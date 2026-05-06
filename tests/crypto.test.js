import { describe, it, expect, beforeAll } from 'vitest';
import _sodium from 'libsodium-wrappers-sumo';

// We need to simulate the global sodium for the crypto library
// Since the library uses globalThis.MidoriSyncCrypto, we load it via dynamic import after setting up sodium

let MidoriSyncCrypto;
let COLLECTION_INDEX;
let sodium;

beforeAll(async () => {
    await _sodium.ready;
    sodium = _sodium;

    // Simulate the global sodium that the lib expects
    globalThis.sodium = sodium;

    // Load the crypto module by evaluating its code
    const fs = await import('fs');
    const path = await import('path');
    const code = fs.readFileSync(
        path.resolve(import.meta.dirname, '../extension/lib/midori-sync-crypto.js'),
        'utf-8'
    );
    // Execute in current scope
    const fn = new Function(code);
    fn();

    MidoriSyncCrypto = globalThis.MidoriSyncCrypto;
    COLLECTION_INDEX = globalThis.COLLECTION_INDEX;
});

describe('MidoriSyncCrypto', () => {
    it('should initialize successfully', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();
        expect(crypto.sodium).toBeDefined();
    });

    it('should generate a 16-byte salt', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();

        const salt = crypto.generateSalt();
        expect(salt).toBeInstanceOf(Uint8Array);
        expect(salt.length).toBe(16);
    });

    it('should derive a 32-byte master key from passphrase via Argon2id', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();

        const salt = crypto.generateSalt();
        const masterKey = await crypto.deriveKeys('test-passphrase-12345', salt);

        expect(masterKey).toBeInstanceOf(Uint8Array);
        expect(masterKey.length).toBe(32);
    });

    it('should derive deterministic keys from same passphrase and salt', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();

        const salt = crypto.generateSalt();
        const key1 = await crypto.deriveKeys('my-passphrase', salt);
        const key2 = await crypto.deriveKeys('my-passphrase', salt);

        expect(sodium.to_hex(key1)).toBe(sodium.to_hex(key2));
    });

    it('should derive different keys from different passphrases', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();

        const salt = crypto.generateSalt();
        const key1 = await crypto.deriveKeys('passphrase-one', salt);
        const key2 = await crypto.deriveKeys('passphrase-two', salt);

        expect(sodium.to_hex(key1)).not.toBe(sodium.to_hex(key2));
    });

    it('should derive unique sub-keys per collection', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();

        const salt = crypto.generateSalt();
        const masterKey = await crypto.deriveKeys('test-passphrase', salt);

        const bookmarksKey = crypto.getCollectionKey(masterKey, 'bookmarks');
        const historyKey = crypto.getCollectionKey(masterKey, 'history');

        expect(bookmarksKey.length).toBe(32);
        expect(historyKey.length).toBe(32);
        expect(sodium.to_hex(bookmarksKey)).not.toBe(sodium.to_hex(historyKey));
    });

    it('should throw for unknown collection names', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();

        const salt = crypto.generateSalt();
        const masterKey = await crypto.deriveKeys('passphrase', salt);

        expect(() => crypto.getCollectionKey(masterKey, 'nonexistent')).toThrow('Unknown collection');
    });

    it('should encrypt and decrypt data with XChaCha20-Poly1305', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();

        const salt = crypto.generateSalt();
        const masterKey = await crypto.deriveKeys('test-passphrase', salt);
        const key = crypto.getCollectionKey(masterKey, 'bookmarks');

        const plaintext = JSON.stringify({ title: 'My Bookmark', url: 'https://example.com' });

        const encrypted = crypto.encrypt(plaintext, key);
        expect(typeof encrypted).toBe('string');
        expect(encrypted).not.toBe(plaintext);

        const decrypted = crypto.decrypt(encrypted, key);
        expect(decrypted).toBe(plaintext);
    });

    it('should produce different ciphertexts for same plaintext (random nonce)', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();

        const salt = crypto.generateSalt();
        const masterKey = await crypto.deriveKeys('passphrase', salt);
        const key = crypto.getCollectionKey(masterKey, 'bookmarks');

        const plaintext = 'same-data-twice';
        const enc1 = crypto.encrypt(plaintext, key);
        const enc2 = crypto.encrypt(plaintext, key);

        expect(enc1).not.toBe(enc2); // Different random nonces
    });

    it('should fail to decrypt with wrong key', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();

        const salt = crypto.generateSalt();
        const masterKey = await crypto.deriveKeys('passphrase', salt);
        const rightKey = crypto.getCollectionKey(masterKey, 'bookmarks');
        const wrongKey = crypto.getCollectionKey(masterKey, 'history');

        const encrypted = crypto.encrypt('secret data', rightKey);

        expect(() => crypto.decrypt(encrypted, wrongKey)).toThrow();
    });

    it('should encrypt and decrypt a key bundle', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();

        const salt = crypto.generateSalt();
        const masterKey = await crypto.deriveKeys('my-secure-passphrase', salt);

        const bundle = await crypto.encryptKeyBundle(masterKey, 'my-secure-passphrase', salt);
        expect(typeof bundle).toBe('string');

        const parsed = JSON.parse(bundle);
        expect(parsed.v).toBe(1);
        expect(parsed.salt).toBeDefined();
        expect(parsed.bundle).toBeDefined();

        const { masterKey: recovered } = await crypto.decryptKeyBundle(bundle, 'my-secure-passphrase');
        expect(sodium.to_hex(recovered)).toBe(sodium.to_hex(masterKey));
    });

    it('should fail to decrypt key bundle with wrong passphrase', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();

        const salt = crypto.generateSalt();
        const masterKey = await crypto.deriveKeys('correct-passphrase', salt);

        const bundle = await crypto.encryptKeyBundle(masterKey, 'correct-passphrase', salt);

        await expect(
            crypto.decryptKeyBundle(bundle, 'wrong-passphrase')
        ).rejects.toThrow();
    });

    it('should have all expected collection indexes', () => {
        const expected = [
            'bookmarks', 'history', 'tabs', 'open-tabs',
            'browser-settings', 'midori-tab', 'midori-privacy',
            'devices', 'passwords',
        ];
        expected.forEach(name => {
            expect(COLLECTION_INDEX[name]).toBeDefined();
            expect(typeof COLLECTION_INDEX[name]).toBe('number');
        });
    });

    it('should map legacy `open-tabs` to the same index as `tabs`', () => {
        expect(COLLECTION_INDEX['open-tabs']).toBe(COLLECTION_INDEX['tabs']);
    });

    it('should derive the same sub-key for `tabs` and `open-tabs` (alias)', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();
        const masterKey = await crypto.deriveKeys('alias-test', crypto.generateSalt());
        const a = crypto.getCollectionKey(masterKey, 'tabs');
        const b = crypto.getCollectionKey(masterKey, 'open-tabs');
        expect(sodium.to_hex(a)).toBe(sodium.to_hex(b));
    });

    it('should derive a usable sub-key for `passwords`', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();
        const masterKey = await crypto.deriveKeys('pwds', crypto.generateSalt());
        const k = crypto.getCollectionKey(masterKey, 'passwords');
        expect(k.length).toBe(32);
        const ct = crypto.encrypt('{"site":"x"}', k);
        expect(crypto.decrypt(ct, k)).toBe('{"site":"x"}');
    });
});

