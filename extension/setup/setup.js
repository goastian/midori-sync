/**
 * Midori Sync — Seed Phrase Setup Page
 *
 * Handles generation and recovery of the 12-word mnemonic seed phrase.
 * The encryption key is derived client-side from the seed phrase using Argon2id.
 * The server NEVER receives the key or the seed phrase.
 */

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => document.querySelectorAll(sel);

// ─── Step Navigation ────────────────────────────────────────────────────

function showStep(name) {
    $$('.step').forEach(s => s.classList.add('hidden'));
    $(`#step-${name}`).classList.remove('hidden');
}

// ─── Generate Seed Phrase ───────────────────────────────────────────────

let generatedMnemonic = null;

$('#btn-generate').addEventListener('click', async () => {
    try {
        const result = await browser.runtime.sendMessage({ type: 'generateSeedPhrase' });
        if (result.error) throw new Error(result.error);

        generatedMnemonic = result.mnemonic;
        renderSeedGrid(result.mnemonic);
        showStep('show');
    } catch (e) {
        alert('Failed to generate seed phrase: ' + e.message);
    }
});

function renderSeedGrid(mnemonic) {
    const grid = $('#seed-grid');
    grid.innerHTML = '';
    const words = mnemonic.split(' ');
    words.forEach((word, i) => {
        const cell = document.createElement('div');
        cell.className = 'seed-cell';
        cell.innerHTML = `<span class="seed-num">${i + 1}</span><span class="seed-word">${word}</span>`;
        grid.appendChild(cell);
    });
}

// Copy button
$('#btn-copy').addEventListener('click', () => {
    if (!generatedMnemonic) return;
    navigator.clipboard.writeText(generatedMnemonic).then(() => {
        const btn = $('#btn-copy');
        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Copied!';
        setTimeout(() => {
            btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Copy';
        }, 2000);
    });
});

// Confirm checkbox enables the save button
$('#chk-confirm').addEventListener('change', (e) => {
    $('#btn-confirm').disabled = !e.target.checked;
});

// Confirm: finalize setup
$('#btn-confirm').addEventListener('click', async () => {
    if (!generatedMnemonic) return;
    try {
        const result = await browser.runtime.sendMessage({
            type: 'completeSeedPhraseSetup',
            data: { mnemonic: generatedMnemonic },
        });
        if (result.error) throw new Error(result.error);
        showStep('done');
    } catch (e) {
        alert('Setup failed: ' + e.message);
    }
});

// ─── Recover With Seed Phrase ───────────────────────────────────────────

$('#btn-recover').addEventListener('click', () => {
    renderRecoverGrid();
    showStep('recover');
});

$('#btn-back-recover').addEventListener('click', () => showStep('choose'));

function renderRecoverGrid() {
    const grid = $('#recover-grid');
    grid.innerHTML = '';
    for (let i = 0; i < 12; i++) {
        const cell = document.createElement('div');
        cell.className = 'seed-cell input-cell';
        cell.innerHTML = `<span class="seed-num">${i + 1}</span><input type="text" class="seed-input" data-index="${i}" autocomplete="off" spellcheck="false">`;
        grid.appendChild(cell);
    }

    // Enable submit when all 12 fields have a word
    grid.querySelectorAll('.seed-input').forEach(input => {
        input.addEventListener('input', validateRecoverInputs);
        // Auto-advance on space
        input.addEventListener('keydown', (e) => {
            if (e.key === ' ' || e.key === 'Tab') {
                e.preventDefault();
                const idx = parseInt(e.target.dataset.index);
                const next = grid.querySelector(`[data-index="${idx + 1}"]`);
                if (next) next.focus();
            }
        });
    });

    // Focus first input
    grid.querySelector('.seed-input')?.focus();
}

function validateRecoverInputs() {
    const inputs = $$('.seed-input');
    const allFilled = Array.from(inputs).every(i => i.value.trim().length > 0);
    $('#btn-submit-recover').disabled = !allFilled;
    $('#recover-error').classList.add('hidden');
}

// Handle paste of full mnemonic
$('#recover-grid')?.addEventListener('paste', (e) => {
    // Only handle global paste (not on individual input)
    if (e.target.classList.contains('seed-input') && e.target.dataset.index === '0') {
        const pasted = (e.clipboardData || window.clipboardData).getData('text').trim();
        const words = pasted.split(/\s+/);
        if (words.length === 12) {
            e.preventDefault();
            const inputs = $$('.seed-input');
            words.forEach((word, i) => {
                if (inputs[i]) inputs[i].value = word.toLowerCase();
            });
            validateRecoverInputs();
        }
    }
});

$('#btn-submit-recover').addEventListener('click', async () => {
    const inputs = $$('.seed-input');
    const words = Array.from(inputs).map(i => i.value.trim().toLowerCase());
    const mnemonic = words.join(' ');

    const btn = $('#btn-submit-recover');
    btn.disabled = true;
    btn.textContent = 'Recovering...';

    try {
        const result = await browser.runtime.sendMessage({
            type: 'recoverWithSeedPhrase',
            data: { mnemonic },
        });
        if (result.error) throw new Error(result.error);
        showStep('done');
    } catch (e) {
        $('#recover-error').textContent = e.message || 'Invalid seed phrase';
        $('#recover-error').classList.remove('hidden');
        btn.disabled = false;
        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Recover';
    }
});
