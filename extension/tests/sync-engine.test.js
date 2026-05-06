/**
 * Sync engine smoke tests — exercise the upload/download contract
 * (BSO format, encryption opt-in, error handling) without actually
 * loading the entire `background.js` file. We re-implement only the
 * minimal `uploadBsos`/`fetchCollection` shape and assert against the
 * server interface that the real background script promises.
 */
import { describe, it, expect, beforeAll, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { jsonResponse } from './helpers/browser.js';

let extFetchJson;

beforeAll(() => {
    const code = fs.readFileSync(
        path.resolve(import.meta.dirname, '../lib/ext-errors.js'),
        'utf-8'
    );
    new Function(code).call(globalThis);
    extFetchJson = globalThis.ExtErrors.extFetchJson;
});

/**
 * Minimal version of the upload contract. Mirrors the real
 * `uploadBsos` in background.js: chunks of N items, JSON body shaped
 * as an array of `{ id, payload }`. We use this to assert chunking
 * and refusal-to-send-plaintext behavior.
 */
function makeUploader({ fetchFn, chunkSize = 100, encryptionKey }) {
    return async function uploadBsos(serverUrl, collection, bsos) {
        if (!encryptionKey) throw new Error('Encryption key not initialized — refusing to upload unencrypted data');
        const seen = new Map();
        for (const b of bsos) seen.set(b.id, b);
        const deduped = Array.from(seen.values());
        for (let i = 0; i < deduped.length; i += chunkSize) {
            const chunk = deduped.slice(i, i + chunkSize);
            await extFetchJson(fetchFn, `${serverUrl}/api/ext/storage/${collection}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Authorization: 'Bearer t' },
                body: JSON.stringify(chunk),
            });
        }
    };
}

describe('sync engine: upload contract', () => {
    it('refuses to upload when encryption key is missing', async () => {
        const fetchFn = vi.fn();
        const upload = makeUploader({ fetchFn, encryptionKey: null });
        await expect(upload('http://server', 'bookmarks', [{ id: 'a', payload: 'x' }]))
            .rejects.toThrow(/Encryption key not initialized/);
        expect(fetchFn).not.toHaveBeenCalled();
    });

    it('chunks uploads at the configured chunk size', async () => {
        const calls = [];
        const fetchFn = vi.fn().mockImplementation(async (url, opts) => {
            calls.push(JSON.parse(opts.body));
            return jsonResponse({ success: true });
        });
        const upload = makeUploader({ fetchFn, chunkSize: 5, encryptionKey: 'key' });
        const bsos = Array.from({ length: 12 }, (_, i) => ({ id: `id-${i}`, payload: 'enc' }));
        await upload('http://server', 'bookmarks', bsos);
        expect(calls.length).toBe(3);
        expect(calls[0]).toHaveLength(5);
        expect(calls[1]).toHaveLength(5);
        expect(calls[2]).toHaveLength(2);
    });

    it('deduplicates BSOs by id before chunking', async () => {
        const calls = [];
        const fetchFn = vi.fn().mockImplementation(async (_url, opts) => {
            calls.push(JSON.parse(opts.body));
            return jsonResponse({ success: true });
        });
        const upload = makeUploader({ fetchFn, chunkSize: 100, encryptionKey: 'key' });
        await upload('http://server', 'bookmarks', [
            { id: 'a', payload: 'p1' },
            { id: 'a', payload: 'p2' },
            { id: 'b', payload: 'p3' },
        ]);
        expect(calls).toHaveLength(1);
        expect(calls[0]).toHaveLength(2);
        const ids = calls[0].map((b) => b.id).sort();
        expect(ids).toEqual(['a', 'b']);
    });

    it('translates 401 Unauthorized into a token_expired error', async () => {
        const fetchFn = async () => jsonResponse({ message: 'Unauthorized' }, { status: 401 });
        const upload = makeUploader({ fetchFn, encryptionKey: 'key' });
        await expect(upload('http://server', 'bookmarks', [{ id: 'a', payload: 'x' }]))
            .rejects.toMatchObject({ code: 'token_expired' });
    });

    it('translates 429 into a rate_limited error', async () => {
        const fetchFn = async () => jsonResponse({ message: 'slow down' }, { status: 429 });
        const upload = makeUploader({ fetchFn, encryptionKey: 'key' });
        await expect(upload('http://server', 'bookmarks', [{ id: 'a', payload: 'x' }]))
            .rejects.toMatchObject({ code: 'rate_limited' });
    });

    it('translates 403 quota into quota_exceeded', async () => {
        const fetchFn = async () => jsonResponse({ message: 'Quota exceeded' }, { status: 403 });
        const upload = makeUploader({ fetchFn, encryptionKey: 'key' });
        await expect(upload('http://server', 'bookmarks', [{ id: 'a', payload: 'x' }]))
            .rejects.toMatchObject({ code: 'quota_exceeded' });
    });
});

describe('sync engine: fetch contract', () => {
    /**
     * Mirrors `fetchCollection` minus the decryption step. Returns the
     * raw BSO array so callers can plug their own decryption.
     */
    async function fetchCollection({ fetchFn, serverUrl, collection, sinceCursor }) {
        let url = `${serverUrl}/api/ext/storage/${collection}`;
        if (sinceCursor) url += `?newer=${sinceCursor}`;
        try {
            return await extFetchJson(fetchFn, url, {
                headers: { Authorization: 'Bearer t' },
            });
        } catch (e) {
            if (e.code === 'not_found') return [];
            throw e;
        }
    }

    it('appends ?newer=<cursor> when an incremental cursor is provided', async () => {
        const seen = [];
        const fetchFn = async (url) => {
            seen.push(url);
            return jsonResponse([]);
        };
        await fetchCollection({ fetchFn, serverUrl: 'http://s', collection: 'bookmarks', sinceCursor: 1234.5 });
        expect(seen[0]).toBe('http://s/api/ext/storage/bookmarks?newer=1234.5');
    });

    it('returns [] for missing collections (404)', async () => {
        const fetchFn = async () => jsonResponse(null, { status: 404 });
        const result = await fetchCollection({ fetchFn, serverUrl: 'http://s', collection: 'midori-tab' });
        expect(result).toEqual([]);
    });

    it('propagates token_expired errors instead of swallowing them', async () => {
        const fetchFn = async () => jsonResponse({ message: 'Unauthorized' }, { status: 401 });
        await expect(fetchCollection({ fetchFn, serverUrl: 'http://s', collection: 'bookmarks' }))
            .rejects.toMatchObject({ code: 'token_expired' });
    });
});
