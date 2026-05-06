/**
 * Midori Sync — Background Service Worker
 *
 * Handles authentication, sync scheduling, and data synchronization.
 * Simplified flow: no manual server URL, passphrase, or device name required.
 */

const DEFAULT_SERVER = 'http://localhost:8000';
// Production: const DEFAULT_SERVER = 'https://sync.astian.org';

const SYNC_TYPES = {
    bookmarks: { label: 'Bookmarks', interval: 15, enabled: true },
    history: { label: 'History', interval: 30, enabled: true },
    tabs: { label: 'Open Tabs', interval: 5, enabled: true },
    passwords: { label: 'Passwords', interval: 60, enabled: true },
};

// ─── State ──────────────────────────────────────────────────────────────

let authState = {
    token: null,
    user: null,
    device: null,
    serverUrl: DEFAULT_SERVER,
};

let lastSyncTimes = { bookmarks: 0, history: 0, tabs: 0, passwords: 0 };
let syncTimeouts = {};
let encryptionKey = null;

// Set ONLY while a master-key rotation is in progress (or has been
// interrupted). When non-null, decryption falls back to it after the
// active key fails — this is what makes a rotation crash-safe across
// browser restarts. See the "Master key rotation" section below.
let previousEncryptionKey = null;

const MidoriSyncCryptoClass = globalThis.MidoriSyncCrypto;
if (!MidoriSyncCryptoClass) {
    throw new Error('MidoriSyncCrypto library is not loaded');
}

const crypto_ = new MidoriSyncCryptoClass();

// ─── Encryption Key Management ──────────────────────────────────────────
// Keys NEVER leave the client. A 12-word BIP39 mnemonic seed phrase is
// generated client-side. The 32-byte encryption key is derived from it
// via Argon2id. The server only stores encrypted payloads.

// Fixed salt for Argon2id key derivation (16 bytes = "MidoriSync_v1_KD")
const SEED_KDF_SALT = new Uint8Array([77,105,100,111,114,105,83,121,110,99,95,118,49,95,75,68]);

/**
 * Load the encryption key from local storage. Does NOT auto-generate.
 * If no key exists, encryptionKey stays null and the UI must handle setup.
 */
async function initEncryptionKey() {
    await crypto_.init();
    const stored = await browser.storage.local.get('encryptionKey');
    if (stored.encryptionKey) {
        encryptionKey = crypto_.sodium.from_base64(stored.encryptionKey, crypto_.sodium.base64_variants.ORIGINAL);
    }
    // If no key, encryptionKey remains null — UI will prompt for seed phrase setup
}

/**
 * Generate a BIP39 12-word mnemonic from 128 bits of entropy.
 * Uses libsodium for secure random generation and SHA-256 for checksum.
 */
function generateMnemonic() {
    const entropy = crypto_.sodium.randombytes_buf(16); // 128 bits
    return entropyToMnemonic(entropy);
}

/**
 * Convert 16 bytes of entropy to a 12-word BIP39 mnemonic.
 */
function entropyToMnemonic(entropy) {
    // SHA-256 checksum
    const hash = crypto_.sodium.crypto_hash_sha256(entropy);
    const checksumBits = hash[0] >> 4; // first 4 bits

    // Combine entropy (128 bits) + checksum (4 bits) = 132 bits → 12 × 11
    const bits = [];
    for (let i = 0; i < entropy.length; i++) {
        for (let b = 7; b >= 0; b--) bits.push((entropy[i] >> b) & 1);
    }
    for (let b = 3; b >= 0; b--) bits.push((checksumBits >> b) & 1);

    const words = [];
    for (let i = 0; i < 12; i++) {
        let idx = 0;
        for (let b = 0; b < 11; b++) idx = (idx << 1) | bits[i * 11 + b];
        words.push(BIP39_WORDLIST[idx]);
    }
    return words.join(' ');
}

/**
 * Validate a mnemonic: 12 words, all in BIP39 list, checksum matches.
 */
function validateMnemonic(mnemonic) {
    const words = mnemonic.trim().toLowerCase().split(/\s+/);
    if (words.length !== 12) return false;

    const indices = words.map(w => BIP39_WORDLIST.indexOf(w));
    if (indices.some(i => i === -1)) return false;

    // Reconstruct bits
    const bits = [];
    for (const idx of indices) {
        for (let b = 10; b >= 0; b--) bits.push((idx >> b) & 1);
    }

    // Extract entropy (128 bits) and checksum (4 bits)
    const entropy = new Uint8Array(16);
    for (let i = 0; i < 16; i++) {
        let byte = 0;
        for (let b = 0; b < 8; b++) byte = (byte << 1) | bits[i * 8 + b];
        entropy[i] = byte;
    }

    let checksumBits = 0;
    for (let b = 0; b < 4; b++) checksumBits = (checksumBits << 1) | bits[128 + b];

    // Verify checksum
    const hash = crypto_.sodium.crypto_hash_sha256(entropy);
    const expectedChecksum = hash[0] >> 4;
    return checksumBits === expectedChecksum;
}

/**
 * Derive a 32-byte encryption key from a mnemonic using Argon2id.
 * Deterministic: same mnemonic always produces the same key.
 */
function deriveKeyFromMnemonic(mnemonic) {
    return crypto_.sodium.crypto_pwhash(
        32, // key length
        mnemonic,
        SEED_KDF_SALT,
        crypto_.sodium.crypto_pwhash_OPSLIMIT_INTERACTIVE,
        crypto_.sodium.crypto_pwhash_MEMLIMIT_INTERACTIVE,
        crypto_.sodium.crypto_pwhash_ALG_ARGON2ID13
    );
}

/**
 * Store the derived key and mnemonic locally. Server never sees either.
 */
async function setupEncryptionFromMnemonic(mnemonic) {
    await crypto_.init();
    const key = deriveKeyFromMnemonic(mnemonic);
    encryptionKey = key;
    const b64 = crypto_.sodium.to_base64(key, crypto_.sodium.base64_variants.ORIGINAL);
    await browser.storage.local.set({
        encryptionKey: b64,
        seedPhrase: mnemonic,
    });
    lastSyncTimes = { bookmarks: 0, history: 0, tabs: 0, passwords: 0 };
    await browser.storage.local.set({ lastSyncTimes });
    await browser.storage.local.remove('lastSync');
    console.log('[Midori Sync] Encryption key derived from seed phrase.');
}

// ─── Inactivity Lock & Optional Local Passphrase ────────────────────────
// By default the seed phrase + derived encryption key are stored in
// `browser.storage.local`. Two opt-in protections live on top of that:
//
//   1. Local passphrase: the user supplies a passphrase that wraps the
//      seed phrase + encryption key with XChaCha20-Poly1305. Plaintext
//      is removed from `browser.storage.local`; only the encrypted
//      bundle remains at rest. Unlocking requires the passphrase.
//
//   2. Inactivity lock: when a passphrase is set, the in-memory
//      `encryptionKey` is wiped after `LOCK_TIMEOUT_MIN` minutes
//      without a user action. The next user action must unlock first.
//
// Without a passphrase, neither protection is active — the existing
// behavior of "stored plaintext, never locks" is preserved verbatim so
// upgrades do not surprise current users.

