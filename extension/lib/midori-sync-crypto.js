/**
 * Midori Sync Crypto Library
 *
 * End-to-end encryption using XChaCha20-Poly1305 (libsodium).
 * Key derivation: Argon2id → Master Key → per-collection sub-keys via BLAKE2B KDF.
 *
 * All encryption/decryption happens client-side. The server never sees plaintext.
 */

/**
 * Stable per-collection KDF index. NEVER reorder or repurpose existing
 * indices: re-using an index would silently re-derive the same sub-key
 * for a different collection, producing data that decrypts cleanly but
 * carries the wrong type. New collections MUST claim a fresh index.
 *
 * Canonical name -> index. Legacy aliases (e.g. `open-tabs`) map to the
 * same index as their canonical counterpart and exist only for backward
 * compatibility with installs predating the alias.
 */
const COLLECTION_INDEX = {
    'bookmarks': 1,
    'history': 2,
    'tabs': 3,             // canonical name for currently-open tabs
    'open-tabs': 3,        // legacy alias of `tabs` — same sub-key
    'browser-settings': 4,
    'midori-tab': 5,
    'midori-privacy': 6,
    'devices': 7,
    'passwords': 8,        // encrypted password vault (KDF index reserved)
};

const KDF_CONTEXT = 'MSPv1key'; // 8 bytes max for libsodium KDF context

class MidoriSyncCrypto {
    constructor(options = {}) {
        this._sodium = null;
        // Path to the Argon2id worker, resolved relative to the
        // extension root. Pass `null` to force the synchronous fallback
        // (useful in tests, Node, or environments without Worker).
        this._workerUrl = options.workerUrl !== undefined
            ? options.workerUrl
            : (typeof browser !== 'undefined' && browser.runtime && browser.runtime.getURL
                ? browser.runtime.getURL('lib/argon2-worker.js')
                : null);
        this._worker = null;
        this._workerSeq = 0;
        this._workerPending = new Map();
        this._workerBroken = false;
    }

    async init() {
        if (this._sodium) return;
        if (typeof sodium !== 'undefined' && sodium.ready) {
            await sodium.ready;
            this._sodium = sodium;
        } else {
            throw new Error('libsodium not loaded');
        }
    }

    get sodium() {
        if (!this._sodium) throw new Error('Call init() first');
        return this._sodium;
    }

    /**
     * Decide whether Argon2id should run in a Web Worker. We require:
     *   - Worker constructor available
     *   - a worker URL configured (extensions / browser context)
     *   - the worker has not previously errored out
     */
    _canUseWorker() {
        return !this._workerBroken
            && typeof Worker !== 'undefined'
            && typeof this._workerUrl === 'string'
            && this._workerUrl.length > 0;
    }

    _ensureWorker() {
        if (this._worker) return this._worker;
        const worker = new Worker(this._workerUrl);
        worker.addEventListener('message', (event) => {
            const { id, ok, key, error } = event.data || {};
            const pending = this._workerPending.get(id);
            if (!pending) return;
            this._workerPending.delete(id);
            if (ok) pending.resolve(key);
            else pending.reject(new Error(error || 'argon2 worker failed'));
        });
        worker.addEventListener('error', (event) => {
            // Mark the worker as broken so subsequent calls fall back.
            // Reject any in-flight requests so callers don't hang.
            this._workerBroken = true;
            for (const { reject } of this._workerPending.values()) {
                reject(new Error(`argon2 worker error: ${event.message || 'unknown'}`));
            }
            this._workerPending.clear();
            try { worker.terminate(); } catch (_) { /* ignore */ }
            this._worker = null;
        });
        this._worker = worker;
        return worker;
    }

    _argon2InWorker(passphrase, salt, opslimit, memlimit) {
        return new Promise((resolve, reject) => {
            const worker = this._ensureWorker();
            const id = ++this._workerSeq;
            this._workerPending.set(id, { resolve, reject });
            worker.postMessage({ id, op: 'derive', passphrase, salt, opslimit, memlimit });
        });
    }

    /**
     * Generate a random 16-byte salt
     */
    generateSalt() {
        return this.sodium.randombytes_buf(16);
    }

    /**
     * Derive a master key from a passphrase using Argon2id.
     *
     * Runs inside a dedicated Web Worker when available (extension
     * runtime) so the main thread is not blocked by the 64 MB / ops=3
     * memory-hard derivation. Falls back to synchronous in-thread
     * derivation in environments without Worker support (Node tests,
     * sandboxes), or if the worker fails to start.
     *
     * @param {string|Uint8Array} passphrase - User's passphrase
     * @param {Uint8Array} salt - 16-byte salt
     * @returns {Promise<Uint8Array>} 32-byte master key
     */
    async deriveKeys(passphrase, salt) {
        const s = this.sodium;
        const ops = 3;
        const mem = 67108864; // 64 MB
        if (this._canUseWorker()) {
            try {
                return await this._argon2InWorker(passphrase, salt, ops, mem);
            } catch (err) {
                // One-shot fallback: degrade to in-thread derivation
                // rather than break login because the worker died.
                this._workerBroken = true;
                console.warn('[MidoriSyncCrypto] Argon2id worker failed, falling back to main thread:', err);
            }
        }
        return s.crypto_pwhash(
            32,
            passphrase,
            salt,
            ops,
            mem,
            s.crypto_pwhash_ALG_ARGON2ID13
        );
    }

