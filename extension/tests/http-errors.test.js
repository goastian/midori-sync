import { describe, it, expect, beforeAll } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { jsonResponse } from './helpers/browser.js';

let classify;
let extFetchJson;

beforeAll(() => {
    const code = fs.readFileSync(
        path.resolve(import.meta.dirname, '../lib/ext-errors.js'),
        'utf-8'
    );
    new Function(code).call(globalThis);
    classify = globalThis.ExtErrors.classify;
    extFetchJson = globalThis.ExtErrors.extFetchJson;
});

describe('classify(status, body)', () => {
    it('maps 401 to token_expired', () => {
        expect(classify(401, null).code).toBe('token_expired');
    });

    it('maps 403 with "quota" message to quota_exceeded', () => {
        expect(classify(403, { message: 'Quota exceeded for user' }).code).toBe('quota_exceeded');
    });

    it('maps 403 with "collection disabled" message to collection_disabled', () => {
        expect(classify(403, { message: 'Collection disabled' }).code).toBe('collection_disabled');
    });

    it('falls back to forbidden for generic 403', () => {
        expect(classify(403, { message: 'Forbidden' }).code).toBe('forbidden');
    });

    it('maps 404 to not_found', () => {
        expect(classify(404, null).code).toBe('not_found');
    });

    it('maps 412 to precondition_failed', () => {
        expect(classify(412, null).code).toBe('precondition_failed');
    });

    it('maps 429 to rate_limited', () => {
        expect(classify(429, null).code).toBe('rate_limited');
    });

    it('maps 5xx to server_error', () => {
        expect(classify(500, null).code).toBe('server_error');
        expect(classify(502, null).code).toBe('server_error');
        expect(classify(503, null).code).toBe('server_error');
    });

    it('uses synthetic status 0 for network failures', () => {
        expect(classify(0, null).code).toBe('network');
    });
});

describe('extFetchJson(fetchFn, ...)', () => {
    it('parses JSON for 2xx responses', async () => {
        const fetchFn = async () => jsonResponse({ ok: true, devices: [] });
        const result = await extFetchJson(fetchFn, '/api/ext/devices');
        expect(result.devices).toEqual([]);
    });

    it('returns null for 204 No Content', async () => {
        const fetchFn = async () => ({ ok: true, status: 204, async json() { throw new Error('no body'); } });
        const result = await extFetchJson(fetchFn, '/api/ext/data', { method: 'DELETE' });
        expect(result).toBeNull();
    });

    it('throws with .code = network when fetch itself rejects', async () => {
        const fetchFn = async () => { throw new TypeError('Failed to fetch'); };
        await expect(extFetchJson(fetchFn, '/x')).rejects.toMatchObject({ code: 'network' });
    });

    it('throws with .code = token_expired on 401', async () => {
        const fetchFn = async () => jsonResponse({ message: 'Unauthorized' }, { status: 401 });
        await expect(extFetchJson(fetchFn, '/x')).rejects.toMatchObject({
            code: 'token_expired',
            status: 401,
        });
    });

    it('throws with .code = quota_exceeded on 403 quota body', async () => {
        const fetchFn = async () => jsonResponse({ message: 'Storage quota exceeded' }, { status: 403 });
        await expect(extFetchJson(fetchFn, '/x')).rejects.toMatchObject({
            code: 'quota_exceeded',
        });
    });

    it('throws with .code = rate_limited on 429', async () => {
        const fetchFn = async () => jsonResponse({ message: 'slow down' }, { status: 429 });
        await expect(extFetchJson(fetchFn, '/x')).rejects.toMatchObject({
            code: 'rate_limited',
            status: 429,
        });
    });

    it('throws with .code = server_error on 5xx', async () => {
        const fetchFn = async () => jsonResponse(null, { status: 500 });
        await expect(extFetchJson(fetchFn, '/x')).rejects.toMatchObject({
            code: 'server_error',
            status: 500,
        });
    });

    it('respects expectNoBody for endpoints that return text/empty', async () => {
        const fetchFn = async () => ({ ok: true, status: 200, async json() { throw new Error('boom'); } });
        const result = await extFetchJson(fetchFn, '/x', { expectNoBody: true });
        expect(result).toBeNull();
    });
});