const LOCK_STORAGE_KEY = 'localLockBundle';
const LOCK_ALARM_NAME = 'midori-sync-lock';
const DEFAULT_LOCK_TIMEOUT_MIN = 15;

let lockState = {
    hasPassphrase: false,    // is a local passphrase configured?
    locked: false,           // is the encryption key currently absent in memory because of a lock?
    timeoutMinutes: DEFAULT_LOCK_TIMEOUT_MIN,
};

// Plaintext seed phrase kept only in memory while the vault is unlocked
// AND a local passphrase is active. Never persisted to storage in that
// mode, so the user must re-unlock to view the seed again after a lock.
let seedPhraseInMemory = null;

function _lockKdfContext() {
    // 8-byte libsodium KDF context; distinct from MSPv1key so a wrapping
    // key derived for the local lock cannot collide with collection sub-keys.
    return 'MSPv1lck';
}

async function _deriveLockKey(passphrase, salt) {
    // Reuse the worker-aware derivation in MidoriSyncCrypto so the UI is
    // not blocked while Argon2id stretches the local passphrase.
    await crypto_.init();
    return crypto_.deriveKeys(passphrase, salt);
}

async function _readLockBundle() {
    const stored = await browser.storage.local.get(LOCK_STORAGE_KEY);
    return stored[LOCK_STORAGE_KEY] || null;
}

async function _writeLockBundle(bundle) {
    await browser.storage.local.set({ [LOCK_STORAGE_KEY]: bundle });
}

async function _clearLockBundle() {
    await browser.storage.local.remove(LOCK_STORAGE_KEY);
}

/**
 * Compute and persist the encrypted local bundle from the current
 * plaintext seed phrase + encryption key, wrapped under a key derived
 * from `passphrase`.
 */
async function _writeLockedSnapshot(passphrase) {
    await crypto_.init();
    const stored = await browser.storage.local.get(['seedPhrase', 'encryptionKey']);
    if (!stored.seedPhrase || !stored.encryptionKey) {
        throw new Error('Cannot enable lock: seed phrase is not set up');
    }
    const salt = crypto_.generateSalt();
    const wrapKey = await _deriveLockKey(passphrase, salt);
    const payload = JSON.stringify({
        seedPhrase: stored.seedPhrase,
        encryptionKey: stored.encryptionKey,
    });
    const bundle = crypto_.encrypt(payload, wrapKey);
    await _writeLockBundle({
        v: 1,
        salt: crypto_.sodium.to_base64(salt, crypto_.sodium.base64_variants.ORIGINAL),
        bundle,
        timeoutMinutes: lockState.timeoutMinutes,
    });
}

/**
 * Decrypt the stored bundle and return { seedPhrase, encryptionKey }.
 * Throws on bad passphrase.
 */
async function _readLockedSnapshot(passphrase) {
    await crypto_.init();
    const data = await _readLockBundle();
    if (!data) throw new Error('No local lock bundle stored');
    if (data.v !== 1) throw new Error(`Unsupported lock bundle version: ${data.v}`);
    const salt = crypto_.sodium.from_base64(data.salt, crypto_.sodium.base64_variants.ORIGINAL);
    const wrapKey = await _deriveLockKey(passphrase, salt);
    let plaintext;
    try {
        plaintext = crypto_.decrypt(data.bundle, wrapKey);
    } catch (e) {
        throw new Error('Invalid passphrase');
    }
    const parsed = JSON.parse(plaintext);
    return parsed;
}

async function _scheduleLockAlarm() {
    if (!lockState.hasPassphrase || lockState.locked) return;
    const minutes = Math.max(1, lockState.timeoutMinutes || DEFAULT_LOCK_TIMEOUT_MIN);
    try {
        await browser.alarms.clear(LOCK_ALARM_NAME);
        await browser.alarms.create(LOCK_ALARM_NAME, { delayInMinutes: minutes });
    } catch (e) {
        console.warn('[Midori Sync] Failed to schedule lock alarm:', e);
    }
}

async function _cancelLockAlarm() {
    try { await browser.alarms.clear(LOCK_ALARM_NAME); } catch (_) { /* ignore */ }
}

/**
 * Reset the inactivity timer because the user did something.
 * Cheap to call from every message handler.
 */
function noteUserActivity() {
    if (lockState.hasPassphrase && !lockState.locked) {
        _scheduleLockAlarm();
    }
}

async function _refreshLockStateFromStorage() {
    const bundle = await _readLockBundle();
    lockState.hasPassphrase = !!bundle;
    if (bundle && typeof bundle.timeoutMinutes === 'number') {
        lockState.timeoutMinutes = bundle.timeoutMinutes;
    }
    // If a bundle exists and the in-memory key is missing, we are locked.
    lockState.locked = !!bundle && !encryptionKey;
}

/**
 * Wipe the in-memory encryption key (and seed cache, if any).
 * Plaintext on disk is already absent when a passphrase is configured.
 */
async function lockEncryption() {
    if (!lockState.hasPassphrase) {
        return { success: false, reason: 'no-passphrase' };
    }
    encryptionKey = null;
    seedPhraseInMemory = null;
    lockState.locked = true;
    await _cancelLockAlarm();
    return { success: true };
}

/**
 * Unlock by re-deriving and rehydrating the in-memory encryption key
 * from the encrypted bundle on disk.
 */
async function unlockEncryption(passphrase) {
    await crypto_.init();
    const snapshot = await _readLockedSnapshot(passphrase);
    encryptionKey = crypto_.sodium.from_base64(snapshot.encryptionKey, crypto_.sodium.base64_variants.ORIGINAL);
    seedPhraseInMemory = snapshot.seedPhrase || null;
    lockState.locked = false;
    await _scheduleLockAlarm();
    return { success: true };
}

/**
 * Configure (or change) the local passphrase. After a successful call
 * the seed phrase + encryption key are no longer stored in plaintext.
 *
 * @param {{ passphrase: string, currentPassphrase?: string, timeoutMinutes?: number }} data
 */
