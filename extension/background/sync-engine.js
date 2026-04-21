/**
 * Midori Sync Engine
 *
 * Orchestrates sync operations: pull remote changes, push local changes,
 * handle conflict resolution, and manage sync scheduling.
 */

class SyncEngine {
    constructor() {
        this.crypto = new MidoriSyncCrypto();
        this.serverUrl = '';
        this.authToken = '';
        this.masterKey = null;
        this.adapters = {};
        this.enabledCollections = new Set();
        this.syncInterval = 5; // minutes
        this.isSyncing = false;
        this.lastSyncTimestamps = {};
        this._changeBuffer = {};
        this._debounceTimers = {};
    }

    async initialize(config) {
        await this.crypto.init();

        this.serverUrl = config.serverUrl.replace(/\/$/, '');
        this.authToken = config.authToken;
        this.masterKey = config.masterKey;
        this.syncInterval = config.syncInterval || 5;
        this.enabledCollections = new Set(config.enabledCollections || []);

        // Load last sync timestamps from storage
        const stored = await browser.storage.local.get('syncTimestamps');
        this.lastSyncTimestamps = stored.syncTimestamps || {};

        // Register adapters
        this.adapters = {
            'bookmarks': new BookmarksAdapter(),
            'history': new HistoryAdapter(),
            'open-tabs': new OpenTabsAdapter(),
            'browser-settings': new BrowserSettingsAdapter(),
            'midori-tab': new MidoriTabAdapter(),
            'midori-privacy': new MidoriPrivacyAdapter(),
        };

        // Setup sync alarm
        browser.alarms.create('midori-sync', { periodInMinutes: this.syncInterval });
        browser.alarms.onAlarm.addListener((alarm) => {
            if (alarm.name === 'midori-sync') this.syncAll();
        });

        // Setup event listeners for real-time changes
        this._setupEventListeners();
    }

    _setupEventListeners() {
        // Bookmarks
        if (this.enabledCollections.has('bookmarks')) {
            const bookmarkHandler = () => this._bufferChange('bookmarks');
            browser.bookmarks.onCreated.addListener(bookmarkHandler);
            browser.bookmarks.onChanged.addListener(bookmarkHandler);
            browser.bookmarks.onMoved.addListener(bookmarkHandler);
            browser.bookmarks.onRemoved.addListener(bookmarkHandler);
        }

        // History
        if (this.enabledCollections.has('history')) {
            browser.history.onVisited.addListener(() => this._bufferChange('history'));
        }

        // Tabs
        if (this.enabledCollections.has('open-tabs')) {
            browser.tabs.onUpdated.addListener(() => this._bufferChange('open-tabs'));
            browser.tabs.onRemoved.addListener(() => this._bufferChange('open-tabs'));
        }
    }

    _bufferChange(collectionName) {
        // Debounce: accumulate changes for 30 seconds before pushing
        if (this._debounceTimers[collectionName]) {
            clearTimeout(this._debounceTimers[collectionName]);
        }
        this._debounceTimers[collectionName] = setTimeout(() => {
            this.syncCollection(collectionName).catch(console.error);
        }, 30000);
    }

    async syncAll() {
        if (this.isSyncing) return;
        this.isSyncing = true;

        try {
            for (const name of this.enabledCollections) {
                if (this.adapters[name]) {
                    await this.syncCollection(name);
                }
            }
            await browser.storage.local.set({ lastSyncAt: Date.now() });
        } catch (err) {
            console.error('[MidoriSync] syncAll failed:', err);
        } finally {
            this.isSyncing = false;
        }
    }

    async syncCollection(name) {
        const adapter = this.adapters[name];
        if (!adapter) throw new Error(`No adapter for collection: ${name}`);

        const collectionKey = this.crypto.getCollectionKey(this.masterKey, name);
        const since = this.lastSyncTimestamps[name] || 0;

        // 1. Pull remote changes
        const remoteRecords = await this._fetchRecords(name, since);

        // 2. Decrypt and apply remote changes
        if (remoteRecords.length > 0) {
            const decrypted = [];
            for (const record of remoteRecords) {
                try {
                    if (record.deleted) {
                        decrypted.push({ ...record, data: null });
                    } else {
                        const plaintext = this.crypto.decrypt(record.payload, collectionKey);
                        decrypted.push({ ...record, data: JSON.parse(plaintext) });
                    }
                } catch (err) {
                    console.warn(`[MidoriSync] Failed to decrypt record ${record.id}:`, err);
                }
            }
            await adapter.applyRemote(decrypted);
        }

        // 3. Get local changes and push
        const localItems = await adapter.getAll();
        const records = [];

        for (const item of localItems) {
            const syncRecord = await adapter.toSyncRecord(item);
            const plaintext = JSON.stringify(syncRecord.data);
            const encrypted = this.crypto.encrypt(plaintext, collectionKey);

            records.push({
                id: syncRecord.id,
                payload: encrypted,
                deleted: false,
            });
        }

        if (records.length > 0) {
            await this._pushRecords(name, records);
        }

        // 4. Update timestamp
        this.lastSyncTimestamps[name] = Date.now() / 1000;
        await browser.storage.local.set({ syncTimestamps: this.lastSyncTimestamps });
    }

    async _fetchRecords(collectionName, since) {
        const url = `${this.serverUrl}/api/v1/collections/${collectionName}?since=${since}&include_deleted=true`;
        const response = await fetch(url, {
            headers: {
                'Authorization': `Bearer ${this.authToken}`,
                'Content-Type': 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error(`Failed to fetch ${collectionName}: ${response.status}`);
        }

        const data = await response.json();
        return data.records || [];
    }

    async _pushRecords(collectionName, records) {
        // Batch upsert (max 100 per request)
        for (let i = 0; i < records.length; i += 100) {
            const batch = records.slice(i, i + 100);
            const response = await fetch(
                `${this.serverUrl}/api/v1/collections/${collectionName}`,
                {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${this.authToken}`,
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ records: batch }),
                }
            );

            if (!response.ok) {
                throw new Error(`Failed to push ${collectionName}: ${response.status}`);
            }
        }
    }

    async getStatus() {
        const response = await fetch(`${this.serverUrl}/api/v1/sync/info`, {
            headers: { 'Authorization': `Bearer ${this.authToken}` },
        });
        return response.json();
    }

    destroy() {
        browser.alarms.clear('midori-sync');
        for (const timer of Object.values(this._debounceTimers)) {
            clearTimeout(timer);
        }
    }
}

if (typeof globalThis !== 'undefined') {
    globalThis.SyncEngine = SyncEngine;
}
