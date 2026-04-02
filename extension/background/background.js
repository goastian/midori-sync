/**
 * Midori Sync — Background Service Worker
 *
 * Handles authentication, sync scheduling, and data synchronization
 * with the Midori Sync server.
 */

const DEFAULT_SERVER = 'http://localhost:8000';

const SYNC_TYPES = {
    bookmarks: { label: 'Bookmarks', interval: 15, enabled: true },
    history: { label: 'History', interval: 30, enabled: true },
    tabs: { label: 'Open Tabs', interval: 5, enabled: true },
    passwords: { label: 'Passwords', interval: 60, enabled: true },
    // creditcards: { label: 'Credit Cards', interval: 60, enabled: false },
};

// ─── State ──────────────────────────────────────────────────────────────

let authState = {
    token: null,
    user: null,
    device: null,
    serverUrl: DEFAULT_SERVER,
};

// Delta sync: timestamp (microtime float) del último item recibido por colección
let lastSyncTimes = {
    bookmarks: 0,
    history: 0,
    tabs: 0,
    passwords: 0,
};

// Debounce: timers pendientes por tipo para coalescencia de eventos
let syncTimeouts = {};

// ─── Initialization ─────────────────────────────────────────────────────

/**
 * Restore auth state from storage. Runs every time the background
 * script loads (browser start, event page wake-up, extension reload).
 */
async function initializeState() {
    const stored = await browser.storage.local.get([
        'auth', 'syncSettings', 'serverUrl', 'lastSyncTimes',
    ]);

    if (stored.auth) {
        authState = { ...authState, ...stored.auth };
    }
    if (stored.serverUrl) {
        authState.serverUrl = stored.serverUrl;
    }
    if (stored.lastSyncTimes) {
        lastSyncTimes = { ...lastSyncTimes, ...stored.lastSyncTimes };
    }

    if (authState.token) {
        setupSyncAlarms(stored.syncSettings || {});
        updateBadge('on');
    } else {
        updateBadge('off');
    }
}

// Run immediately on script load (covers event page wake-up)
initializeState();

// Also run on browser startup and extension install/update
browser.runtime.onStartup.addListener(initializeState);
browser.runtime.onInstalled.addListener(initializeState);

// ─── Message Listener ───────────────────────────────────────────────────

browser.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    const handlers = {
        login: handleLogin,
        logout: handleLogout,
        getState: handleGetState,
        syncNow: handleSyncNow,
        saveSyncSettings: handleSaveSyncSettings,
        generatePairingToken: handleGeneratePairingToken,
        redeemPairingToken: handleRedeemPairingToken,
        getProfile: handleGetProfile,
        removeDevice: handleRemoveDevice,
    };

    const handler = handlers[msg.type];
    if (handler) {
        handler(msg.data).then(sendResponse).catch(err => {
            sendResponse({ error: err.message });
        });
        return true; // async response
    }
});

// ─── Auth Handlers ──────────────────────────────────────────────────────

/**
 * Start the OAuth2 Authorization Code login flow.
 *
 * 1. Calls /api/ext/auth/start to get the Authentik auth URL + state
 * 2. Opens the auth URL in a new browser tab
 * 3. Polls /api/ext/auth/poll until the server confirms login
 * 4. Stores the API token and user info
 */
