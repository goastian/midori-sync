/**
 * Midori Sync Extension — Options/Settings Page Script
 *
 * Manages sync schedule configuration, connected devices list,
 * QR code device pairing, and server information display.
 */

const SYNC_ICONS = {
    bookmarks: '🔖',
    history: '🕐',
    tabs: '📑',
    passwords: '🔒',
    creditcards: '💳',
};

const INTERVAL_OPTIONS = [
    { value: 1, label: '1 min' },
    { value: 5, label: '5 min' },
    { value: 15, label: '15 min' },
    { value: 30, label: '30 min' },
    { value: 60, label: '1 hour' },
    { value: 120, label: '2 hours' },
    { value: 360, label: '6 hours' },
    { value: 720, label: '12 hours' },
    { value: 1440, label: '24 hours' },
];

let pairingTimer = null;

// ─── Initialization ─────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', async () => {
    const state = await sendMessage('getState');

    if (!state.isLoggedIn) {
        document.getElementById('not-logged-in').classList.remove('hidden');
        document.getElementById('sync-settings').classList.add('hidden');
        document.getElementById('devices-section').classList.add('hidden');
        document.getElementById('pair-section').classList.add('hidden');
        return;
    }

    document.getElementById('badge-name').textContent = state.user?.name || 'User';
    document.getElementById('server-url-display').textContent = state.serverUrl || '—';
    document.getElementById('user-uid-display').textContent = state.user?.uid || '—';
    document.getElementById('device-name-display').textContent = state.device?.name || '—';

    renderSyncSettings(state.syncTypes, state.syncSettings);
    loadDevices();

    // Event listeners
    document.getElementById('generate-qr-btn').addEventListener('click', generatePairingQR);
    document.getElementById('logout-btn').addEventListener('click', handleLogout);
});

// ─── Sync Settings ──────────────────────────────────────────────────────

/**
 * Render sync settings rows with toggles and interval selectors.
 */
function renderSyncSettings(syncTypes, settings) {
    const container = document.getElementById('sync-settings-list');
    container.innerHTML = '';

    for (const [type, defaults] of Object.entries(syncTypes)) {
        const config = settings[type] || {};
        const enabled = config.enabled ?? defaults.enabled;
        const interval = config.interval ?? defaults.interval;

        const row = document.createElement('div');
        row.className = 'sync-row';
        row.innerHTML = `
            <div class="sync-row-left">
                <span class="sync-row-icon">${SYNC_ICONS[type] || '📦'}</span>
                <div class="sync-row-info">
                    <span class="sync-row-label">${defaults.label}</span>
                    <span class="sync-row-desc">Every ${formatInterval(interval)}</span>
                </div>
            </div>
            <div class="sync-row-right">
                <select class="interval-select" data-type="${type}">
                    ${INTERVAL_OPTIONS.map(o =>
                        `<option value="${o.value}" ${o.value === interval ? 'selected' : ''}>${o.label}</option>`
                    ).join('')}
                </select>
                <input type="checkbox" class="toggle" data-type="${type}" ${enabled ? 'checked' : ''}>
            </div>
        `;
        container.appendChild(row);
    }

    // Attach change handlers
    container.querySelectorAll('.toggle').forEach(el => {
        el.addEventListener('change', saveSyncSettings);
    });
    container.querySelectorAll('.interval-select').forEach(el => {
        el.addEventListener('change', saveSyncSettings);
    });
}

/**
 * Collect current settings from the UI and save them.
 */
async function saveSyncSettings() {
    const settings = {};
    const container = document.getElementById('sync-settings-list');

    container.querySelectorAll('.sync-row').forEach(row => {
        const toggle = row.querySelector('.toggle');
        const select = row.querySelector('.interval-select');
        const type = toggle.dataset.type;

        settings[type] = {
            enabled: toggle.checked,
            interval: parseInt(select.value, 10),
        };

        // Update the description text
        const desc = row.querySelector('.sync-row-desc');
        desc.textContent = `Every ${formatInterval(settings[type].interval)}`;
    });

    await sendMessage('saveSyncSettings', settings);
}

// ─── Devices ────────────────────────────────────────────────────────────

/**
 * Load and display connected devices from the server.
 */