async function enableLocalPassphrase(data) {
    const { passphrase, currentPassphrase, timeoutMinutes } = data || {};
    if (!passphrase || passphrase.length < 8) {
        throw new Error('Passphrase must be at least 8 characters');
    }

    // If a passphrase is already set, require the current one to rotate.
    const existing = await _readLockBundle();
    if (existing && !lockState.locked) {
        // Already unlocked — fine, we have the plaintext we need.
    } else if (existing && lockState.locked) {
        if (!currentPassphrase) throw new Error('Current passphrase required to change');
        const snap = await _readLockedSnapshot(currentPassphrase);
        // Rehydrate plaintext briefly so we can re-wrap it under the new passphrase.
        await browser.storage.local.set({
            seedPhrase: snap.seedPhrase,
            encryptionKey: snap.encryptionKey,
        });
        encryptionKey = crypto_.sodium.from_base64(snap.encryptionKey, crypto_.sodium.base64_variants.ORIGINAL);
        lockState.locked = false;
    }

    if (typeof timeoutMinutes === 'number' && timeoutMinutes >= 1) {
        lockState.timeoutMinutes = Math.floor(timeoutMinutes);
    }

    await _writeLockedSnapshot(passphrase);
    // Remove plaintext copies once the encrypted bundle is durable.
    await browser.storage.local.remove(['seedPhrase', 'encryptionKey']);
    lockState.hasPassphrase = true;
    lockState.locked = false;
    await _scheduleLockAlarm();
    return { success: true };
}

/**
 * Remove the local passphrase, restoring plaintext seed-phrase storage.
 * The current passphrase is required.
 */
async function disableLocalPassphrase(data) {
    const { passphrase } = data || {};
    if (!lockState.hasPassphrase) return { success: true, alreadyDisabled: true };
    if (!passphrase) throw new Error('Passphrase required to disable lock');
    const snap = await _readLockedSnapshot(passphrase);
    await browser.storage.local.set({
        seedPhrase: snap.seedPhrase,
        encryptionKey: snap.encryptionKey,
    });
    encryptionKey = crypto_.sodium.from_base64(snap.encryptionKey, crypto_.sodium.base64_variants.ORIGINAL);
    await _clearLockBundle();
    lockState.hasPassphrase = false;
    lockState.locked = false;
    await _cancelLockAlarm();
    return { success: true };
}

async function getLockStatus() {
    return {
        hasPassphrase: lockState.hasPassphrase,
        locked: lockState.locked,
        timeoutMinutes: lockState.timeoutMinutes,
    };
}

// Listen for the lock alarm and any other alarm wired elsewhere.
if (typeof browser !== 'undefined' && browser.alarms && browser.alarms.onAlarm) {
    browser.alarms.onAlarm.addListener((alarm) => {
        if (alarm && alarm.name === LOCK_ALARM_NAME) {
            lockEncryption().catch(e =>
                console.warn('[Midori Sync] Auto-lock failed:', e)
            );
        }
    });
}

// ─── Master Key Rotation ────────────────────────────────────────────────
// Implements the procedure documented in docs/encryption.md:
//   1. Caller previews a new mnemonic and confirms.
//   2. We derive M_new, stash M_old as `previousEncryptionKey`, and
//      persist a checkpoint to storage so a crash can resume.
//   3. For each rotatable collection we fetch every record, decrypt
//      under M_old (or M_new on resume), and re-upload using M_new as
//      the active encryption key. Per-collection cursors are
//      checkpointed after each chunk.
//   4. When all collections finish we swap the on-disk seed phrase +
//      encryption key to M_new and clear the previous key.
//
// Reads during rotation transparently fall back to M_old via
// `decryptBsoPayload`, which is why mixed-state stays functional.

const ROTATION_STORAGE_KEY = 'rotationState';
const ROTATABLE_COLLECTIONS = ['bookmarks', 'history', 'tabs', 'browser-settings', 'midori-tab', 'midori-privacy', 'passwords'];

let rotationState = {
    inProgress: false,
    startedAt: null,
    completed: [],          // collection names finished
    currentCollection: null,
    error: null,
};

async function _persistRotationState(extra = {}) {
    const payload = {
        ...rotationState,
        ...extra,
        // Persist the previous key so we can keep reading after a reload.
        previousKeyB64: previousEncryptionKey
            ? crypto_.sodium.to_base64(previousEncryptionKey, crypto_.sodium.base64_variants.ORIGINAL)
            : null,
    };
    await browser.storage.local.set({ [ROTATION_STORAGE_KEY]: payload });
}

async function _clearRotationState() {
    rotationState = {
        inProgress: false,
        startedAt: null,
        completed: [],
        currentCollection: null,
        error: null,
    };
    await browser.storage.local.remove(ROTATION_STORAGE_KEY);
}

async function _restoreRotationState() {
    const stored = await browser.storage.local.get(ROTATION_STORAGE_KEY);
    const data = stored[ROTATION_STORAGE_KEY];
    if (!data) return;
    rotationState = {
        inProgress: !!data.inProgress,
        startedAt: data.startedAt || null,
        completed: Array.isArray(data.completed) ? data.completed : [],
        currentCollection: data.currentCollection || null,
        error: data.error || null,
    };
    if (data.previousKeyB64) {
        previousEncryptionKey = crypto_.sodium.from_base64(
            data.previousKeyB64,
            crypto_.sodium.base64_variants.ORIGINAL
        );
    }
}

/**
 * Re-encrypt every record of a collection under `newKey`, decrypting
 * under `oldKey` (with M_new fallback for resumes). Pages through the
 * collection in chunks so a crash does not lose progress.
 */
async function _rotateCollection(collection, oldKey, newKey) {
    if (!authState.token) throw new Error('Not logged in');
    // Full scan: rotation must touch every record, not just newer ones.
    const url = `${authState.serverUrl}/api/ext/storage/${collection}`;
    const resp = await fetch(url, { headers: authHeaders() });
    if (!resp.ok) {
        // Empty / missing collection on the server — nothing to rotate.
        if (resp.status === 404) return;
        throw new Error(`Fetch ${collection} failed (HTTP ${resp.status})`);
    }
    const items = await resp.json();
    if (!Array.isArray(items) || items.length === 0) return;

    // Decrypt with old key; fallback to new key for items already rotated
    // in a previous interrupted run.
    const reencrypted = [];
    for (const bso of items) {
        if (!bso || !bso.payload) continue;
        let plaintext = null;
        for (const k of [oldKey, newKey]) {
            if (!k) continue;
            try {
                plaintext = crypto_.decrypt(bso.payload, k);
                break;
            } catch (_) { /* try next */ }
        }
        if (plaintext === null) {
            // Skip items we cannot decrypt under either key — they were
            // likely written by an even older key and rotation cannot
            // recover them. Surface this in logs but do not abort.
            console.warn(`[Midori Sync] Skipping unrotatable record ${bso.id} in ${collection}`);
            continue;
        }
        reencrypted.push({ id: bso.id, payload: encryptPayload(plaintext, newKey) });
    }

    if (reencrypted.length === 0) return;

    // Upload using a one-shot fetch path (we cannot use uploadBsos here
    // because it reads the *global* encryptionKey, which is still M_old
    // until the rotation commits).
    const CHUNK = 100;
    for (let i = 0; i < reencrypted.length; i += CHUNK) {
        const chunk = reencrypted.slice(i, i + CHUNK);
        const response = await fetch(`${authState.serverUrl}/api/ext/storage/${collection}`, {
            method: 'POST',
            headers: { ...authHeaders(), 'Content-Type': 'application/json' },
            body: JSON.stringify(chunk),
        });
        if (!response.ok) {
            const errBody = await response.json().catch(() => null);
            throw new Error(
                `Rotation upload to '${collection}' failed (HTTP ${response.status}): ${errBody?.message ?? 'unknown'}`
            );
        }
    }
}