async function handleLogin(data) {
    const { serverUrl } = data;
    const server = serverUrl || authState.serverUrl;

    // Get browser info for device name
    const browserInfo = await browser.runtime.getBrowserInfo().catch(() => ({
        name: 'Midori', version: '0',
    }));
    const deviceName = `${browserInfo.name} on ${navigator.platform}`;

    // Reuse existing device_id if we have one (prevents duplicate device registrations)
    const existingDeviceId = authState.device?.id || '';

    // Step 1: Get auth URL from server
    const startResp = await fetch(
        `${server}/api/ext/auth/start?device_name=${encodeURIComponent(deviceName)}&device_type=desktop&device_id=${encodeURIComponent(existingDeviceId)}`,
        { headers: { 'Accept': 'application/json' } }
    );

    if (!startResp.ok) {
        throw new Error('Failed to start authentication');
    }

    const { auth_url, state } = await startResp.json();

    // Step 2: Open Authentik login page in a new tab
    const tab = await browser.tabs.create({ url: auth_url });

    // Step 3: Poll for the result with exponential backoff (up to 5 minutes)
    const maxAttempts = 150;
    const baseDelay = 1000;
    const backoffMultiplier = 1.5;
    const maxDelay = 30000;

    for (let i = 0; i < maxAttempts; i++) {
        const delay = Math.min(baseDelay * Math.pow(backoffMultiplier, i), maxDelay);
        await new Promise(resolve => setTimeout(resolve, delay));

        // Check if the tab was closed by the user
        try {
            await browser.tabs.get(tab.id);
        } catch {
            // Tab was closed — check one last time
            const finalResp = await fetch(
                `${server}/api/ext/auth/poll?state=${state}`,
                { headers: { 'Accept': 'application/json' } }
            );
            const finalResult = await finalResp.json();
            if (finalResult.status === 'complete') {
                return await completeLogin(finalResult, server);
            }
            throw new Error('Login cancelled');
        }

        const pollResp = await fetch(
            `${server}/api/ext/auth/poll?state=${state}`,
            { headers: { 'Accept': 'application/json' } }
        );

        if (!pollResp.ok) continue;

        const pollResult = await pollResp.json();

        if (pollResult.status === 'complete') {
            // Close the auth tab
            browser.tabs.remove(tab.id).catch(() => {});
            return await completeLogin(pollResult, server);
        }
        // status === 'pending' → keep polling
    }

    throw new Error('Login timed out. Please try again.');
}

/**
 * Complete the login process after receiving the token from the server.
 * After storing credentials, restores data from the server to populate
 * the local browser (bookmarks, history, tabs).
 */
async function completeLogin(result, server) {
    authState = {
        token: result.token,
        user: result.user,
        device: result.device,
        serverUrl: server,
    };

    await browser.storage.local.set({
        auth: authState,
        serverUrl: server,
    });

    const stored = await browser.storage.local.get('syncSettings');
    setupSyncAlarms(stored.syncSettings || {});
    updateBadge('on');

    // Restore data from server after login
    try {
        await restoreFromServer();
    } catch (e) {
        console.warn('[Midori Sync] Data restore after login failed:', e);
    }

    return { success: true, user: result.user, device: result.device };
}

/**
 * Pull all synced data from the server and populate the local browser.
 * Runs once after login to restore bookmarks, history, and tabs.
 */
async function restoreFromServer() {
    console.log('[Midori Sync] Restoring data from server...');
    updateBadge('syncing');

    await restoreBookmarks();
    await restoreHistory();
    // Tabs are read-only from other devices — just upload ours
    await syncTabs();

    updateBadge('on');
    console.log('[Midori Sync] Data restore complete.');
}

/**
 * Restore bookmarks from the server into the local browser.
 * Only creates bookmarks that don't already exist locally (by URL).
 */
async function restoreBookmarks() {
    const serverData = await fetchCollection('bookmarks');
    if (!serverData || serverData.length === 0) return;

    // Get existing local bookmarks by URL for deduplication
    const tree = await browser.bookmarks.getTree();
    const localUrls = new Set();
    flattenBookmarks(tree).forEach(b => localUrls.add(b.url));

    let restored = 0;
    for (const bso of serverData) {
        try {
            const payload = JSON.parse(bso.payload);
            if (payload.url && !localUrls.has(payload.url)) {
                await browser.bookmarks.create({
                    title: payload.title || payload.url,
                    url: payload.url,
                });
                restored++;
            }
        } catch (e) {
            // Skip malformed entries
        }
    }
    console.log(`[Midori Sync] Restored ${restored} bookmarks from server.`);
}

/**
 * Restore history from the server into the local browser.
 * Uses browser.history.addUrl to populate entries that don't exist locally.
 * Persists server data to IndexedDB to avoid saturating storage.local.
 */
async function restoreHistory() {
    const serverData = await fetchCollection('history');
    if (!serverData || serverData.length === 0) return;

    const THIRTY_DAYS_MS = 30 * 24 * 3600 * 1000;
    const thirtyDaysAgo = Date.now() - THIRTY_DAYS_MS;

    // Solo restaurar historial reciente (30 días)
    const recentData = serverData.filter(bso => {
        try {
            const payload = JSON.parse(bso.payload);
            return payload.lastVisitTime && payload.lastVisitTime >= thirtyDaysAgo;
        } catch {
            return false;
        }
    });

    // Guardar en IndexedDB para referencia futura (no en storage.local)
    const idbItems = recentData.flatMap(bso => {
        try {
            return [JSON.parse(bso.payload)];
        } catch {
            return [];
        }
    });
    if (idbItems.length > 0) {
        await storeHistory(idbItems).catch(e =>
            console.warn('[Midori Sync] IndexedDB store failed:', e)
        );
    }

    let restored = 0;
    for (const bso of recentData) {
        try {
            const payload = JSON.parse(bso.payload);
            if (payload.url) {
                await browser.history.addUrl({
                    url: payload.url,
                    title: payload.title || '',
                    visitTime: payload.lastVisitTime || Date.now(),
                });
                restored++;
            }
        } catch (e) {
            // Skip malformed entries
        }
    }
    console.log(`[Midori Sync] Restored ${restored} history entries from server.`);
}

