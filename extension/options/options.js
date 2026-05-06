/**
 * Midori Sync — Options Page Script (Simplified)
 */

const $ = (sel) => document.querySelector(sel);

const ALL_COLLECTIONS = [
    { id: 'bookmarks', name: 'Bookmarks', desc: 'Bookmark folders and links' },
    { id: 'history', name: 'History', desc: 'Browsing history' },
    { id: 'open-tabs', name: 'Open Tabs', desc: 'Currently open tabs' },
    { id: 'browser-settings', name: 'Browser Settings', desc: 'Homepage, new tab page' },
    { id: 'midori-tab', name: 'Midori Tab', desc: 'Widgets, themes, shortcuts' },
    { id: 'midori-privacy', name: 'Midori Privacy', desc: 'Filter lists, site toggles' },
];

function showToast(message) {
    const toast = $('#toast');
    toast.textContent = message;
    toast.classList.remove('hidden');
    setTimeout(() => toast.classList.add('hidden'), 2500);
}

function showSection(name) {
    $('#section-login').classList.add('hidden');
    $('#section-settings').classList.add('hidden');
    $(`#section-${name}`).classList.remove('hidden');
}

function renderCollections(syncSettings) {
    const container = $('#collections-list');
    container.innerHTML = '';

    ALL_COLLECTIONS.forEach(col => {
        const enabled = syncSettings[col.id]?.enabled ?? true;

        const item = document.createElement('div');
        item.className = 'collection-item';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = `col-${col.id}`;
        checkbox.checked = enabled;
        checkbox.addEventListener('change', () => onCollectionToggle());

        const labelWrap = document.createElement('div');

        const label = document.createElement('label');
        label.htmlFor = `col-${col.id}`;
        label.textContent = col.name;

        const desc = document.createElement('div');
        desc.className = 'collection-desc';
        desc.textContent = col.desc;

        labelWrap.appendChild(label);
        labelWrap.appendChild(desc);
        item.appendChild(checkbox);
        item.appendChild(labelWrap);
        container.appendChild(item);
    });
}

async function onCollectionToggle() {
    const state = await browser.runtime.sendMessage({ type: 'getState' });
    const settings = state.syncSettings || {};

    ALL_COLLECTIONS.forEach(col => {
        if (!settings[col.id]) settings[col.id] = {};
        settings[col.id].enabled = $(`#col-${col.id}`).checked;
    });

    await browser.runtime.sendMessage({ type: 'saveSyncSettings', data: settings });
    showToast('Collections updated');
}

async function loadConfig() {
    const state = await browser.runtime.sendMessage({ type: 'getState' });

    if (!state.isLoggedIn) {
        showSection('login');
        return;
    }

    showSection('settings');
    $('#info-name').textContent = state.user?.name || '—';
    $('#info-email').textContent = state.user?.email || '—';
    $('#info-device').textContent = state.device?.name || '—';
    $('#input-server').value = state.serverUrl || '';

    renderCollections(state.syncSettings || {});
    loadSeedPhraseStatus();
}

// Save server URL
$('#btn-save-server').addEventListener('click', async () => {
    const url = $('#input-server').value.trim().replace(/\/+$/, '');
    if (!url) return;
    await browser.runtime.sendMessage({ type: 'updateServerUrl', data: { serverUrl: url } });
    showToast('Server URL updated');
});

// Logout
$('#btn-logout').addEventListener('click', async () => {
    if (!confirm('Are you sure you want to sign out?')) return;
    await browser.runtime.sendMessage({ type: 'logout' });
    showToast('Signed out');
    await loadConfig();
});

// ─── Seed Phrase Section ────────────────────────────────────────────────

let seedPhraseRevealed = false;
let cachedSeedPhrase = null;

async function loadSeedPhraseStatus() {
    try {
        const result = await browser.runtime.sendMessage({ type: 'getSeedPhrase' });
        const statusEl = $('#encryption-status');

        if (result.mnemonic) {
            statusEl.textContent = '✓ Active';
            statusEl.style.color = 'var(--color-success)';
            cachedSeedPhrase = result.mnemonic;
            renderSeedGrid(result.mnemonic);
            $('#seed-phrase-viewer').classList.remove('hidden');
            $('#seed-phrase-setup').classList.add('hidden');
            $('#seed-phrase-recover').classList.remove('hidden');
        } else {
            statusEl.textContent = '✗ Not configured';
            statusEl.style.color = 'var(--color-error)';
            $('#seed-phrase-viewer').classList.add('hidden');
            $('#seed-phrase-setup').classList.remove('hidden');
            $('#seed-phrase-recover').classList.add('hidden');
        }
    } catch (err) {
        $('#encryption-status').textContent = 'Error';
    }
}