async function loadDevices() {
    const container = document.getElementById('devices-list');

    try {
        const profile = await sendMessage('getProfile');
        const devices = profile.devices || [];
        const state = await sendMessage('getState');
        const currentDeviceId = state.device?.id;

        if (devices.length === 0) {
            container.innerHTML = '<div class="empty-state">No devices connected.</div>';
            return;
        }

        container.innerHTML = '';
        for (const device of devices) {
            const isCurrent = device.id === currentDeviceId;
            const icon = device.type === 'mobile' ? '📱' : device.type === 'tablet' ? '📟' : '🖥️';
            const lastSync = device.last_sync_at ? formatTime(device.last_sync_at) : 'Never';

            const row = document.createElement('div');
            row.className = 'device-row';
            row.innerHTML = `
                <div class="device-row-left">
                    <span class="device-icon">${icon}</span>
                    <div>
                        <div class="device-name">${device.name || 'Unknown'}${isCurrent ? ' <span class="device-badge">This device</span>' : ''}</div>
                        <div class="device-meta">Last synced: ${lastSync}</div>
                    </div>
                </div>
            `;
            container.appendChild(row);
        }
    } catch (err) {
        container.innerHTML = `<div class="empty-state">Could not load devices: ${err.message}</div>`;
    }
}

// ─── QR Pairing ─────────────────────────────────────────────────────────

/**
 * Generate a QR code for device pairing.
 */
async function generatePairingQR() {
    const btn = document.getElementById('generate-qr-btn');
    btn.textContent = 'Generating...';
    btn.disabled = true;

    try {
        const result = await sendMessage('generatePairingToken');

        if (result.error) throw new Error(result.error);

        const pairingToken = result.pairing_token;
        const pairingUrl = result.pairing_url;
        const expiresAt = new Date(result.expires_at);

        // Show pairing info
        document.getElementById('qr-container').classList.add('hidden');
        const pairInfo = document.getElementById('pair-info');
        pairInfo.classList.remove('hidden');

        // Display the code
        document.getElementById('pair-code-display').textContent = pairingToken;

        // Copy button
        const copyBtn = document.getElementById('copy-code-btn');
        copyBtn.onclick = async () => {
            await navigator.clipboard.writeText(pairingToken);
            copyBtn.textContent = 'Copied!';
            setTimeout(() => { copyBtn.textContent = 'Copy'; }, 2000);
        };

        // Generate QR code
        const qrWrap = document.getElementById('qr-canvas-wrap');
        qrWrap.innerHTML = '';

        if (typeof QRCode !== 'undefined') {
            new QRCode(qrWrap, {
                text: pairingUrl,
                width: 200,
                height: 200,
                colorDark: '#111827',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M,
            });
        } else {
            // Fallback if QRCode lib not loaded
            qrWrap.innerHTML = `<div style="padding:20px;text-align:center;color:#9ca3af;font-size:12px;">QR library not available.<br>Share the code manually.</div>`;
        }

        // Start countdown timer
        startPairingTimer(expiresAt);

    } catch (err) {
        btn.textContent = 'Generate QR Code';
        btn.disabled = false;
        alert('Failed to generate pairing token: ' + err.message);
    }
}

/**
 * Start a countdown timer for the pairing token expiry.
 */
function startPairingTimer(expiresAt) {
    if (pairingTimer) clearInterval(pairingTimer);

    const timerEl = document.getElementById('pair-timer');

    pairingTimer = setInterval(() => {
        const remaining = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));

        if (remaining <= 0) {
            clearInterval(pairingTimer);
            timerEl.textContent = 'Expired';
            // Reset to generate button
            setTimeout(() => {
                document.getElementById('pair-info').classList.add('hidden');
                document.getElementById('qr-container').classList.remove('hidden');
                const btn = document.getElementById('generate-qr-btn');
                btn.textContent = 'Generate QR Code';
                btn.disabled = false;
            }, 2000);
            return;
        }

        const mins = Math.floor(remaining / 60);
        const secs = remaining % 60;
        timerEl.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
    }, 1000);
}

// ─── Logout ─────────────────────────────────────────────────────────────

async function handleLogout() {
    if (!confirm('Are you sure you want to sign out?')) return;

    await sendMessage('logout');
    document.getElementById('not-logged-in').classList.remove('hidden');
    document.getElementById('sync-settings').classList.add('hidden');
    document.getElementById('devices-section').classList.add('hidden');
    document.getElementById('pair-section').classList.add('hidden');
    document.getElementById('badge-name').textContent = 'Not signed in';
}

// ─── Utilities ──────────────────────────────────────────────────────────

function sendMessage(type, data = {}) {
    return browser.runtime.sendMessage({ type, data });
}

function formatInterval(mins) {
    if (mins < 60) return `${mins} min`;
    if (mins === 60) return '1 hour';
    if (mins < 1440) return `${mins / 60} hours`;
    return '24 hours';
}

function formatTime(isoString) {
    const date = new Date(isoString);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);

    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return date.toLocaleDateString();
}