/**
 * Log the user out and stop all sync alarms.
 */
async function handleLogout() {
    if (authState.token) {
        await fetch(`${authState.serverUrl}/api/ext/logout`, {
            method: 'POST',
            headers: authHeaders(),
        }).catch(() => {});
    }

    authState = { token: null, user: null, device: null, serverUrl: authState.serverUrl };
    await browser.storage.local.remove('auth');
    await browser.alarms.clearAll();
    updateBadge('off');

    return { success: true };
}

/**
 * Return the current authentication and sync state.
 * Always reads from storage to avoid race conditions with initializeState().
 */
async function handleGetState() {
    const stored = await browser.storage.local.get(['auth', 'syncSettings', 'lastSync', 'serverUrl', 'storageInfo']);

    // Ensure in-memory state is up to date
    if (stored.auth && stored.auth.token && !authState.token) {
        authState = { ...authState, ...stored.auth };
        if (stored.serverUrl) authState.serverUrl = stored.serverUrl;
    }

    return {
        isLoggedIn: !!authState.token,
        user: authState.user,
        device: authState.device,
        serverUrl: authState.serverUrl,
        syncSettings: stored.syncSettings || {},
        lastSync: stored.lastSync || {},
        storageInfo: stored.storageInfo || null,
        syncTypes: SYNC_TYPES,
    };
}

/**
 * Get user profile and devices from server.
 */
async function handleGetProfile() {
    if (!authState.token) throw new Error('Not logged in');

    const response = await fetch(`${authState.serverUrl}/api/ext/profile`, {
        headers: authHeaders(),
    });

    if (!response.ok) throw new Error('Failed to fetch profile');

    return await response.json();
}

// ─── Sync Handlers ──────────────────────────────────────────────────────

/**
 * Trigger a manual sync for all enabled types or a specific type.
 */
async function handleSyncNow(data) {
    if (!authState.token) throw new Error('Not logged in');

    const type = data?.type;
    const stored = await browser.storage.local.get('syncSettings');
    const settings = stored.syncSettings || {};

    if (type) {
        await syncDataType(type);
    } else {
        // Sync all enabled types
        for (const [key, defaults] of Object.entries(SYNC_TYPES)) {
            const enabled = settings[key]?.enabled ?? defaults.enabled;
            if (enabled) {
                await syncDataType(key);
            }
        }
    }

    // Update sync status on server
    await fetch(`${authState.serverUrl}/api/ext/sync/status`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id: authState.device?.id }),
    }).catch(() => {});

    return { success: true };
}

/**
 * Save sync settings (intervals, enabled/disabled per type).
 */
async function handleSaveSyncSettings(data) {
    await browser.storage.local.set({ syncSettings: data });
    setupSyncAlarms(data);
    return { success: true };
}

// ─── Sync Engine ────────────────────────────────────────────────────────

/**
 * Sync a specific data type with the server.
 * Libera referencias a datos grandes en el bloque finally para ayudar al GC.
 */
async function syncDataType(type) {
    const now = new Date().toISOString();
    let syncPromise = null;

    try {
        switch (type) {
            case 'bookmarks':
                syncPromise = syncBookmarks();
                break;
            case 'history':
                syncPromise = syncHistory();
                break;
            case 'tabs':
                syncPromise = syncTabs();
                break;
            case 'passwords':
                // Passwords require the optional "logins" permission
                syncPromise = syncPasswords();
                break;
        }

        if (syncPromise) await syncPromise;

        // Update last sync timestamp
        const stored = await browser.storage.local.get('lastSync');
        const lastSync = stored.lastSync || {};
        lastSync[type] = now;
        await browser.storage.local.set({ lastSync });

        // Refresh storage quota info (best-effort)
        refreshStorageInfo();

        console.log(`[Midori Sync] Synced ${type} at ${now}`);
    } catch (err) {
        console.error(`[Midori Sync] Failed to sync ${type}:`, err);
    } finally {
        syncPromise = null; // Liberar referencia explícita
    }
}

