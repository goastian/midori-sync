/**
 * Midori Tab Collection Adapter
 * Communicates with the Midori Tab extension via runtime messaging
 * to sync widget configurations, themes, and shortcuts.
 */
class MidoriTabAdapter {
    constructor() {
        this.extensionId = 'midori-tab@nickel-org.github.io'; // Update with actual ID
    }

    async getAll() {
        try {
            const response = await browser.runtime.sendMessage(this.extensionId, {
                type: 'MIDORI_SYNC_GET_CONFIG',
            });
            return response ? [response] : [];
        } catch {
            console.warn('[MidoriTabAdapter] Midori Tab extension not available');
            return [];
        }
    }

    async getChangesSince() {
        return this.getAll();
    }

    async applyRemote(records) {
        for (const record of records) {
            if (record.deleted || !record.data) continue;

            try {
                await browser.runtime.sendMessage(this.extensionId, {
                    type: 'MIDORI_SYNC_SET_CONFIG',
                    config: record.data,
                });
            } catch {
                console.warn('[MidoriTabAdapter] Failed to apply config to Midori Tab');
            }
        }
    }

    async toSyncRecord(config) {
        return {
            id: 'midori-tab-config',
            data: config,
        };
    }

    async fromSyncRecord(record) {
        return record.data;
    }
}

if (typeof globalThis !== 'undefined') {
    globalThis.MidoriTabAdapter = MidoriTabAdapter;
}
