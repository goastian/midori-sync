/**
 * Midori Sync Extension — Popup Script
 *
 * Handles UI interactions for login, sync status, and navigation.
 */

const SYNC_ICONS = {
    bookmarks: '🔖',
    history: '🕐',
    tabs: '📑',
    passwords: '🔒',
    creditcards: '💳',
};

// ─── DOM Elements ───────────────────────────────────────────────────────

const views = {
    login: document.getElementById('view-login'),
    pair: document.getElementById('view-pair'),
    main: document.getElementById('view-main'),
};

// ─── Initialization ─────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', async () => {
    const state = await sendMessage('getState');
    if (state.isLoggedIn) {
        showMainView(state);
    } else {
        showView('login');
    }

    setupEventListeners();
});

// ─── View Management ────────────────────────────────────────────────────

/**
 * Show a specific view and hide all others.
 */
function showView(name) {
    Object.values(views).forEach(v => v.classList.add('hidden'));
    views[name].classList.remove('hidden');
}

/**
 * Show the main (logged-in) view with user data and sync status.
 */
function showMainView(state) {
    showView('main');

    // User info
    document.getElementById('user-name').textContent = state.user?.name || 'User';
    document.getElementById('user-email').textContent = state.user?.email || '';

    const avatarEl = document.getElementById('user-avatar');
    if (state.user?.avatar) {
        avatarEl.innerHTML = `<img src="${state.user.avatar}" alt="Avatar">`;
    }

    // Sync items
    renderSyncItems(state.syncTypes, state.syncSettings, state.lastSync);
}

/**
 * Render the list of sync data types with toggles and last sync time.
 */
function renderSyncItems(syncTypes, settings, lastSync) {
    const container = document.getElementById('sync-items');
    container.innerHTML = '';

    for (const [type, defaults] of Object.entries(syncTypes)) {
        const config = settings[type] || {};
        const enabled = config.enabled ?? defaults.enabled;
        const last = lastSync[type];

        const item = document.createElement('div');
        item.className = 'sync-item';
        item.innerHTML = `
            <div class="sync-item-left">
                <span class="sync-icon">${SYNC_ICONS[type] || '📦'}</span>
                <div>
                    <div class="sync-label">${defaults.label}</div>
                    <div class="sync-time">${last ? formatTime(last) : 'Never synced'}</div>
                </div>
            </div>
            <div class="sync-item-right">
                <button class="sync-btn" data-type="${type}" title="Sync now">↻</button>
                <input type="checkbox" class="sync-toggle" data-type="${type}" ${enabled ? 'checked' : ''}>
            </div>
        `;
        container.appendChild(item);
    }

    // Sync individual type buttons
    container.querySelectorAll('.sync-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const type = e.currentTarget.dataset.type;
            e.currentTarget.classList.add('spinning');
            await sendMessage('syncNow', { type });
            e.currentTarget.classList.remove('spinning');

            // Refresh state
            const state = await sendMessage('getState');
            renderSyncItems(state.syncTypes, state.syncSettings, state.lastSync);
        });
    });

    // Toggle handlers
    container.querySelectorAll('.sync-toggle').forEach(toggle => {
        toggle.addEventListener('change', async (e) => {
            const type = e.target.dataset.type;
            const state = await sendMessage('getState');
            const settings = state.syncSettings || {};

            if (!settings[type]) settings[type] = {};
            settings[type].enabled = e.target.checked;

            await sendMessage('saveSyncSettings', settings);
        });
    });
}

// ─── Event Listeners ────────────────────────────────────────────────────

function setupEventListeners() {
    // Login button — opens Authentik in a new tab via OAuth flow
    document.getElementById('login-btn').addEventListener('click', async () => {
        const btn = document.getElementById('login-btn');
        const errorEl = document.getElementById('login-error');
        errorEl.classList.add('hidden');

        setLoading(btn, true);

        try {
            const result = await sendMessage('login', {
                serverUrl: document.getElementById('server-url').value,
            });

            if (result.error) throw new Error(result.error);

            const state = await sendMessage('getState');
            showMainView(state);
        } catch (err) {
            errorEl.textContent = err.message || 'Login failed. Please try again.';
            errorEl.classList.remove('hidden');
        } finally {
            setLoading(btn, false);
        }
    });

    // Show pair view
    document.getElementById('show-pair').addEventListener('click', () => showView('pair'));
    document.getElementById('pair-back').addEventListener('click', () => showView('login'));

    // Pair form
    document.getElementById('pair-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const errorEl = document.getElementById('pair-error');
        errorEl.classList.add('hidden');

        setLoading(btn, true);

        try {
            const result = await sendMessage('redeemPairingToken', {
                pairingToken: document.getElementById('pair-code').value.trim(),
                serverUrl: document.getElementById('pair-server').value,
            });

            if (result.error) throw new Error(result.error);

            const state = await sendMessage('getState');
            showMainView(state);
        } catch (err) {
            errorEl.textContent = err.message || 'Pairing failed.';
            errorEl.classList.remove('hidden');
        } finally {
            setLoading(btn, false);
        }
    });

    // Sync all button
    document.getElementById('sync-all-btn').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        btn.textContent = 'Syncing...';

        await sendMessage('syncNow');

        const state = await sendMessage('getState');
        renderSyncItems(state.syncTypes, state.syncSettings, state.lastSync);

        btn.disabled = false;
        btn.textContent = 'Sync Now';
    });

    // Settings button
    document.getElementById('open-settings').addEventListener('click', () => {
        browser.runtime.openOptionsPage();
    });

    // Logout button
    document.getElementById('logout-btn').addEventListener('click', async () => {
        await sendMessage('logout');
        showView('login');
    });
}

// ─── Utilities ──────────────────────────────────────────────────────────

/**
 * Send a message to the background script.
 */
function sendMessage(type, data = {}) {
    return browser.runtime.sendMessage({ type, data });
}

/**
 * Toggle loading state on a button.
 */
function setLoading(btn, loading) {
    const text = btn.querySelector('.btn-text');
    const spinner = btn.querySelector('.btn-loading');
    if (text) text.classList.toggle('hidden', loading);
    if (spinner) spinner.classList.toggle('hidden', !loading);
    btn.disabled = loading;
}

/**
 * Format a timestamp to a relative time string.
 */
function formatTime(isoString) {
    const date = new Date(isoString);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);

    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return date.toLocaleDateString();
}