// ─── Property-based / fuzz roundtrip tests ─────────────────────────────
// These tests do not pull in a property-based testing framework to keep
// the dependency surface small. Instead they generate randomized inputs
// and assert the documented invariants of the encryption layer.

describe('MidoriSyncCrypto — roundtrip properties', () => {
    const NONCE_BYTES = 24;
    const TAG_BYTES = 16;
    const ITERATIONS = 60;

    function randomBytes(min, max) {
        const len = min + Math.floor(Math.random() * (max - min + 1));
        return sodium.randombytes_buf(len);
    }

    function randomString(min, max) {
        // Random binary content encoded as a non-empty string. We use
        // base64 so the test exercises arbitrary payload sizes without
        // worrying about UTF-8 surrogate pairs.
        const buf = randomBytes(min, max);
        return sodium.to_base64(buf, sodium.base64_variants.ORIGINAL);
    }

    it('encrypt/decrypt is the identity for arbitrary plaintexts', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();
        const masterKey = await crypto.deriveKeys('roundtrip', crypto.generateSalt());
        const key = crypto.getCollectionKey(masterKey, 'bookmarks');

        for (let i = 0; i < ITERATIONS; i++) {
            const pt = randomString(0, 4096);
            const ct = crypto.encrypt(pt, key);
            expect(crypto.decrypt(ct, key)).toBe(pt);
        }
    });

    it('payload layout invariant: ciphertext length = nonce + plaintext + tag', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();
        const masterKey = await crypto.deriveKeys('layout', crypto.generateSalt());
        const key = crypto.getCollectionKey(masterKey, 'history');

        for (let i = 0; i < ITERATIONS; i++) {
            const pt = randomString(0, 1024);
            const combined = sodium.from_base64(
                crypto.encrypt(pt, key),
                sodium.base64_variants.ORIGINAL
            );
            // libsodium's encrypt returns plaintext_len + tag_len bytes
            // for the AEAD output; we prepend the 24-byte nonce.
            const expected = NONCE_BYTES + sodium.from_string(pt).length + TAG_BYTES;
            expect(combined.length).toBe(expected);
        }
    });

    it('flipping any byte of the ciphertext breaks decryption (AEAD integrity)', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();
        const masterKey = await crypto.deriveKeys('integrity', crypto.generateSalt());
        const key = crypto.getCollectionKey(masterKey, 'history');

        for (let i = 0; i < 20; i++) {
            const pt = randomString(8, 256);
            const ct = sodium.from_base64(crypto.encrypt(pt, key), sodium.base64_variants.ORIGINAL);
            const idx = Math.floor(Math.random() * ct.length);
            ct[idx] ^= 0x01;
            const tampered = sodium.to_base64(ct, sodium.base64_variants.ORIGINAL);
            expect(() => crypto.decrypt(tampered, key)).toThrow();
        }
    });

    it('sub-keys are pairwise distinct across all known collections', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();
        const masterKey = await crypto.deriveKeys('distinct', crypto.generateSalt());

        // Group by KDF index because aliases (`open-tabs` -> `tabs`) MUST
        // produce identical keys; only distinct indices must be distinct.
        const byIndex = new Map();
        for (const [name, idx] of Object.entries(COLLECTION_INDEX)) {
            const k = crypto.getCollectionKey(masterKey, name);
            const hex = sodium.to_hex(k);
            if (byIndex.has(idx)) {
                expect(byIndex.get(idx)).toBe(hex);
            } else {
                byIndex.set(idx, hex);
            }
        }
        const uniqueKeys = new Set(byIndex.values());
        expect(uniqueKeys.size).toBe(byIndex.size);
    });

    it('different master keys produce different collection sub-keys', async () => {
        const crypto = new MidoriSyncCrypto();
        await crypto.init();
        const salt = crypto.generateSalt();
        const m1 = await crypto.deriveKeys('one', salt);
        const m2 = await crypto.deriveKeys('two', salt);
        const k1 = crypto.getCollectionKey(m1, 'bookmarks');
        const k2 = crypto.getCollectionKey(m2, 'bookmarks');
        expect(sodium.to_hex(k1)).not.toBe(sodium.to_hex(k2));
    });
});