/**
 * Preview a new mnemonic without changing anything.
 * Caller confirms it has been recorded, then calls performKeyRotation.
 */
async function previewKeyRotation() {
    await crypto_.init();
    if (lockState.hasPassphrase && lockState.locked) {
        throw new Error('Vault is locked. Unlock before rotating the master key.');
    }
    if (!encryptionKey) throw new Error('No encryption key configured');
    if (rotationState.inProgress) {
        throw new Error('A rotation is already in progress');
    }
    const mnemonic = generateMnemonic();
    return { mnemonic };
}

/**
 * Run the rotation end-to-end. May resume a prior interrupted run when
 * called with `{ resume: true }`.
 */
async function performKeyRotation(data) {
    await crypto_.init();
    if (lockState.hasPassphrase && lockState.locked) {
        throw new Error('Vault is locked. Unlock before rotating the master key.');
    }

    const { newMnemonic, resume = false } = data || {};

    if (!resume) {
        if (!newMnemonic || !validateMnemonic(newMnemonic)) {
            throw new Error('Invalid new seed phrase');
        }
        if (!encryptionKey) throw new Error('Current encryption key not loaded');

        // Derive M_new (worker-aware via deriveKeys).
        const newKey = deriveKeyFromMnemonic(newMnemonic);
        previousEncryptionKey = encryptionKey;
        // Stash the new mnemonic+key in the rotation checkpoint so we
        // can resume after a crash without losing it.
        rotationState = {
            inProgress: true,
            startedAt: new Date().toISOString(),
            completed: [],
            currentCollection: null,
            error: null,
        };
        await _persistRotationState({
            newMnemonic,
            newKeyB64: crypto_.sodium.to_base64(newKey, crypto_.sodium.base64_variants.ORIGINAL),
        });
        return _runRotationLoop(newKey, newMnemonic);
    }

    // Resume path: pull the checkpoint and continue.
    const stored = await browser.storage.local.get(ROTATION_STORAGE_KEY);
    const cp = stored[ROTATION_STORAGE_KEY];
    if (!cp || !cp.inProgress || !cp.newKeyB64 || !cp.newMnemonic) {
        throw new Error('No rotation to resume');
    }
    const newKey = crypto_.sodium.from_base64(cp.newKeyB64, crypto_.sodium.base64_variants.ORIGINAL);
    return _runRotationLoop(newKey, cp.newMnemonic);
}

async function _runRotationLoop(newKey, newMnemonic) {
    try {
        for (const collection of ROTATABLE_COLLECTIONS) {
            if (rotationState.completed.includes(collection)) continue;
            rotationState.currentCollection = collection;
            await _persistRotationState({
                newMnemonic,
                newKeyB64: crypto_.sodium.to_base64(newKey, crypto_.sodium.base64_variants.ORIGINAL),
            });
            try {
                await _rotateCollection(collection, previousEncryptionKey, newKey);
            } catch (e) {
                rotationState.error = `Failed on ${collection}: ${e.message || e}`;
                await _persistRotationState({
                    newMnemonic,
                    newKeyB64: crypto_.sodium.to_base64(newKey, crypto_.sodium.base64_variants.ORIGINAL),
                });
                throw e;
            }
            rotationState.completed.push(collection);
            rotationState.currentCollection = null;
            rotationState.error = null;
            await _persistRotationState({
                newMnemonic,
                newKeyB64: crypto_.sodium.to_base64(newKey, crypto_.sodium.base64_variants.ORIGINAL),
            });
        }

        // Commit: swap active key + persist seed/key per current lock mode.
        encryptionKey = newKey;
        const newKeyB64 = crypto_.sodium.to_base64(newKey, crypto_.sodium.base64_variants.ORIGINAL);
        if (lockState.hasPassphrase) {
            // Update in-memory copy of the seed phrase; the locked bundle
            // on disk is rewritten the next time the user re-enables the
            // passphrase. Until then we keep plaintext absent on disk.
            seedPhraseInMemory = newMnemonic;
            await browser.storage.local.remove(['seedPhrase', 'encryptionKey']);
        } else {
            await browser.storage.local.set({
                seedPhrase: newMnemonic,
                encryptionKey: newKeyB64,
            });
        }

        // Reset incremental cursors so the next sync re-pulls everything
        // and confirms the rotation took effect end-to-end.
        lastSyncTimes = { bookmarks: 0, history: 0, tabs: 0, passwords: 0 };
        await browser.storage.local.set({ lastSyncTimes });

        previousEncryptionKey = null;
        await _clearRotationState();
        return { success: true };
    } catch (err) {
        // Leave checkpoint + previousEncryptionKey in place so the user
        // can retry via resumeKeyRotation without losing progress.
        return { success: false, error: err.message || String(err) };
    }
}

async function getRotationStatus() {
    return {
        inProgress: rotationState.inProgress,
        startedAt: rotationState.startedAt,
        completed: rotationState.completed.slice(),
        total: ROTATABLE_COLLECTIONS.length,
        currentCollection: rotationState.currentCollection,
        error: rotationState.error,
        hasPreviousKey: !!previousEncryptionKey,
    };
}

/**
 * Abort an in-flight rotation. Server-side state stays partially
 * rotated, but reads keep working because `previousEncryptionKey`
 * remains as a fallback. The user is expected to either resume or
 * accept the mixed state until the next rotation.
 */
async function abortKeyRotation() {
    if (!rotationState.inProgress) return { success: true, alreadyClean: true };
    rotationState.inProgress = false;
    rotationState.error = 'Aborted by user';
    await _persistRotationState();
    return { success: true };
}

// ─── Crypto Helpers ─────────────────────────────────────────────────────

function exportKeyBase64(key) {
    return crypto_.sodium.to_base64(key, crypto_.sodium.base64_variants.ORIGINAL);
}

function importKeyBase64(b64) {
    return crypto_.sodium.from_base64(b64, crypto_.sodium.base64_variants.ORIGINAL);
}

function encryptPayload(plaintext, key) {
    return crypto_.encrypt(plaintext, key);
}

