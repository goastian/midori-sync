/**
 * Midori Sync Extension — Popup Script
 *
 * Handles UI interactions for login, sync status, and navigation.
 * Phase 1.5: Enhanced UX with spinners, toasts, inline validation, and status panel.
 */

const SYNC_ICONS = {
    bookmarks: '🔖',
    history: '🕐',
    tabs: '📑',
    passwords: '🔒',
    creditcards: '💳',
};

// ─── Toast Notification System ──────────────────────────────────────────

/**
 * Show a toast notification with auto-dismiss.
 * @param {string} message - The message to display
 * @param {number} duration - Duration in ms (default 3000)
 * @param {string} type - 'success', 'error', 'info'
 */
function showToast(message, duration = 3000, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);

    // Trigger animation entry
    setTimeout(() => toast.classList.add('active'), 10);

    // Remove after duration
    setTimeout(() => {
        toast.classList.remove('active');
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

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
        const last = lastSync?.[type];

        // Calculate time diff for color indicator
        let timeColor = 'time-old';
        if (last) {
            const diffSeconds = Math.floor((new Date() - new Date(last)) / 1000);
            timeColor = getSyncTimeColor(diffSeconds);
        }

        const item = document.createElement('div');
        item.className = 'sync-item';
        item.innerHTML = `
            <div class="sync-item-left">
                <span class="sync-icon">${SYNC_ICONS[type] || '📦'}</span>
                <div class="sync-info">
                    <div class="sync-label">${defaults.label}</div>
                    <div class="sync-time ${timeColor}">
                        ${last ? `✓ ${formatTime(last)}` : '⊘ Never synced'}
                    </div>
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
            const label = SYNC_ICONS[type] ? Object.entries(SYNC_ICONS).find(([k]) => k === type)?.[1] : type;
            
            e.currentTarget.classList.add('spinning');
            try {
                await sendMessage('syncNow', { type });
                showToast(`✓ ${type} synced successfully`, 2000, 'success');
            } catch (err) {
                showToast(`✗ Failed to sync ${type}`, 3000, 'error');
            } finally {
                e.currentTarget.classList.remove('spinning');

                // Refresh state
                const state = await sendMessage('getState');
                renderSyncItems(state.syncTypes, state.syncSettings, state.lastSync);
            }
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
            showToast(`${type} is now ${e.target.checked ? 'enabled' : 'disabled'}`, 2000, 'info');
        });
    });
}

function setupEventListeners() {
    // Login button — opens Authentik in a new tab via OAuth flow
    const loginBtn = document.getElementById('login-btn');
    loginBtn.setAttribute('data-original-text', loginBtn.innerHTML);
    loginBtn.addEventListener('click', async () => {
        const btn = document.getElementById('login-btn');
        const errorEl = document.getElementById('login-error');
        errorEl.classList.add('hidden');

        setLoading(btn, true);

        try {
            const result = await sendMessage('login', {
                serverUrl: document.getElementById('server-url').value,
            });

            if (result.error) throw new Error(result.error);

            showToast('✓ Logged in successfully!', 2000, 'success');
            const state = await sendMessage('getState');
            showMainView(state);
        } catch (err) {
            errorEl.textContent = err.message || 'Login failed. Please try again.';
            errorEl.classList.remove('hidden');
            showToast(`✗ ${err.message}`, 3000, 'error');
        } finally {
            setLoading(btn, false);
        }
    });

    // Show pair view
    document.getElementById('show-pair').addEventListener('click', () => showView('pair'));
    document.getElementById('pair-back').addEventListener('click', () => showView('login'));

    // Pair form
    const pairForm = document.getElementById('pair-form');
    const pairCodeInput = document.getElementById('pair-code');
    
    // Inline validation for pair code
    if (pairCodeInput) {
        pairCodeInput.addEventListener('input', (e) => {
            const value = e.target.value.trim();
            if (value && value.length !== 32) {
                pairCodeInput.classList.add('error');
                pairCodeInput.setAttribute('title', 'Code must be exactly 32 characters');
            } else {
                pairCodeInput.classList.remove('error');
                pairCodeInput.removeAttribute('title');
            }
        });
    }

    pairForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const errorEl = document.getElementById('pair-error');
        errorEl.classList.add('hidden');

        btn.setAttribute('data-original-text', btn.innerHTML);
        setLoading(btn, true);

        try {
            const code = document.getElementById('pair-code').value.trim();
            if (code.length !== 32) {
                throw new Error('Pairing code must be exactly 32 characters');
            }

            const result = await sendMessage('redeemPairingToken', {
                pairingToken: code,
                serverUrl: document.getElementById('pair-server').value,
            });

            if (result.error) throw new Error(result.error);

            showToast('✓ Device paired successfully!', 2000, 'success');
            const state = await sendMessage('getState');
            showMainView(state);
        } catch (err) {
            errorEl.textContent = err.message || 'Pairing failed.';
            errorEl.classList.remove('hidden');
            showToast(`✗ ${err.message}`, 3000, 'error');
        } finally {
            setLoading(btn, false);
        }
    });

    // Sync all button
    const syncAllBtn = document.getElementById('sync-all-btn');
    syncAllBtn.setAttribute('data-original-text', syncAllBtn.innerHTML);
    syncAllBtn.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        setLoading(btn, true);

        try {
            await sendMessage('syncNow');
            showToast('✓ All data synced successfully!', 2000, 'success');
        } catch (err) {
            showToast(`✗ Sync failed: ${err.message}`, 3000, 'error');
        } finally {
            setLoading(btn, false);
            const state = await sendMessage('getState');
            renderSyncItems(state.syncTypes, state.syncSettings, state.lastSync);
        }
    });

    // Settings button
    document.getElementById('open-settings').addEventListener('click', () => {
        browser.runtime.openOptionsPage();
    });

    // Logout button
    document.getElementById('logout-btn').addEventListener('click', async () => {
        await sendMessage('logout');
        showToast('✓ Logged out', 1500, 'info');
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
 * Toggle loading state on a button with spinner animation.
 * @param {HTMLElement} btn - The button element
 * @param {boolean} loading - Whether to show loading state
 */
function setLoading(btn, loading) {
    if (loading) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> <span class="btn-text">Loading...</span>';
        btn.classList.add('loading');
    } else {
        btn.disabled = false;
        btn.classList.remove('loading');
        // Restore original text (caller should preserve it or this will be generic)
        const originalText = btn.getAttribute('data-original-text') || 'Continue';
        btn.innerHTML = originalText;
    }
}

/**
 * Format a timestamp to a relative time string with color indicator.
 * @param {string} isoString - ISO timestamp
 * @returns {string} Formatted relative time
 */
function formatTime(isoString) {
    const date = new Date(isoString);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);

    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return date.toLocaleDateString();
}

/**
 * Get time color status based on sync recency.
 * @param {number} diffSeconds - Difference in seconds
 * @returns {string} Color class name
 */
function getSyncTimeColor(diffSeconds) {
    if (diffSeconds < 3600) return 'time-fresh';     // < 1 hour: green
    if (diffSeconds < 86400) return 'time-stale';    // < 1 day: amber
    return 'time-old';                               // >= 1 day: red
}