function renderSeedGrid(mnemonic) {
    const grid = $('#seed-grid');
    grid.innerHTML = '';
    const words = mnemonic.split(' ');
    words.forEach((word, i) => {
        const el = document.createElement('div');
        el.className = 'seed-word';
        el.innerHTML = `<span class="word-num">${i + 1}.</span>${word}`;
        grid.appendChild(el);
    });
}

// Reveal / hide
$('#btn-reveal-seed')?.addEventListener('click', () => {
    const grid = $('#seed-grid');
    seedPhraseRevealed = !seedPhraseRevealed;
    if (seedPhraseRevealed) {
        grid.classList.remove('blurred');
        $('#btn-reveal-seed').textContent = '🙈 Hide';
    } else {
        grid.classList.add('blurred');
        $('#btn-reveal-seed').textContent = '👁 Reveal';
    }
});

// Copy
$('#btn-copy-seed')?.addEventListener('click', async () => {
    if (cachedSeedPhrase) {
        await navigator.clipboard.writeText(cachedSeedPhrase);
        showToast('Seed phrase copied to clipboard');
    }
});

// Setup seed phrase — open setup page
$('#btn-setup-seed')?.addEventListener('click', () => {
    browser.tabs.create({ url: browser.runtime.getURL('setup/setup.html') });
});

// Recover with another seed phrase — open setup page
$('#btn-recover-seed')?.addEventListener('click', () => {
    browser.tabs.create({ url: browser.runtime.getURL('setup/setup.html') });
});

// ─── Local Lock Section ─────────────────────────────────────────────────

async function loadLockStatus() {
    try {
        const status = await browser.runtime.sendMessage({ type: 'getLockStatus' });
        const statusEl = $('#lock-status');
        if (!status) return;
        if (status.hasPassphrase) {
            statusEl.textContent = status.locked
                ? `🔒 Locked (auto-locks after ${status.timeoutMinutes ?? 15} min idle)`
                : `🔓 Unlocked (auto-locks after ${status.timeoutMinutes ?? 15} min idle)`;
            statusEl.style.color = 'var(--color-success)';
            $('#lock-enable-form').classList.add('hidden');
            $('#lock-manage-form').classList.remove('hidden');
        } else {
            statusEl.textContent = 'Not enabled';
            statusEl.style.color = 'var(--color-muted, #888)';
            $('#lock-enable-form').classList.remove('hidden');
            $('#lock-manage-form').classList.add('hidden');
        }
    } catch (_) {
        $('#lock-status').textContent = 'Error';
    }
}

$('#btn-enable-lock')?.addEventListener('click', async () => {
    const pw = $('#lock-new-passphrase').value;
    const confirm = $('#lock-confirm-passphrase').value;
    const timeout = parseInt($('#lock-timeout').value, 10);
    if (!pw || pw.length < 8) return showToast('Passphrase must be at least 8 characters');
    if (pw !== confirm) return showToast('Passphrases do not match');
    try {
        await browser.runtime.sendMessage({
            type: 'enableLocalPassphrase',
            data: { passphrase: pw, timeoutMinutes: timeout },
        });
        $('#lock-new-passphrase').value = '';
        $('#lock-confirm-passphrase').value = '';
        showToast('Local lock enabled');
        await loadLockStatus();
    } catch (e) {
        showToast(`Failed: ${e?.message || e}`);
    }
});

$('#btn-lock-now')?.addEventListener('click', async () => {
    try {
        await browser.runtime.sendMessage({ type: 'lockEncryption' });
        showToast('Vault locked');
        await loadLockStatus();
    } catch (e) {
        showToast(`Failed: ${e?.message || e}`);
    }
});

$('#btn-disable-lock')?.addEventListener('click', async () => {
    const pw = $('#lock-disable-passphrase').value;
    if (!pw) return showToast('Enter your current passphrase');
    try {
        await browser.runtime.sendMessage({
            type: 'disableLocalPassphrase',
            data: { passphrase: pw },
        });
        $('#lock-disable-passphrase').value = '';
        showToast('Local lock disabled');
        await loadLockStatus();
    } catch (e) {
        showToast(`Failed: ${e?.message || e}`);
    }
});

// ─── Master Key Rotation ────────────────────────────────────────────────

let pendingRotationMnemonic = null;
let rotationPollTimer = null;

function showRotationPanel(panel) {
    ['rotation-idle', 'rotation-confirm', 'rotation-progress'].forEach(id => {
        const el = $(`#${id}`);
        if (!el) return;
        if (id === panel) el.classList.remove('hidden');
        else el.classList.add('hidden');
    });
}

function renderRotationMnemonic(mnemonic) {
    const grid = $('#rotation-new-grid');
    grid.innerHTML = '';
    mnemonic.split(' ').forEach((word, i) => {
        const el = document.createElement('div');
        el.className = 'seed-word';
        el.innerHTML = `<span class="word-num">${i + 1}.</span>${word}`;
        grid.appendChild(el);
    });
}

