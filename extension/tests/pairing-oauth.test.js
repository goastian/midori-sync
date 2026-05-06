/**
 * Pairing & OAuth flow tests.
 *
 * These tests exercise the *contract* the extension promises against
 * the `/api/ext/pair*` and `/api/ext/auth/*` endpoints. They simulate
 * the network with a deterministic fetch mock and assert that the
 * extension would issue the right requests and surface the right
 * error codes.
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

describe('pairing flow', () => {
    it('generates a pairing token via POST /api/ext/pair', async () => {
        const fetchFn = vi.fn().mockResolvedValue(jsonResponse({
            pairing_token: 'abc123',
            expires_in: 300,
        }));
        const result = await extFetchJson(fetchFn, 'http://server/api/ext/pair', {
            method: 'POST',
            headers: { Authorization: 'Bearer t' },
        });
        expect(result.pairing_token).toBe('abc123');
        expect(fetchFn).toHaveBeenCalledTimes(1);
        const [, opts] = fetchFn.mock.calls[0];
        expect(opts.method).toBe('POST');
        expect(opts.headers.Authorization).toBe('Bearer t');
    });

    it('redeems a pairing token unauthenticated and gets back a sync token', async () => {
        const fetchFn = vi.fn().mockResolvedValue(jsonResponse({
            token: 'sync-token-x',
            user: { id: 1, email: 'a@b' },
            device: { id: 'dev-1', name: 'Browser' },
        }));
        const body = { pairing_token: 'abc123', device_name: 'Test Device', device_type: 'desktop' };
        const result = await extFetchJson(fetchFn, 'http://server/api/ext/pair/redeem', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        expect(result.token).toBe('sync-token-x');
        expect(result.user.id).toBe(1);
    });

    it('treats an invalid/expired pairing token as a not_found error', async () => {
        const fetchFn = async () => jsonResponse({ error: 'Invalid or expired pairing token' }, { status: 404 });
        await expect(
            extFetchJson(fetchFn, 'http://server/api/ext/pair/redeem', { method: 'POST' })
        ).rejects.toMatchObject({ code: 'not_found', status: 404 });
    });
});

describe('OAuth (extension) flow', () => {
    it('starts auth and receives an auth_url + state', async () => {
        const fetchFn = vi.fn().mockResolvedValue(jsonResponse({
            auth_url: 'https://accounts.astian.org/login?...',
            state: 'state-abc',
        }));
        const result = await extFetchJson(fetchFn, 'http://server/api/ext/auth/start?device_name=Test');
        expect(result.auth_url).toMatch(/accounts.astian.org/);
        expect(result.state).toBe('state-abc');
    });

    it('polls /auth/poll and returns pending until login completes', async () => {
        const responses = [
            jsonResponse({ status: 'pending' }),
            jsonResponse({ status: 'pending' }),
            jsonResponse({ status: 'complete', token: 't', user: { id: 1 }, device: { id: 'd' } }),
        ];
        const fetchFn = vi.fn().mockImplementation(async () => responses.shift());

        let last;
        for (let i = 0; i < 5; i++) {
            last = await extFetchJson(fetchFn, 'http://server/api/ext/auth/poll?state=s');
            if (last.status === 'complete') break;
        }
        expect(last.status).toBe('complete');
        expect(last.token).toBe('t');
        expect(fetchFn).toHaveBeenCalledTimes(3);
    });

    it('reports server_error on 500 during poll', async () => {
        const fetchFn = async () => jsonResponse(null, { status: 500 });
        await expect(extFetchJson(fetchFn, 'http://server/api/ext/auth/poll?state=s'))
            .rejects.toMatchObject({ code: 'server_error' });
    });
});
