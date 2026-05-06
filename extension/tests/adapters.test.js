import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import _sodium from 'libsodium-wrappers-sumo';
import { createBrowserMock } from './helpers/browser.js';

const ADAPTER_DIR = path.resolve(import.meta.dirname, '../background/collection-adapters');

function loadAdapter(file) {
    const code = fs.readFileSync(path.join(ADAPTER_DIR, file), 'utf-8');
    new Function(code).call(globalThis);
}

beforeAll(async () => {
    await _sodium.ready;
    globalThis.sodium = _sodium;
    loadAdapter('bookmarks.js');
    loadAdapter('history.js');
    loadAdapter('tabs.js');
});

beforeEach(() => {
    delete globalThis.browser;
});

describe('BookmarksAdapter', () => {
    it('flattens the bookmarks tree into individual records', async () => {
        const tree = [
            {
                id: 'folder-1',
                title: 'Folder',
                children: [
                    { id: 'b1', title: 'Example', url: 'https://example.com', parentId: 'folder-1' },
                    { id: 'b2', title: 'Nested', url: 'https://nested.test', parentId: 'folder-1' },
                ],
            },
        ];
        const { browser } = createBrowserMock({ bookmarks: tree });
        globalThis.browser = browser;

        const adapter = new globalThis.BookmarksAdapter();
        const all = await adapter.getAll();
        const urls = all.filter((b) => b.url).map((b) => b.url);
        expect(urls).toContain('https://example.com');
        expect(urls).toContain('https://nested.test');
    });

    it('produces stable sync records derived from the input id', async () => {
        const adapter = new globalThis.BookmarksAdapter();
        const record = await adapter.toSyncRecord({
            id: 'b1',
            parentId: 'folder-1',
            title: 'Hello',
            url: 'https://hello.test',
            index: 0,
            dateAdded: 1700000000000,
        });
        expect(record.id).toBe('b1');
        expect(record.data.url).toBe('https://hello.test');
        expect(record.data.type).toBe('bookmark');
    });
});

describe('HistoryAdapter', () => {
    it('hashes URLs deterministically with BLAKE2b-128 (h_ prefix)', async () => {
        const adapter = new globalThis.HistoryAdapter();
        const r1 = await adapter.toSyncRecord({ url: 'https://a.test', title: 'a', visitCount: 1, lastVisitTime: 1 });
        const r2 = await adapter.toSyncRecord({ url: 'https://a.test', title: 'a', visitCount: 1, lastVisitTime: 1 });
        expect(r1.id).toBe(r2.id);
        expect(r1.id.startsWith('h_')).toBe(true);
        // 16 bytes hex = 32 chars, plus the "h_" prefix.
        expect(r1.id.length).toBe(34);
    });

    it('returns different ids for different URLs', async () => {
        const adapter = new globalThis.HistoryAdapter();
        const r1 = await adapter.toSyncRecord({ url: 'https://one.test', lastVisitTime: 1 });
        const r2 = await adapter.toSyncRecord({ url: 'https://two.test', lastVisitTime: 1 });
        expect(r1.id).not.toBe(r2.id);
    });

    it('queries history starting from the requested timestamp (in ms)', async () => {
        const { browser } = createBrowserMock({
            history: [
                { url: 'https://old.test', lastVisitTime: 1000 },
                { url: 'https://new.test', lastVisitTime: 5_000_000 },
            ],
        });
        globalThis.browser = browser;
        const adapter = new globalThis.HistoryAdapter();
        // Adapter multiplies by 1000 (ms). Pass a seconds-style cursor.
        const result = await adapter.getChangesSince(2000);
        const urls = result.map((h) => h.url);
        expect(urls).toContain('https://new.test');
        expect(urls).not.toContain('https://old.test');
    });
});

describe('OpenTabsAdapter', () => {
    it('filters out about: and moz-extension: URLs', async () => {
        const { browser } = createBrowserMock({
            tabs: [
                { id: 1, url: 'https://real.test', title: 'Real' },
                { id: 2, url: 'about:blank', title: 'Blank' },
                { id: 3, url: 'moz-extension://xyz/page.html', title: 'Internal' },
            ],
        });
        globalThis.browser = browser;
        const adapter = new globalThis.OpenTabsAdapter();
        const all = await adapter.getAll();
        const urls = all.map((t) => t.url);
        expect(urls).toEqual(['https://real.test']);
    });

    it('emits sync records keyed by `tab_<id>`', async () => {
        const adapter = new globalThis.OpenTabsAdapter();
        const record = await adapter.toSyncRecord({
            id: 42,
            url: 'https://real.test',
            title: 'Real',
            favIconUrl: '',
            pinned: false,
            index: 0,
            windowId: 1,
            active: true,
            lastAccessed: 1,
        });
        expect(record.id).toBe('tab_42');
        expect(record.data.url).toBe('https://real.test');
    });
});
