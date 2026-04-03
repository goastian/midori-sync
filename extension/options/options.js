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
        document.getElementById('encryption-section').classList.add('hidden');
        document.getElementById('passwords-section').classList.add('hidden');
        return;
    }

    document.getElementById('badge-name').textContent = state.user?.name || 'User';
    document.getElementById('server-url-display').textContent = state.serverUrl || '—';
    document.getElementById('user-uid-display').textContent = state.user?.uid || '—';
    document.getElementById('device-name-display').textContent = state.device?.name || '—';

    renderSyncSettings(state.syncTypes, state.syncSettings);
    loadDevices();
    loadEncryptionInfo();
    loadPasswords();

    // Event listeners
    document.getElementById('generate-qr-btn').addEventListener('click', generatePairingQR);
    document.getElementById('logout-btn').addEventListener('click', handleLogout);
    document.getElementById('export-key-btn').addEventListener('click', handleExportKey);
    document.getElementById('import-key-btn').addEventListener('click', handleImportKey);
    setupPasswordForm();
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

        // Show encryption key if available
        const encKeyBox = document.getElementById('pair-enc-key-box');
        const encKeyDisplay = document.getElementById('pair-enc-key-display');
        const copyEncKeyBtn = document.getElementById('copy-enc-key-btn');
        if (result.encryption_key_b64) {
            encKeyDisplay.textContent = result.encryption_key_b64;
            encKeyBox.style.display = '';
            copyEncKeyBtn.onclick = async () => {
                await navigator.clipboard.writeText(result.encryption_key_b64);
                copyEncKeyBtn.textContent = 'Copied!';
                setTimeout(() => { copyEncKeyBtn.textContent = 'Copy'; }, 2000);
            };
        } else {
            encKeyBox.style.display = 'none';
        }

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

// ─── Encryption ─────────────────────────────────────────────────────

/**
 * Load and display the encryption key fingerprint (SHA-256, first 8 bytes as hex).
 */
async function loadEncryptionInfo() {
    const fingerprintEl = document.getElementById('enc-fingerprint');
    try {
        const result = await sendMessage('exportEncryptionKey');
        if (result?.key) {
            const raw = Uint8Array.from(atob(result.key), c => c.charCodeAt(0));
            const hash = await crypto.subtle.digest('SHA-256', raw);
            const hex = Array.from(new Uint8Array(hash).slice(0, 8))
                .map(b => b.toString(16).padStart(2, '0'))
                .join(':');
            fingerprintEl.textContent = hex.toUpperCase();
        } else {
            fingerprintEl.textContent = 'Not available';
        }
    } catch {
        fingerprintEl.textContent = 'Error loading key';
    }
}

/**
 * Copy the raw base64 encryption key to the clipboard.
 */
async function handleExportKey() {
    try {
        const result = await sendMessage('exportEncryptionKey');
        if (!result?.key) throw new Error('Key not available');
        await navigator.clipboard.writeText(result.key);
        const btn = document.getElementById('export-key-btn');
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = 'Export Key'; }, 2000);
    } catch (err) {
        alert('Export failed: ' + err.message);
    }
}

/**
 * Import an encryption key from the text field and schedule re-encryption.
 */
async function handleImportKey() {
    const input = document.getElementById('import-key-input');
    const msgEl = document.getElementById('import-key-msg');
    msgEl.classList.add('hidden');

    const keyBase64 = input.value.trim();
    if (!keyBase64) {
        msgEl.textContent = 'Please paste a base64 key first.';
        msgEl.style.color = '#ef4444';
        msgEl.classList.remove('hidden');
        return;
    }

    try {
        const result = await sendMessage('importEncryptionKey', { keyBase64 });
        if (result.error) throw new Error(result.error);
        input.value = '';
        msgEl.textContent = '✓ Key imported. Existing data will be re-encrypted on next startup.';
        msgEl.style.color = '#22c55e';
        msgEl.classList.remove('hidden');
        loadEncryptionInfo();
    } catch (err) {
        msgEl.textContent = '✗ Import failed: ' + err.message;
        msgEl.style.color = '#ef4444';
        msgEl.classList.remove('hidden');
    }
}

// ─── Passwords ──────────────────────────────────────────────────────────

/**
 * Load passwords from background and render the list.
 */
async function loadPasswords() {
    try {
        const passwords = await sendMessage('getPasswords');
        renderPasswords(passwords);
    } catch (err) {
        document.getElementById('passwords-list').innerHTML =
            `<div class="empty-state">Could not load passwords: ${err.message}</div>`;
    }
}

/**
 * Render the password list rows.
 */
