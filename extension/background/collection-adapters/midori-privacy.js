/**
 * Midori Privacy Collection Adapter
 * Communicates with the Midori Privacy extension via runtime messaging
 * to sync filter lists, site-specific toggles, and privacy settings.
 */
class MidoriPrivacyAdapter {
    constructor() {
        this.extensionId = 'midori-privacy@nickel-org.github.io'; // Update with actual ID
    }

    async getAll() {
        try {
            const response = await browser.runtime.sendMessage(this.extensionId, {
                type: 'MIDORI_SYNC_GET_PRIVACY_CONFIG',
            });
            return response ? [response] : [];
        } catch {
            console.warn('[MidoriPrivacyAdapter] Midori Privacy extension not available');
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
                    type: 'MIDORI_SYNC_SET_PRIVACY_CONFIG',
                    config: record.data,
                });
            } catch {
                console.warn('[MidoriPrivacyAdapter] Failed to apply config to Midori Privacy');
            }
        }
    }

    async toSyncRecord(config) {
        return {
            id: 'midori-privacy-config',
            data: config,
        };
    }

    async fromSyncRecord(record) {
        return record.data;
    }
}

if (typeof globalThis !== 'undefined') {
    globalThis.MidoriPrivacyAdapter = MidoriPrivacyAdapter;
}