    /**
     * Derive a per-collection sub-key from the master key
     * @param {Uint8Array} masterKey - 32-byte master key
     * @param {number} collectionIndex - Collection index (1-7)
     * @returns {Uint8Array} 32-byte sub-key
     */
    deriveCollectionKey(masterKey, collectionIndex) {
        const s = this.sodium;
        return s.crypto_kdf_derive_from_key(
            32, // subkey length
            collectionIndex,
            KDF_CONTEXT,
            masterKey
        );
    }

    /**
     * Get the sub-key for a named collection
     * @param {Uint8Array} masterKey - 32-byte master key
     * @param {string} collectionName - Collection name
     * @returns {Uint8Array} 32-byte sub-key
     */
    getCollectionKey(masterKey, collectionName) {
        const index = COLLECTION_INDEX[collectionName];
        if (!index) throw new Error(`Unknown collection: ${collectionName}`);
        return this.deriveCollectionKey(masterKey, index);
    }

    /**
     * Encrypt plaintext with XChaCha20-Poly1305
     * @param {string} plaintext - JSON string to encrypt
     * @param {Uint8Array} key - 32-byte encryption key
     * @returns {string} Base64-encoded (nonce || ciphertext || tag)
     */
    encrypt(plaintext, key) {
        const s = this.sodium;
        const nonce = s.randombytes_buf(s.crypto_aead_xchacha20poly1305_ietf_NPUBBYTES); // 24 bytes
        const ciphertext = s.crypto_aead_xchacha20poly1305_ietf_encrypt(
            s.from_string(plaintext),
            null, // additional data
            null, // nsec (unused)
            nonce,
            key
        );

        // Concatenate: nonce (24) || ciphertext+tag
        const combined = new Uint8Array(nonce.length + ciphertext.length);
        combined.set(nonce);
        combined.set(ciphertext, nonce.length);

        return s.to_base64(combined, s.base64_variants.ORIGINAL);
    }

    /**
     * Decrypt a payload encrypted with encrypt()
     * @param {string} encryptedPayload - Base64-encoded (nonce || ciphertext || tag)
     * @param {Uint8Array} key - 32-byte encryption key
     * @returns {string} Decrypted plaintext
     */
    decrypt(encryptedPayload, key) {
        const s = this.sodium;
        const combined = s.from_base64(encryptedPayload, s.base64_variants.ORIGINAL);

        const nonceLen = s.crypto_aead_xchacha20poly1305_ietf_NPUBBYTES; // 24
        const nonce = combined.slice(0, nonceLen);
        const ciphertext = combined.slice(nonceLen);

        const plaintext = s.crypto_aead_xchacha20poly1305_ietf_decrypt(
            null, // nsec (unused)
            ciphertext,
            null, // additional data
            nonce,
            key
        );

        return s.to_string(plaintext);
    }

    /**
     * Encrypt the master key for storage on the server.
     * Uses a separate key derived from the passphrase.
     * @param {Uint8Array} masterKey - 32-byte master key to protect
     * @param {string} passphrase - User's passphrase
     * @param {Uint8Array} salt - 16-byte salt
     * @returns {string} JSON string with encrypted bundle
     */
    async encryptKeyBundle(masterKey, passphrase, salt) {
        const s = this.sodium;
        // Derive a wrapping key (different from master key by using subkey index 0)
        const wrapKey = await this.deriveKeys(passphrase, salt);
        const wrappingKey = s.crypto_kdf_derive_from_key(32, 0, KDF_CONTEXT, wrapKey);

        const encrypted = this.encrypt(
            s.to_base64(masterKey, s.base64_variants.ORIGINAL),
            wrappingKey
        );

        return JSON.stringify({
            v: 1,
            salt: s.to_base64(salt, s.base64_variants.ORIGINAL),
            bundle: encrypted,
        });
    }

    /**
     * Decrypt the master key from a stored bundle
     * @param {string} encryptedBundle - JSON string from encryptKeyBundle
     * @param {string} passphrase - User's passphrase
     * @returns {{ masterKey: Uint8Array, salt: Uint8Array }}
     */
    async decryptKeyBundle(encryptedBundle, passphrase) {
        const s = this.sodium;
        const data = JSON.parse(encryptedBundle);

        if (data.v !== 1) throw new Error(`Unsupported bundle version: ${data.v}`);

        const salt = s.from_base64(data.salt, s.base64_variants.ORIGINAL);
        const wrapKey = await this.deriveKeys(passphrase, salt);
        const wrappingKey = s.crypto_kdf_derive_from_key(32, 0, KDF_CONTEXT, wrapKey);

        const masterKeyB64 = this.decrypt(data.bundle, wrappingKey);
        const masterKey = s.from_base64(masterKeyB64, s.base64_variants.ORIGINAL);

        return { masterKey, salt };
    }
}

// Export for use in extension background scripts
if (typeof globalThis !== 'undefined') {
    globalThis.MidoriSyncCrypto = MidoriSyncCrypto;
    globalThis.COLLECTION_INDEX = COLLECTION_INDEX;
}