/**
 * Sync bookmarks with the server.
 * Downloads server bookmarks and merges with local ones.
 */
async function syncBookmarks() {
    // Get local bookmarks
    const tree = await browser.bookmarks.getTree();
    const localBookmarks = flattenBookmarks(tree);

    // Get server bookmarks
    const serverData = await fetchCollection('bookmarks');

    // Upload local bookmarks not on server
    const serverIds = new Set(serverData.map(b => b.id));
    const toUpload = localBookmarks.filter(b => !serverIds.has(b.id));

    if (toUpload.length > 0) {
        await uploadBsos('bookmarks', toUpload.map(b => ({
            id: b.id,
            payload: JSON.stringify(b),
        })));
    }

    // Create local bookmarks from server that don't exist locally
    const localIds = new Set(localBookmarks.map(b => b.id));
    for (const serverBso of serverData) {
        if (!localIds.has(serverBso.id)) {
            try {
                const payload = JSON.parse(serverBso.payload);
                if (payload.url && payload.title) {
                    await browser.bookmarks.create({
                        title: payload.title,
                        url: payload.url,
                        parentId: payload.parentId || undefined,
                    });
                }
            } catch (e) {
                console.warn('[Midori Sync] Could not create bookmark:', e);
            }
        }
    }
}

/**
 * Flatten the bookmark tree into a list of bookmark objects.
 */
function flattenBookmarks(nodes, result = []) {
    for (const node of nodes) {
        if (node.url) {
            result.push({
                id: 'bk-' + hashString(node.url),
                title: node.title || '',
                url: node.url,
                parentId: node.parentId,
                dateAdded: node.dateAdded,
            });
        }
        if (node.children) {
            flattenBookmarks(node.children, result);
        }
    }
    return result;
}

/**
 * Sync browsing history with the server.
 * Solo sube historial de los últimos 30 días para reducir payload.
 */
async function syncHistory() {
    const THIRTY_DAYS_MS = 30 * 24 * 3600 * 1000;
    const thirtyDaysAgo = Date.now() - THIRTY_DAYS_MS;

    const stored = await browser.storage.local.get('lastSync');
    const lastSync = stored.lastSync?.history;
    // Usa lastSync si existe, pero nunca más de 30 días atrás
    const startTime = lastSync
        ? Math.max(new Date(lastSync).getTime(), thirtyDaysAgo)
        : thirtyDaysAgo;

    const historyItems = await browser.history.search({
        text: '',
        startTime,
        maxResults: 500,
    });

    if (historyItems.length === 0) return;

    const bsos = historyItems.map(item => ({
        id: 'hi-' + hashString(item.url),
        payload: JSON.stringify({
            url: item.url,
            title: item.title || '',
            visitCount: item.visitCount,
            lastVisitTime: item.lastVisitTime,
        }),
    }));

    await uploadBsos('history', bsos);
}

/**
 * Sync open tabs with the server.
 */
async function syncTabs() {
    const tabs = await browser.tabs.query({});
    const tabData = tabs
        .filter(t => t.url && !t.url.startsWith('about:') && !t.url.startsWith('moz-extension:'))
        .map(t => ({
            url: t.url,
            title: t.title || '',
            icon: t.favIconUrl || '',
            active: t.active,
            lastAccessed: t.lastAccessed,
        }));

    const clientId = authState.device?.id || 'unknown';

    await uploadBsos('tabs', [{
        id: clientId,
        payload: JSON.stringify({
            clientName: authState.device?.name || 'Midori',
            tabs: tabData,
        }),
    }]);
}

/**
 * Sync passwords.
 *
 * Note: There is no standard WebExtension API for accessing saved passwords.
 * Password sync is not available in the current MVP.
 */
async function syncPasswords() {
    console.log('[Midori Sync] Password sync is not yet available — no standard WebExtension API exists for saved logins.');
}

// ─── Server Communication ───────────────────────────────────────────────

/**
 * Fetch a collection from the sync server.
 * Usa delta sync: solo descarga items modificados después de lastSyncTimes[collection].
 * Para colecciones grandes usa fetchCollectionPaginated en su lugar.
 */
