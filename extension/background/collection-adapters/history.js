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
        // Simple hash for URL-based record ID
        let hash = 0;
        for (let i = 0; i < url.length; i++) {
            const chr = url.charCodeAt(i);
            hash = ((hash << 5) - hash) + chr;
            hash |= 0;
        }
        return 'h_' + Math.abs(hash).toString(36);
    }
}

if (typeof globalThis !== 'undefined') {
    globalThis.HistoryAdapter = HistoryAdapter;
}