function renderRotationProgress(status) {
    const total = status.total || 0;
    const done = (status.completed || []).length;
    $('#rotation-progress-text').textContent = `${done} / ${total} collections re-encrypted`;
    $('#rotation-progress-current').textContent = status.currentCollection || (status.inProgress ? 'starting…' : 'idle');
    const errEl = $('#rotation-progress-error');
    if (status.error) {
        errEl.textContent = status.error;
        errEl.classList.remove('hidden');
    } else {
        errEl.classList.add('hidden');
    }
    // Show resume/abort when stalled.
    const resumeBtn = $('#btn-rotation-resume');
    const abortBtn = $('#btn-rotation-abort');
    const stalled = !status.inProgress && status.hasPreviousKey;
    resumeBtn.classList.toggle('hidden', !stalled);
    abortBtn.classList.toggle('hidden', !stalled);
}

async function pollRotationStatus() {
    try {
        const status = await browser.runtime.sendMessage({ type: 'getRotationStatus' });
        if (!status) return;
        if (status.inProgress || status.hasPreviousKey) {
            showRotationPanel('rotation-progress');
            renderRotationProgress(status);
        } else {
            showRotationPanel('rotation-idle');
            stopRotationPolling();
        }
    } catch (_) { /* ignore transient errors */ }
}

function startRotationPolling() {
    stopRotationPolling();
    rotationPollTimer = setInterval(pollRotationStatus, 1500);
}

function stopRotationPolling() {
    if (rotationPollTimer) {
        clearInterval(rotationPollTimer);
        rotationPollTimer = null;
    }
}

$('#btn-rotation-preview')?.addEventListener('click', async () => {
    try {
        const result = await browser.runtime.sendMessage({ type: 'previewKeyRotation' });
        if (!result?.mnemonic) throw new Error('No mnemonic returned');
        pendingRotationMnemonic = result.mnemonic;
        renderRotationMnemonic(result.mnemonic);
        showRotationPanel('rotation-confirm');
    } catch (e) {
        showToast(`Failed: ${e?.message || e}`);
    }
});

$('#btn-rotation-cancel')?.addEventListener('click', () => {
    pendingRotationMnemonic = null;
    showRotationPanel('rotation-idle');
});

$('#btn-rotation-copy')?.addEventListener('click', async () => {
    if (pendingRotationMnemonic) {
        await navigator.clipboard.writeText(pendingRotationMnemonic);
        showToast('New seed phrase copied');
    }
});

$('#btn-rotation-start')?.addEventListener('click', async () => {
    if (!pendingRotationMnemonic) return;
    if (!confirm('Start re-encrypting all server data with the new key? This may take a while.')) return;
    showRotationPanel('rotation-progress');
    renderRotationProgress({ total: 7, completed: [], currentCollection: 'starting…', inProgress: true });
    startRotationPolling();
    try {
        const result = await browser.runtime.sendMessage({
            type: 'performKeyRotation',
            data: { newMnemonic: pendingRotationMnemonic },
        });
        pendingRotationMnemonic = null;
        if (result?.success) {
            showToast('Master key rotation complete');
            stopRotationPolling();
            showRotationPanel('rotation-idle');
            await loadSeedPhraseStatus();
        } else {
            showToast(`Rotation paused: ${result?.error || 'unknown error'}`);
            await pollRotationStatus();
        }
    } catch (e) {
        showToast(`Rotation failed: ${e?.message || e}`);
        await pollRotationStatus();
    }
});

$('#btn-rotation-resume')?.addEventListener('click', async () => {
    startRotationPolling();
    try {
        const result = await browser.runtime.sendMessage({
            type: 'performKeyRotation',
            data: { resume: true },
        });
        if (result?.success) {
            showToast('Master key rotation complete');
            stopRotationPolling();
            showRotationPanel('rotation-idle');
            await loadSeedPhraseStatus();
        } else {
            showToast(`Still paused: ${result?.error || 'unknown error'}`);
            await pollRotationStatus();
        }
    } catch (e) {
        showToast(`Resume failed: ${e?.message || e}`);
        await pollRotationStatus();
    }
});

$('#btn-rotation-abort')?.addEventListener('click', async () => {
    if (!confirm('Abort the rotation? Server-side data may stay partially rotated; reads keep working but you should run a rotation again later.')) return;
    try {
        await browser.runtime.sendMessage({ type: 'abortKeyRotation' });
        showToast('Rotation aborted');
        await pollRotationStatus();
    } catch (e) {
        showToast(`Abort failed: ${e?.message || e}`);
    }
});

// Load lock + rotation status alongside the rest of the config.
const _origLoadConfig = loadConfig;
loadConfig = async function() {
    await _origLoadConfig();
    await loadLockStatus();
    await pollRotationStatus();
};

loadConfig();