async function fetchCollection(collection, incrementalOnly = true) {
    if (!authState.token) return [];

    let url = `${authState.serverUrl}/api/ext/storage/${collection}`;
    if (incrementalOnly && lastSyncTimes[collection]) {
        url += `?newer=${lastSyncTimes[collection]}`;
    }

    const resp = await fetch(url, { headers: authHeaders() });

    if (!resp.ok) return [];

    const items = await resp.json();

    // Actualizar timestamp del último item recibido
    if (Array.isArray(items) && items.length > 0) {
        const maxModified = Math.max(...items.map(i => parseFloat(i.modified || 0)));
        if (maxModified > 0) {
            lastSyncTimes[collection] = maxModified;
            await browser.storage.local.set({ lastSyncTimes });
        }
    }

    return Array.isArray(items) ? items : [];
}

/**
 * Fetch a large collection in paginated chunks of 500 items.
 * Returns all items (up to maxFetch). Combina con delta sync si hay lastSyncTimes.
 */
async function fetchCollectionPaginated(collectionName, maxFetch = 10000) {
    if (!authState.token) return [];

    let allItems = [];
    let offset = 0;
    const limit = 500;

    while (true) {
        let url = `${authState.serverUrl}/api/ext/storage/${collectionName}?offset=${offset}&limit=${limit}`;
        if (lastSyncTimes[collectionName]) {
            url += `&newer=${lastSyncTimes[collectionName]}`;
        }

        const response = await fetch(url, { headers: authHeaders() });
        if (!response.ok) break;

        const data = await response.json();
        // El backend devuelve {items, nextOffset} cuando se especifica limit
        const pageItems = Array.isArray(data.items) ? data.items : (Array.isArray(data) ? data : []);
        allItems = allItems.concat(pageItems);

        if (!data.nextOffset || allItems.length >= maxFetch) {
            break;
        }
        offset = data.nextOffset;
    }

    // Actualizar timestamp del último item recibido
    if (allItems.length > 0) {
        const maxModified = Math.max(...allItems.map(i => parseFloat(i.modified || 0)));
        if (maxModified > 0) {
            lastSyncTimes[collectionName] = maxModified;
            await browser.storage.local.set({ lastSyncTimes });
        }
    }

    return allItems;
}

/**
 * Upload BSOs to a collection on the sync server.
 */
async function uploadBsos(collection, bsos) {
    const uid = authState.user?.id;
    if (!uid || bsos.length === 0) return;

    await fetch(`${authState.serverUrl}/api/ext/storage/${collection}`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify(bsos),
    });
}

// ─── Device Pairing ─────────────────────────────────────────────────────

/**
 * Generate a pairing token for connecting another device.
 */
async function handleGeneratePairingToken() {
    if (!authState.token) throw new Error('Not logged in');

    const response = await fetch(`${authState.serverUrl}/api/ext/pair`, {
        method: 'POST',
        headers: authHeaders(),
    });

    if (!response.ok) throw new Error('Failed to generate pairing token');

    return await response.json();
}

/**
 * Redeem a pairing token from another device.
 */
async function handleRedeemPairingToken(data) {
    const { pairingToken, serverUrl } = data;
    const server = serverUrl || authState.serverUrl;

    const browserInfo = await browser.runtime.getBrowserInfo().catch(() => ({
        name: 'Midori', version: '0',
    }));

    const response = await fetch(`${server}/api/ext/pair/redeem`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
            pairing_token: pairingToken,
            device_name: `${browserInfo.name} on ${navigator.platform}`,
            device_type: 'desktop',
        }),
    });

    if (!response.ok) {
        const err = await response.json();
        throw new Error(err.message || 'Pairing failed');
    }

    const result = await response.json();

    authState = {
        token: result.token,
        user: result.user,
        device: result.device,
        serverUrl: server,
    };

    await browser.storage.local.set({ auth: authState, serverUrl: server });

    const stored = await browser.storage.local.get('syncSettings');
    setupSyncAlarms(stored.syncSettings || {});
    updateBadge('on');

    return { success: true, user: result.user, device: result.device };
}

/**
 * Remove a device.
 */
async function handleRemoveDevice(data) {
    if (!authState.token) throw new Error('Not logged in');

    // The web dashboard handles this — return info for now
    return { success: true };
}

// ─── Alarm Scheduling ───────────────────────────────────────────────────

/**
 * Setup periodic sync alarms based on user settings.
 */