function decryptBsoPayload(bso, key) {
    if (!bso.payload) return bso;
    // Try the active key first, then the previous master key (only set
    // during a mixed-state rotation). This keeps reads working while a
    // rotation is mid-flight or has been interrupted.
    const candidates = [];
    if (key) candidates.push(key);
    if (previousEncryptionKey && previousEncryptionKey !== key) {
        candidates.push(previousEncryptionKey);
    }
    if (candidates.length === 0) return bso;
    for (const candidate of candidates) {
        try {
            const decrypted = crypto_.decrypt(bso.payload, candidate);
            return { ...bso, payload: decrypted, _decrypted: true };
        } catch (_) { /* try next candidate */ }
    }
    console.warn('[Midori Sync] Decryption failed for BSO', bso.id);
    return { ...bso, _decrypted: false };
}

/**
 * Re-encrypt all existing server-side plaintext data.
 */
async function migrateToEncryption() {
    console.log('[Midori Sync] Migrating existing data to encrypted storage...');
    const collections = ['bookmarks', 'history', 'tabs'];
    for (const col of collections) {
        try {
            const items = await fetchCollection(col, false);
            if (items.length === 0) continue;
            // Only re-upload items that were successfully decrypted (plaintext)
            // Skip items that failed decryption to avoid double-encryption
            const plaintextItems = items.filter(item => item._decrypted !== false);
            if (plaintextItems.length === 0) continue;
            const bsos = plaintextItems.map(item => ({ id: item.id, payload: item.payload }));
            await uploadBsos(col, bsos);
            console.log(`[Midori Sync] Migrated ${plaintextItems.length} items in '${col}'.`);
        } catch (e) {
            console.warn(`[Midori Sync] Migration failed for '${col}':`, e);
        }
    }
    await browser.storage.local.remove('encryptionMigrationNeeded');
    console.log('[Midori Sync] Encryption migration complete.');
}

// ─── Initialization ─────────────────────────────────────────────────────

async function initializeState() {
    const stored = await browser.storage.local.get([
        'auth', 'syncSettings', 'serverUrl', 'lastSyncTimes', 'encryptionMigrationNeeded',
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

    await initEncryptionKey();
    await _refreshLockStateFromStorage();
    await _restoreRotationState();

    if (authState.token) {
        setupSyncAlarms(stored.syncSettings || {});
        updateBadge('on');
        if (stored.encryptionMigrationNeeded) {
            migrateToEncryption().catch(e =>
                console.warn('[Midori Sync] Background migration failed:', e)
            );
        }
    } else {
        updateBadge('off');
    }
}

initializeState();
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
        generateSeedPhrase: handleGenerateSeedPhrase,
        completeSeedPhraseSetup: handleCompleteSeedPhraseSetup,
        recoverWithSeedPhrase: handleRecoverWithSeedPhrase,
        getSeedPhrase: handleGetSeedPhrase,
        getPasswords: handleGetPasswords,
        savePassword: handleSavePassword,
        deletePassword: handleDeletePassword,
        updateServerUrl: handleUpdateServerUrl,
        getLockStatus: getLockStatus,
        enableLocalPassphrase: enableLocalPassphrase,
        disableLocalPassphrase: disableLocalPassphrase,
        unlockEncryption: (data) => unlockEncryption(data && data.passphrase),
        lockEncryption: () => lockEncryption(),
        previewKeyRotation: () => previewKeyRotation(),
        performKeyRotation: performKeyRotation,
        getRotationStatus: getRotationStatus,
        abortKeyRotation: abortKeyRotation,
    };

    const handler = handlers[msg.type];
    if (handler) {
        // Reset the inactivity timer on every user-driven message.
        // Lock-related messages are intentionally allowed to extend the
        // session because they represent active interaction with the UI.
        try { noteUserActivity(); } catch (_) { /* best effort */ }
        handler(msg.data).then(sendResponse).catch(err => {
            sendResponse({ error: err.message });
        });
        return true;
    }
});

// ─── Auth Handlers ──────────────────────────────────────────────────────

/**
 * Auto-detect device name from browser info.
 */
async function getDeviceName() {
    try {
        const info = await browser.runtime.getBrowserInfo();
        return `${info.name} on ${navigator.platform}`;
    } catch {
        return `Browser on ${navigator.platform}`;
    }
}

/**
 * Start the OAuth2 login flow.
 * No configuration required — server URL is pre-defined, device name is auto-detected.
 */
async function handleLogin() {
    const server = authState.serverUrl;
    const deviceName = await getDeviceName();
    const existingDeviceId = authState.device?.id || '';

    const startResp = await fetch(
        `${server}/api/ext/auth/start?device_name=${encodeURIComponent(deviceName)}&device_type=desktop&device_id=${encodeURIComponent(existingDeviceId)}`,
        { headers: { 'Accept': 'application/json' } }
    );

    if (!startResp.ok) {
        throw new Error('Failed to start authentication');
    }

    const { auth_url, state } = await startResp.json();

    const tab = await browser.tabs.create({ url: auth_url });

    // Poll with exponential backoff (up to 5 minutes)
    const maxAttempts = 150;
    const baseDelay = 1000;
    const backoffMultiplier = 1.5;
    const maxDelay = 30000;

    for (let i = 0; i < maxAttempts; i++) {
        const delay = Math.min(baseDelay * Math.pow(backoffMultiplier, i), maxDelay);
        await new Promise(resolve => setTimeout(resolve, delay));

        try {
            await browser.tabs.get(tab.id);
        } catch {
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
            browser.tabs.remove(tab.id).catch(() => {});
            return await completeLogin(pollResult, server);
        }
    }

    throw new Error('Login timed out. Please try again.');
}

/**
 * Complete login: store credentials, setup sync, restore data.
 * Also uploads the encryption key bundle to the server for recovery.
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

    await initEncryptionKey();

    if (!encryptionKey) {
        // No key yet — user needs to set up seed phrase
        // Don't start sync until encryption is configured
        return { success: true, needsKeySetup: true, user: result.user, device: result.device };
    }

    // Key exists — migrate plaintext, then start sync
    await migrateToEncryption().catch(e =>
        console.warn('[Midori Sync] Encryption migration after login failed:', e)
    );

    const stored = await browser.storage.local.get('syncSettings');
    setupSyncAlarms(stored.syncSettings || {});
    updateBadge('on');

    try {
        await restoreFromServer();
    } catch (e) {
        console.warn('[Midori Sync] Data restore after login failed:', e);
    }

    return { success: true, user: result.user, device: result.device };
}

// ─── Seed Phrase Handlers ───────────────────────────────────────────────

/**
 * Generate a new 12-word mnemonic. Does NOT store it yet —
 * the user must confirm they saved it before we finalize.
 */
async function handleGenerateSeedPhrase() {
    await crypto_.init();
    const mnemonic = generateMnemonic();
    return { mnemonic };
}

/**
 * Finalize seed phrase setup: derive key, store locally, start sync.
 */
