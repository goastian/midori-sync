import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { webcrypto } from 'node:crypto';

const source = await readFile(new URL('../lib/sync-crypto.js', import.meta.url), 'utf8');

function createContext() {
    const context = {
        Uint8Array,
        TextEncoder,
        TextDecoder,
        crypto: webcrypto,
        console,
        globalThis: null,
        atob: value => Buffer.from(value, 'base64').toString('binary'),
        btoa: value => Buffer.from(value, 'binary').toString('base64'),
    };
    context.globalThis = context;
    vm.runInNewContext(source, context, { filename: 'sync-crypto.js' });
    return context.MidoriSyncCrypto;
}

test('encryptPayload/decryptPayload hace roundtrip correcto', async () => {
    const cryptoApi = createContext();
    const key = await cryptoApi.generateEncryptionKey();
    const plaintext = JSON.stringify({
        url: 'https://example.com',
        title: 'Example',
        lastVisitTime: 1775149000000,
    });

    const encrypted = await cryptoApi.encryptPayload(plaintext, key);
    const decrypted = await cryptoApi.decryptPayload(encrypted, key);

    assert.notEqual(encrypted, plaintext);
    assert.equal(decrypted, plaintext);
    assert.equal(cryptoApi.isEncryptedPayload(encrypted), true);
});

test('el mismo plaintext produce ciphertext distinto por IV aleatorio', async () => {
    const cryptoApi = createContext();
    const key = await cryptoApi.generateEncryptionKey();
    const plaintext = JSON.stringify({ id: 'hi-123', url: 'https://astian.org' });

    const encryptedA = await cryptoApi.encryptPayload(plaintext, key);
    const encryptedB = await cryptoApi.encryptPayload(plaintext, key);

    assert.notEqual(encryptedA, encryptedB);
    assert.equal(await cryptoApi.decryptPayload(encryptedA, key), plaintext);
    assert.equal(await cryptoApi.decryptPayload(encryptedB, key), plaintext);
});

test('decryptBsoPayload mantiene payload legacy en texto plano', async () => {
    const cryptoApi = createContext();
    const key = await cryptoApi.generateEncryptionKey();
    const bso = {
        id: 'bk-1',
        payload: JSON.stringify({ url: 'https://legacy.example', title: 'Legacy' }),
    };

    const decrypted = await cryptoApi.decryptBsoPayload(bso, key);

    assert.deepEqual(decrypted, bso);
    assert.equal(cryptoApi.isEncryptedPayload(bso.payload), false);
});

test('decryptPayload falla con clave incorrecta', async () => {
    const cryptoApi = createContext();
    const keyA = await cryptoApi.generateEncryptionKey();
    const keyB = await cryptoApi.generateEncryptionKey();
    const encrypted = await cryptoApi.encryptPayload('secret', keyA);

    await assert.rejects(() => cryptoApi.decryptPayload(encrypted, keyB));
});

test('export/import de clave preserva capacidad de descifrado', async () => {
    const cryptoApi = createContext();
    const originalKey = await cryptoApi.generateEncryptionKey();
    const exported = await cryptoApi.exportKeyBase64(originalKey);
    const importedKey = await cryptoApi.importKeyBase64(exported);
    const encrypted = await cryptoApi.encryptPayload('persisted-data', originalKey);

    assert.equal(await cryptoApi.decryptPayload(encrypted, importedKey), 'persisted-data');
});