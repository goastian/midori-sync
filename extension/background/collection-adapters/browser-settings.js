/**
 * Browser Settings Collection Adapter
 * Uses browser.browserSettings API to read/write browser preferences.
 */
class BrowserSettingsAdapter {
    constructor() {
        this.settingKeys = [
            'homepageOverride',
            'newTabPageOverride',
        ];
    }

    async getAll() {
        const settings = {};
        for (const key of this.settingKeys) {
            try {
                if (browser.browserSettings[key]) {
                    const result = await browser.browserSettings[key].get({});
                    settings[key] = result.value;
                }
            } catch {
                // Setting not available
            }
        }
        return [settings];
    }

    async getChangesSince() {
        return this.getAll();
    }

    async applyRemote(records) {
        for (const record of records) {
            if (record.deleted || !record.data) continue;

            const data = record.data;
            for (const [key, value] of Object.entries(data)) {
                try {
                    if (browser.browserSettings[key] && browser.browserSettings[key].set) {
                        await browser.browserSettings[key].set({ value });
                    }
                } catch (err) {
                    console.warn('[BrowserSettingsAdapter] Failed to set:', key, err);
                }
            }
        }
    }

    async toSyncRecord(settings) {
        return {
            id: 'browser-settings',
            data: settings,
        };
    }

    async fromSyncRecord(record) {
        return record.data;
    }
}

if (typeof globalThis !== 'undefined') {
    globalThis.BrowserSettingsAdapter = BrowserSettingsAdapter;
}
