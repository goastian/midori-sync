(function initMidoriSyncCrypto(globalScope) {
    const CRYPTO_ALGO = { name: 'AES-GCM', length: 256 };
    const IV_LENGTH = 12;

    function bytesToBase64(bytes) {
        let binary = '';
        const chunkSize = 0x8000;
        for (let index = 0; index < bytes.length; index += chunkSize) {
            binary += String.fromCharCode(...bytes.subarray(index, index + chunkSize));
        }
        return globalScope.btoa(binary);
    }

    function base64ToBytes(base64) {
        const binary = globalScope.atob(base64);
        return Uint8Array.from(binary, char => char.charCodeAt(0));
    }

    function generateEncryptionKey() {
        return globalScope.crypto.subtle.generateKey(CRYPTO_ALGO, true, ['encrypt', 'decrypt']);
    }

    async function exportKeyBase64(key) {
        const raw = await globalScope.crypto.subtle.exportKey('raw', key);
        return bytesToBase64(new Uint8Array(raw));
    }

    function importKeyBase64(base64) {
        const raw = base64ToBytes(base64);
        return globalScope.crypto.subtle.importKey('raw', raw, CRYPTO_ALGO, true, ['encrypt', 'decrypt']);
    }

    async function encryptPayload(plaintext, key) {
        const iv = globalScope.crypto.getRandomValues(new Uint8Array(IV_LENGTH));
        const encoded = new globalScope.TextEncoder().encode(plaintext);
        const ciphertext = await globalScope.crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, encoded);
        const combined = new Uint8Array(IV_LENGTH + ciphertext.byteLength);
        combined.set(iv);
        combined.set(new Uint8Array(ciphertext), IV_LENGTH);
        return bytesToBase64(combined);
    }

    async function decryptPayload(base64, key) {
        const combined = base64ToBytes(base64);
        const iv = combined.slice(0, IV_LENGTH);
        const ciphertext = combined.slice(IV_LENGTH);
        const plaintext = await globalScope.crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext);
        return new globalScope.TextDecoder().decode(plaintext);
    }

    function isEncryptedPayload(payload) {
        if (!payload || typeof payload !== 'string') return false;
        if (payload.startsWith('{') || payload.startsWith('[')) return false;
        try {
            return globalScope.atob(payload).length > IV_LENGTH + 16;
        } catch {
            return false;
        }
    }

    async function decryptBsoPayload(bso, key) {
        if (!key || !isEncryptedPayload(bso.payload)) return bso;
        try {
            const plaintext = await decryptPayload(bso.payload, key);
            return { ...bso, payload: plaintext };
        } catch {
            return bso;
        }
    }

    globalScope.MidoriSyncCrypto = {
        CRYPTO_ALGO,
        IV_LENGTH,
        generateEncryptionKey,
        exportKeyBase64,
        importKeyBase64,
        encryptPayload,
        decryptPayload,
        isEncryptedPayload,
        decryptBsoPayload,
    };
})(globalThis);