function setupSyncAlarms(settings) {
    browser.alarms.clearAll();

    for (const [type, defaults] of Object.entries(SYNC_TYPES)) {
        const config = settings[type] || {};
        const enabled = config.enabled ?? defaults.enabled;
        const interval = config.interval ?? defaults.interval;

        if (enabled && authState.token) {
            browser.alarms.create(`sync-${type}`, {
                periodInMinutes: interval,
            });
        }
    }
}

browser.alarms.onAlarm.addListener(async (alarm) => {
    if (alarm.name.startsWith('sync-')) {
        const type = alarm.name.replace('sync-', '');
        await syncDataType(type);
    }
});

// ─── Debounced Event Listeners ──────────────────────────────────────────

/**
 * Debounced sync: coalesces rapid consecutive changes into a single sync call.
 */
function debouncedSync(type, delayMs = 500) {
    if (syncTimeouts[type]) {
        clearTimeout(syncTimeouts[type]);
    }
    syncTimeouts[type] = setTimeout(() => {
        delete syncTimeouts[type];
        if (authState.token) {
            syncDataType(type).catch(e =>
                console.warn(`[Midori Sync] Debounced sync failed for ${type}:`, e)
            );
        }
    }, delayMs);
}

// Bookmark changes → debounce 2s (coalesce rapid folder/title edits)
browser.bookmarks.onCreated.addListener(() => debouncedSync('bookmarks', 2000));
browser.bookmarks.onChanged.addListener(() => debouncedSync('bookmarks', 2000));
browser.bookmarks.onRemoved.addListener(() => debouncedSync('bookmarks', 2000));
browser.bookmarks.onMoved.addListener(() => debouncedSync('bookmarks', 2000));

// History visits → debounce 5s (high frequency, batch window)
browser.history.onVisited.addListener(() => debouncedSync('history', 5000));

// ─── IndexedDB Storage ──────────────────────────────────────────────────

let _idb = null;

/**
 * Abre (o retorna cached) la base de datos IndexedDB para almacenamiento de datos grandes.
 */
async function initializeIndexedDB() {
    if (_idb) return _idb;

    return new Promise((resolve, reject) => {
        const req = indexedDB.open('midori-sync', 1);

        req.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('history')) {
                db.createObjectStore('history', { keyPath: 'url' });
            }
            if (!db.objectStoreNames.contains('bookmarks')) {
                db.createObjectStore('bookmarks', { keyPath: 'id' });
            }
        };

        req.onsuccess = () => {
            _idb = req.result;
            resolve(_idb);
        };
        req.onerror = () => reject(req.error);
    });
}

/**
 * Almacena items de historial en IndexedDB en lugar de storage.local.
 * Evita llenar el límite de 10MB de storage.local con historiales grandes.
 */
async function storeHistory(items) {
    const db = await initializeIndexedDB();
    const tx = db.transaction(['history'], 'readwrite');
    const store = tx.objectStore('history');

    for (const item of items) {
        store.put(item);
    }

    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

// ─── Utilities ──────────────────────────────────────────────────────────

/**
 * Build the Authorization headers for API calls.
 */
function authHeaders() {
    return {
        Authorization: `Bearer ${authState.token}`,
        Accept: 'application/json',
    };
}

/**
 * Fetch storage quota info from the server and cache it locally.
 * Non-critical: errors are silently ignored.
 */
async function refreshStorageInfo() {
    if (!authState.token) return;
    try {
        const response = await fetch(`${authState.serverUrl}/api/ext/storage/info`, {
            headers: authHeaders(),
        });
        if (response.ok) {
            const data = await response.json();
            await browser.storage.local.set({ storageInfo: data });
        }
    } catch (e) {
        // Non-critical, ignore
    }
}

/**
 * Simple string hash for generating deterministic IDs.
 */
function hashString(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        const ch = str.charCodeAt(i);
        hash = ((hash << 5) - hash) + ch;
        hash |= 0;
    }
    return Math.abs(hash).toString(36);
}

/**
 * Update the browser action badge.
 */
function updateBadge(state) {
    if (state === 'on') {
        browser.browserAction.setBadgeText({ text: '✓' });
        browser.browserAction.setBadgeBackgroundColor({ color: '#22c55e' });
    } else if (state === 'syncing') {
        browser.browserAction.setBadgeText({ text: '↻' });
        browser.browserAction.setBadgeBackgroundColor({ color: '#3b82f6' });
    } else {
        browser.browserAction.setBadgeText({ text: '' });
    }
}
