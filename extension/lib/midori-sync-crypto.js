/**
 * Midori Sync Crypto Library
 *
 * End-to-end encryption using XChaCha20-Poly1305 (libsodium).
 * Key derivation: Argon2id → Master Key → per-collection sub-keys via BLAKE2B KDF.
 *
 * All encryption/decryption happens client-side. The server never sees plaintext.
 */

const COLLECTION_INDEX = {
    'bookmarks': 1,
    'history': 2,
    'open-tabs': 3,
    'browser-settings': 4,
    'midori-tab': 5,
    'midori-privacy': 6,
    'devices': 7,
};

const KDF_CONTEXT = 'MSPv1key'; // 8 bytes max for libsodium KDF context

class MidoriSyncCrypto {
    constructor() {
        this._sodium = null;
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
     * Generate a random 16-byte salt
     */
    generateSalt() {
        return this.sodium.randombytes_buf(16);
    }

    /**
     * Derive a master key from a passphrase using Argon2id
     * @param {string} passphrase - User's passphrase
     * @param {Uint8Array} salt - 16-byte salt
     * @returns {Uint8Array} 32-byte master key
     */
    async deriveKeys(passphrase, salt) {
        const s = this.sodium;
        return s.crypto_pwhash(
            32, // key length
            passphrase,
            salt,
            3, // ops limit (MODERATE)
            67108864, // mem limit: 64 MB
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