function renderPasswords(passwords) {
    const container = document.getElementById('passwords-list');
    container.innerHTML = '';

    if (!passwords || passwords.length === 0) {
        container.innerHTML = '<div class="empty-state">No saved credentials yet. Add one above.</div>';
        return;
    }

    // Sort by site name
    const sorted = [...passwords].sort((a, b) => (a.site || '').localeCompare(b.site || ''));

    for (const entry of sorted) {
        const domain = (() => {
            try { return new URL(entry.site).hostname; } catch { return entry.site; }
        })();

        const row = document.createElement('div');
        row.className = 'pw-row';
        row.innerHTML = `
            <div class="pw-row-left">
                <div class="pw-favicon">
                    <img src="https://www.google.com/s2/favicons?sz=32&domain=${encodeURIComponent(domain)}"
                         alt="" width="20" height="20" onerror="this.style.display='none'">
                </div>
                <div class="pw-row-info">
                    <div class="pw-row-site">${escapeHtml(domain)}</div>
                    <div class="pw-row-username">${escapeHtml(entry.username)}</div>
                    ${entry.notes ? `<div class="pw-row-notes">${escapeHtml(entry.notes)}</div>` : ''}
                </div>
            </div>
            <div class="pw-row-actions">
                <button class="btn btn-sm btn-secondary pw-copy-btn" data-id="${escapeHtml(entry.id)}" title="Copy password">Copy</button>
                <button class="btn btn-sm btn-secondary pw-edit-btn" data-id="${escapeHtml(entry.id)}" title="Edit">Edit</button>
                <button class="btn btn-sm btn-danger pw-delete-btn" data-id="${escapeHtml(entry.id)}" title="Delete">✕</button>
            </div>
        `;
        container.appendChild(row);
    }

    // Attach handlers
    container.querySelectorAll('.pw-copy-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const entry = passwords.find(p => p.id === btn.dataset.id);
            if (!entry) return;
            await navigator.clipboard.writeText(entry.password);
            btn.textContent = 'Copied!';
            setTimeout(() => { btn.textContent = 'Copy'; }, 2000);
        });
    });

    container.querySelectorAll('.pw-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const entry = passwords.find(p => p.id === btn.dataset.id);
            if (!entry) return;
            startEditPassword(entry);
        });
    });

    container.querySelectorAll('.pw-delete-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm(`Delete credentials for ${passwords.find(p => p.id === btn.dataset.id)?.site}?`)) return;
            try {
                await sendMessage('deletePassword', { id: btn.dataset.id });
                loadPasswords();
            } catch (err) {
                alert('Delete failed: ' + err.message);
            }
        });
    });
}

/**
 * Fill the form for editing an existing entry.
 */
function startEditPassword(entry) {
    document.getElementById('password-form-title').textContent = 'Edit Credential';
    document.getElementById('password-edit-id').value = entry.id;
    document.getElementById('pw-site').value = entry.site;
    document.getElementById('pw-username').value = entry.username;
    document.getElementById('pw-password').value = entry.password;
    document.getElementById('pw-notes').value = entry.notes || '';
    document.getElementById('pw-cancel-btn').classList.remove('hidden');
    document.getElementById('pw-site').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/**
 * Reset the form to "add new" state.
 */
function resetPasswordForm() {
    document.getElementById('password-form-title').textContent = 'Add New Credential';
    document.getElementById('password-edit-id').value = '';
    document.getElementById('pw-site').value = '';
    document.getElementById('pw-username').value = '';
    document.getElementById('pw-password').value = '';
    document.getElementById('pw-notes').value = '';
    document.getElementById('pw-cancel-btn').classList.add('hidden');
    document.getElementById('password-form-error').classList.add('hidden');
}

/**
 * Wire up form event listeners.
 */
function setupPasswordForm() {
    // Toggle password visibility
    document.getElementById('pw-toggle-visibility').addEventListener('click', () => {
        const input = document.getElementById('pw-password');
        input.type = input.type === 'password' ? 'text' : 'password';
    });

    // Save button
    document.getElementById('pw-save-btn').addEventListener('click', async () => {
        const id = document.getElementById('password-edit-id').value || null;
        const site = document.getElementById('pw-site').value.trim();
        const username = document.getElementById('pw-username').value.trim();
        const password = document.getElementById('pw-password').value;
        const notes = document.getElementById('pw-notes').value.trim();
        const errEl = document.getElementById('password-form-error');

        if (!site || !username || !password) {
            errEl.textContent = 'Website, username and password are required.';
            errEl.classList.remove('hidden');
            return;
        }
        errEl.classList.add('hidden');

        const btn = document.getElementById('pw-save-btn');
        btn.disabled = true;
        btn.textContent = 'Saving…';

        try {
            await sendMessage('savePassword', { id, site, username, password, notes });
            resetPasswordForm();
            loadPasswords();
        } catch (err) {
            errEl.textContent = 'Save failed: ' + err.message;
            errEl.classList.remove('hidden');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Save';
        }
    });

    // Cancel / reset to add-new
    document.getElementById('pw-cancel-btn').addEventListener('click', resetPasswordForm);
}

/**
 * Escape HTML special characters to prevent XSS in innerHTML.
 */
function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
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
