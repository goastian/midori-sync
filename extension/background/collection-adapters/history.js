/**
 * History Collection Adapter
 * Uses browser.history API to read/write browsing history.
 */
class HistoryAdapter {
    constructor() {
        this.maxResults = 10000;
    }

    async getAll() {
        const items = await browser.history.search({
            text: '',
            startTime: 0,
            maxResults: this.maxResults,
        });
        return items;
    }

    async getChangesSince(timestamp) {
        const items = await browser.history.search({
            text: '',
            startTime: timestamp * 1000, // ms
            maxResults: this.maxResults,
        });
        return items;
    }

    async applyRemote(records) {
        for (const record of records) {
            if (record.deleted) continue; // Can't easily delete specific history items by sync ID

            const data = record.data;
            if (!data || !data.url) continue;

            try {
                await browser.history.addUrl({
                    url: data.url,
                    title: data.title,
                    visitTime: data.lastVisitTime,
                });
            } catch (err) {
                console.warn('[HistoryAdapter] Failed to apply:', data.url, err);
            }
        }
    }

    async toSyncRecord(item) {
        return {
            id: this._hashUrl(item.url),
            data: {
                url: item.url,
                title: item.title,
                visitCount: item.visitCount,
                lastVisitTime: item.lastVisitTime,
            },
        };
    }

    async fromSyncRecord(record) {
        return record.data;
    }

    _hashUrl(url) {
        // BLAKE2b-128 via libsodium. Sync API; requires sodium.ready, which is
        // awaited during crypto initialization before any sync operation runs.
        if (typeof sodium === 'undefined' || !sodium.crypto_generichash) {
            throw new Error('libsodium not ready: cannot hash URL');
        }
        const digest = sodium.crypto_generichash(16, sodium.from_string(url));
        return 'h_' + sodium.to_hex(digest);
    }
}

if (typeof globalThis !== 'undefined') {
    globalThis.HistoryAdapter = HistoryAdapter;
}
