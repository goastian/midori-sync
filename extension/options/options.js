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

loadConfig();