async function handleCompleteSeedPhraseSetup(data) {
    const { mnemonic } = data;
    if (!validateMnemonic(mnemonic)) throw new Error('Invalid seed phrase');

    await setupEncryptionFromMnemonic(mnemonic);

    // Encrypt any existing plaintext data on the server
    if (authState.token) {
        await migrateToEncryption().catch(e =>
            console.warn('[Midori Sync] Migration after seed setup failed:', e)
        );

        const stored = await browser.storage.local.get('syncSettings');
        setupSyncAlarms(stored.syncSettings || {});
        updateBadge('on');

        await restoreFromServer().catch(e =>
            console.warn('[Midori Sync] Restore after seed setup failed:', e)
        );
    }

    return { success: true };
}

/**
 * Recover encryption from an existing seed phrase (e.g. new device).
 */
async function handleRecoverWithSeedPhrase(data) {
    const { mnemonic } = data;
    if (!validateMnemonic(mnemonic)) throw new Error('Invalid seed phrase. Check that all 12 words are correct.');

    await setupEncryptionFromMnemonic(mnemonic);

    if (authState.token) {
        const stored = await browser.storage.local.get('syncSettings');
        setupSyncAlarms(stored.syncSettings || {});
        updateBadge('on');

        await restoreFromServer().catch(e =>
            console.warn('[Midori Sync] Restore after recovery failed:', e)
        );
    }

    return { success: true };
}

/**
 * Return the stored seed phrase so the user can view it in settings.
 *
 * When a local passphrase is active and the vault is locked, the seed
 * phrase is not in plaintext on disk and we refuse to surface it. The
 * caller must unlock first via `unlockEncryption`.
 */
async function handleGetSeedPhrase() {
    const stored = await browser.storage.local.get('seedPhrase');
    if (stored.seedPhrase) return { mnemonic: stored.seedPhrase };
    if (lockState.hasPassphrase) {
        if (lockState.locked) throw new Error('Vault is locked');
        if (seedPhraseInMemory) return { mnemonic: seedPhraseInMemory };
        throw new Error('Seed phrase not in memory; unlock the vault again');
    }
    throw new Error('No seed phrase stored');
}

async function restoreFromServer() {
    console.log('[Midori Sync] Restoring data from server...');
    updateBadge('syncing');
    // Reset sync timestamps to ensure full fetch, not incremental
    lastSyncTimes = { bookmarks: 0, history: 0, tabs: 0, passwords: 0 };
    await browser.storage.local.set({ lastSyncTimes });
    await restoreBookmarks();
    await restoreHistory();
    await syncTabs();
    updateBadge('on');
    console.log('[Midori Sync] Data restore complete.');
}

async function restoreBookmarks() {
    const serverData = await fetchCollection('bookmarks', false);
    if (!serverData || serverData.length === 0) return;
    // Filter out items that failed decryption
    const decryptedData = serverData.filter(bso => bso._decrypted !== false);
    if (decryptedData.length === 0) {
        console.warn('[Midori Sync] All bookmarks failed decryption — wrong encryption key?');
        return;
    }
    const tree = await browser.bookmarks.getTree();
    const localUrls = new Set();
    flattenBookmarks(tree).forEach(b => localUrls.add(b.url));
    let restored = 0;
    for (const bso of decryptedData) {
        try {
            const payload = JSON.parse(bso.payload);
            if (payload.url && !localUrls.has(payload.url)) {
                await browser.bookmarks.create({ title: payload.title || payload.url, url: payload.url });
                restored++;
            }
        } catch (e) { /* skip unparseable */ }
    }
    console.log(`[Midori Sync] Restored ${restored} bookmarks.`);
}

async function restoreHistory() {
    const serverData = await fetchCollection('history', false);
    if (!serverData || serverData.length === 0) return;
    // Filter out items that failed decryption
    const decryptedData = serverData.filter(bso => bso._decrypted !== false);
    if (decryptedData.length === 0) {
        console.warn('[Midori Sync] All history entries failed decryption — wrong encryption key?');
        return;
    }
    let restored = 0;
    for (const bso of decryptedData) {
        try {
            const payload = JSON.parse(bso.payload);
            if (payload.url) {
                await browser.history.addUrl({
                    url: payload.url, title: payload.title || '',
                    visitTime: payload.lastVisitTime || Date.now(),
                });
                restored++;
            }
        } catch (e) { /* skip unparseable */ }
    }
    // Also store in IndexedDB for local cache
    const idbItems = decryptedData.flatMap(bso => {
        try { return [JSON.parse(bso.payload)]; } catch { return []; }
    });
    if (idbItems.length > 0) {
        await storeHistory(idbItems).catch(e =>
            console.warn('[Midori Sync] IndexedDB store failed:', e)
        );
    }
    console.log(`[Midori Sync] Restored ${restored} history entries.`);
}

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

async function handleGetState() {
    const stored = await browser.storage.local.get(['auth', 'syncSettings', 'lastSync', 'serverUrl', 'storageInfo']);
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

async function handleGetProfile() {
    if (!authState.token) throw new Error('Not logged in');
    const response = await fetch(`${authState.serverUrl}/api/ext/profile`, { headers: authHeaders() });
    if (!response.ok) throw new Error('Failed to fetch profile');
    return await response.json();
}

/**
 * Allow changing the server URL from settings (advanced).
 */
async function handleUpdateServerUrl(data) {
    const { serverUrl } = data;
    if (!serverUrl) throw new Error('Server URL is required');
    authState.serverUrl = serverUrl.replace(/\/+$/, '');
    await browser.storage.local.set({ serverUrl: authState.serverUrl });
    return { success: true };
}

// ─── Sync Handlers ──────────────────────────────────────────────────────

async function handleSyncNow(data) {
    if (!authState.token) throw new Error('Not logged in');
    const type = data?.type;
    const stored = await browser.storage.local.get('syncSettings');
    const settings = stored.syncSettings || {};
    if (type) {
        await syncDataType(type);
    } else {
        for (const [key, defaults] of Object.entries(SYNC_TYPES)) {
            const enabled = settings[key]?.enabled ?? defaults.enabled;
            if (enabled) await syncDataType(key);
        }
    }
    await fetch(`${authState.serverUrl}/api/ext/sync/status`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id: authState.device?.id }),
    }).catch(() => {});
    return { success: true };
}

async function handleSaveSyncSettings(data) {
    await browser.storage.local.set({ syncSettings: data });
    setupSyncAlarms(data);
    return { success: true };
}

// ─── Sync Engine ────────────────────────────────────────────────────────

