/**
 * Open Tabs Collection Adapter
 * Uses browser.tabs API to read current open tabs.
 */
class OpenTabsAdapter {
    async getAll() {
        const tabs = await browser.tabs.query({});
        return tabs.filter(t => !t.url.startsWith('about:') && !t.url.startsWith('moz-extension:'));
    }

    async getChangesSince() {
        return this.getAll();
    }

    async applyRemote(records) {
        // Open tabs sync is read-only from remote perspective.
        // We don't auto-open tabs from other devices; just store for display.
        // The popup/options page can show tabs from other devices.
    }

    async toSyncRecord(tab) {
        return {
            id: `tab_${tab.id}`,
            data: {
                url: tab.url,
                title: tab.title,
                favIconUrl: tab.favIconUrl,
                pinned: tab.pinned,
                index: tab.index,
                windowId: tab.windowId,
                active: tab.active,
                lastAccessed: tab.lastAccessed,
            },
        };
    }

    async fromSyncRecord(record) {
        return record.data;
    }
}

if (typeof globalThis !== 'undefined') {
    globalThis.OpenTabsAdapter = OpenTabsAdapter;
}