async function syncDataType(type) {
    const now = new Date().toISOString();
    let syncPromise = null;
    let shouldMarkSynced = true;
    try {
        switch (type) {
            case 'bookmarks': syncPromise = syncBookmarks(); break;
            case 'history': syncPromise = syncHistory(); break;
            case 'tabs': syncPromise = syncTabs(); break;
            case 'passwords': syncPromise = syncPasswords(); shouldMarkSynced = false; break;
        }
        if (syncPromise) await syncPromise;
        if (!shouldMarkSynced) return;
        const stored = await browser.storage.local.get('lastSync');
        const lastSync = stored.lastSync || {};
        lastSync[type] = now;
        await browser.storage.local.set({ lastSync });
        refreshStorageInfo();
        console.log(`[Midori Sync] Synced ${type} at ${now}`);
    } catch (err) {
        console.error(`[Midori Sync] Failed to sync ${type}:`, err);
    } finally {
        syncPromise = null;
    }
}

async function syncBookmarks() {
    const tree = await browser.bookmarks.getTree();
    const localBookmarks = flattenBookmarks(tree);
    const serverData = await fetchCollection('bookmarks');
    const serverIds = new Set(serverData.map(b => b.id));
    const toUpload = localBookmarks.filter(b => !serverIds.has(b.id));
    if (toUpload.length > 0) {
        await uploadBsos('bookmarks', toUpload.map(b => ({
            id: b.id, payload: JSON.stringify(b),
        })));
    }
    const localIds = new Set(localBookmarks.map(b => b.id));
    for (const serverBso of serverData) {
        if (!localIds.has(serverBso.id)) {
            try {
                const payload = JSON.parse(serverBso.payload);
                if (payload.url && payload.title) {
                    await browser.bookmarks.create({
                        title: payload.title, url: payload.url,
                        parentId: payload.parentId || undefined,
                    });
                }
            } catch (e) { console.warn('[Midori Sync] Bookmark create failed:', e); }
        }
    }
}

function flattenBookmarks(nodes, result = []) {
    for (const node of nodes) {
        if (node.url) {
            result.push({
                id: 'bk-' + hashString(node.url),
                title: node.title || '', url: node.url,
                parentId: node.parentId, dateAdded: node.dateAdded,
            });
        }
        if (node.children) flattenBookmarks(node.children, result);
    }
    return result;
}

const MAX_FULL_HISTORY_ITEMS = 5000;

async function fetchFullHistory(startTime) {
    const allItems = [];
    const seenIds = new Set();
    let endTime = Date.now();
    let previousEndTime = null;
    while (allItems.length < MAX_FULL_HISTORY_ITEMS) {
        const beforeCount = allItems.length;
        const page = await browser.history.search({ text: '', startTime, endTime, maxResults: 500 });
        if (page.length === 0) break;
        for (const item of page) {
            if (!seenIds.has(item.id)) { seenIds.add(item.id); allItems.push(item); }
        }
        if (page.length < 500) break;
        const oldestVisit = Math.min(...page.map(i => i.lastVisitTime));
        if (oldestVisit <= startTime) break;
        endTime = oldestVisit - 1;
        if (allItems.length === beforeCount) break;
        if (previousEndTime !== null && endTime >= previousEndTime) break;
        previousEndTime = endTime;
    }
    return allItems;
}

async function syncHistory() {
    const THIRTY_DAYS_MS = 30 * 24 * 3600 * 1000;
    const thirtyDaysAgo = Date.now() - THIRTY_DAYS_MS;
    const stored = await browser.storage.local.get('lastSync');
    const lastSync = stored.lastSync?.history;
    const startTime = lastSync
        ? Math.max(new Date(lastSync).getTime(), thirtyDaysAgo)
        : thirtyDaysAgo;
    const historyItems = lastSync
        ? await browser.history.search({ text: '', startTime, maxResults: 500 })
        : await fetchFullHistory(thirtyDaysAgo);
    if (historyItems.length === 0) return;
    const bsos = historyItems.map(item => ({
        id: 'hi-' + hashString(item.url),
        payload: JSON.stringify({
            url: item.url, title: item.title || '',
            visitCount: item.visitCount, lastVisitTime: item.lastVisitTime,
        }),
    }));
    await uploadBsos('history', bsos);
}

async function syncTabs() {
    const tabs = await browser.tabs.query({});
    const tabData = tabs
        .filter(t => t.url && !t.url.startsWith('about:') && !t.url.startsWith('moz-extension:'))
        .map(t => ({
            url: t.url, title: t.title || '', icon: t.favIconUrl || '',
            active: t.active, lastAccessed: t.lastAccessed,
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

async function syncPasswords() {
    const stored = await browser.storage.local.get('passwords');
    const localPasswords = stored.passwords || [];
    if (localPasswords.length > 0) {
        await uploadBsos('passwords', localPasswords.map(p => ({
            id: p.id, payload: JSON.stringify(p),
        })));
    }
    const serverBsos = await fetchCollection('passwords');
    if (serverBsos.length === 0) return;
    const serverPasswords = serverBsos.map(bso => {
        try { return typeof bso.payload === 'string' ? JSON.parse(bso.payload) : bso.payload; }
        catch { return null; }
    }).filter(p => p && p.id);
    if (serverPasswords.length === 0) return;
    const merged = new Map(localPasswords.map(p => [p.id, p]));
    for (const sp of serverPasswords) {
        const local = merged.get(sp.id);
        if (!local || new Date(sp.updatedAt) > new Date(local.updatedAt)) {
            merged.set(sp.id, sp);
        }
    }
    await browser.storage.local.set({ passwords: Array.from(merged.values()) });
}

async function handleGetPasswords() {
    const stored = await browser.storage.local.get('passwords');
    return stored.passwords || [];
}

async function handleSavePassword(data) {
    const { id, site, username, password, notes } = data;
    if (!site || !username || !password) throw new Error('site, username and password are required');
    const stored = await browser.storage.local.get('passwords');
    const passwords = stored.passwords || [];
    const now = new Date().toISOString();
    if (id) {
        const idx = passwords.findIndex(p => p.id === id);
        if (idx === -1) throw new Error('Password entry not found');
        passwords[idx] = { ...passwords[idx], site, username, password, notes: notes || '', updatedAt: now };
    } else {
        passwords.push({
            id: crypto.randomUUID(), site, username, password,
            notes: notes || '', createdAt: now, updatedAt: now,
        });
    }
    await browser.storage.local.set({ passwords });
    if (authState.token) {
        syncDataType('passwords').catch(e =>
            console.warn('[Midori Sync] Post-save password sync failed:', e)
        );
    }
    return { success: true };
}

async function handleDeletePassword(data) {
    const { id } = data;
    if (!id) throw new Error('id is required');
    const stored = await browser.storage.local.get('passwords');
    const passwords = (stored.passwords || []).filter(p => p.id !== id);
    await browser.storage.local.set({ passwords });
    return { success: true };
}

// ─── Server Communication ───────────────────────────────────────────────

async function fetchCollection(collection, incrementalOnly = true) {
    if (!authState.token) return [];
    let url = `${authState.serverUrl}/api/ext/storage/${collection}`;
    if (incrementalOnly && lastSyncTimes[collection]) {
        url += `?newer=${lastSyncTimes[collection]}`;
    }
    const resp = await fetch(url, { headers: authHeaders() });
    if (!resp.ok) return [];
    const items = await resp.json();
    if (!Array.isArray(items) || items.length === 0) return [];
    const maxModified = Math.max(...items.map(i => parseFloat(i.modified || 0)));
    if (maxModified > 0) {
        lastSyncTimes[collection] = maxModified;
        await browser.storage.local.set({ lastSyncTimes });
    }
    return Promise.all(items.map(bso => decryptBsoPayload(bso, encryptionKey)));
}

const BATCH_UPLOAD_CHUNK_SIZE = 100;

async function uploadBsos(collection, bsos) {
    const uid = authState.user?.id;
    if (!uid || bsos.length === 0) return;
    if (!encryptionKey) {
        throw new Error('Encryption key not initialized — refusing to upload unencrypted data');
    }
    const dedupedBsos = Array.from(new Map(bsos.map(bso => [bso.id, bso])).values());
    for (let i = 0; i < dedupedBsos.length; i += BATCH_UPLOAD_CHUNK_SIZE) {
        const rawChunk = dedupedBsos.slice(i, i + BATCH_UPLOAD_CHUNK_SIZE);
        const chunk = await Promise.all(rawChunk.map(async bso => ({
            ...bso,
            payload: await encryptPayload(bso.payload, encryptionKey),
        })));
        const response = await fetch(`${authState.serverUrl}/api/ext/storage/${collection}`, {
            method: 'POST',
            headers: { ...authHeaders(), 'Content-Type': 'application/json' },
            body: JSON.stringify(chunk),
        });
        if (!response.ok) {
            const errBody = await response.json().catch(() => null);
            throw new Error(
                `Upload to '${collection}' failed (HTTP ${response.status}): ${errBody?.message ?? 'unknown error'}`
            );
        }
    }
}

// ─── Device Pairing ─────────────────────────────────────────────────────

async function handleGeneratePairingToken() {
    if (!authState.token) throw new Error('Not logged in');
    const response = await fetch(`${authState.serverUrl}/api/ext/pair`, {
        method: 'POST', headers: authHeaders(),
    });
    if (!response.ok) throw new Error('Failed to generate pairing token');
    const serverResult = await response.json();
    const encKeyB64 = encryptionKey ? await exportKeyBase64(encryptionKey) : null;
    return { ...serverResult, encryption_key_b64: encKeyB64 };
}

async function handleRedeemPairingToken(data) {
    const { pairingToken, serverUrl, encryptionKey: incomingKey } = data;
    const server = serverUrl || authState.serverUrl;
    const deviceName = await getDeviceName();
    const response = await fetch(`${server}/api/ext/pair/redeem`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
            pairing_token: pairingToken,
            device_name: deviceName,
            device_type: 'desktop',
        }),
    });
    if (!response.ok) {
        const err = await response.json();
        throw new Error(err.message || 'Pairing failed');
    }
    const result = await response.json();
    if (incomingKey) {
        await browser.storage.local.set({ encryptionKey: incomingKey });
    }
    authState = { token: result.token, user: result.user, device: result.device, serverUrl: server };
    await browser.storage.local.set({ auth: authState, serverUrl: server });
    await initEncryptionKey();
    const stored = await browser.storage.local.get('syncSettings');
    setupSyncAlarms(stored.syncSettings || {});
    updateBadge('on');

    if (encryptionKey) {
        try {
            await restoreFromServer();
        } catch (e) {
            console.warn('[Midori Sync] Data restore after pairing failed:', e);
        }
    }

    return { success: true, user: result.user, device: result.device };
}

async function handleRemoveDevice() {
    if (!authState.token) throw new Error('Not logged in');
    return { success: true };
}

// ─── Alarms ─────────────────────────────────────────────────────────────

function setupSyncAlarms(settings) {
    browser.alarms.clearAll();
    for (const [type, defaults] of Object.entries(SYNC_TYPES)) {
        const config = settings[type] || {};
        const enabled = config.enabled ?? defaults.enabled;
        const interval = config.interval ?? defaults.interval;
        if (enabled && authState.token) {
            browser.alarms.create(`sync-${type}`, { periodInMinutes: interval });
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

function debouncedSync(type, delayMs = 500) {
    if (syncTimeouts[type]) clearTimeout(syncTimeouts[type]);
    syncTimeouts[type] = setTimeout(() => {
        delete syncTimeouts[type];
        if (authState.token) {
            syncDataType(type).catch(e =>
                console.warn(`[Midori Sync] Debounced sync failed for ${type}:`, e)
            );
        }
    }, delayMs);
}

browser.bookmarks.onCreated.addListener(() => debouncedSync('bookmarks', 2000));
browser.bookmarks.onChanged.addListener(() => debouncedSync('bookmarks', 2000));
browser.bookmarks.onRemoved.addListener(() => debouncedSync('bookmarks', 2000));
browser.bookmarks.onMoved.addListener(() => debouncedSync('bookmarks', 2000));
browser.history.onVisited.addListener(() => debouncedSync('history', 5000));

// ─── IndexedDB ──────────────────────────────────────────────────────────

let _idb = null;

async function initializeIndexedDB() {
    if (_idb) return _idb;
    return new Promise((resolve, reject) => {
        const req = indexedDB.open('midori-sync', 1);
        req.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('history')) db.createObjectStore('history', { keyPath: 'url' });
            if (!db.objectStoreNames.contains('bookmarks')) db.createObjectStore('bookmarks', { keyPath: 'id' });
        };
        req.onsuccess = () => { _idb = req.result; resolve(_idb); };
        req.onerror = () => reject(req.error);
    });
}

async function storeHistory(items) {
    const db = await initializeIndexedDB();
    const tx = db.transaction(['history'], 'readwrite');
    const store = tx.objectStore('history');
    for (const item of items) store.put(item);
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

// ─── Utilities ──────────────────────────────────────────────────────────

function authHeaders() {
    return { Authorization: `Bearer ${authState.token}`, Accept: 'application/json' };
}

async function refreshStorageInfo() {
    if (!authState.token) return;
    try {
        const response = await fetch(`${authState.serverUrl}/api/ext/storage/info`, { headers: authHeaders() });
        if (response.ok) {
            const data = await response.json();
            await browser.storage.local.set({ storageInfo: data });
        }
    } catch (e) { /* non-critical */ }
}

function hashString(str) {
    // BLAKE2b-128 (libsodium, sync). Collision-resistant replacement for the
    // previous DJB2 32-bit hash. Requires sodium.ready; callers run after
    // crypto_.init() so sodium is available.
    if (typeof sodium === 'undefined' || !sodium.crypto_generichash) {
        throw new Error('libsodium not ready: cannot hash URL');
    }
    const digest = sodium.crypto_generichash(16, sodium.from_string(str));
    return sodium.to_hex(digest);
}

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
