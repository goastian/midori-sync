/**
 * Midori Sync — Argon2id Worker
 *
 * Executes the memory-hard, CPU-bound Argon2id KDF off the main thread
 * (or service worker / background page) so the UI/sync engine is not
 * blocked while a passphrase is being stretched into a 32-byte master
 * key. libsodium is loaded *only inside this worker*, which lets the
 * main thread skip loading the ~375 KB sumo build until/unless it
 * actually needs other crypto primitives (lazy-loading objective).
 *
 * Protocol (postMessage):
 *   in : { id, op: 'derive', passphrase, salt, opslimit, memlimit }
 *        (passphrase is a string OR a Uint8Array; salt is a Uint8Array)
 *   out: { id, ok: true,  key: Uint8Array(32) }
 *   out: { id, ok: false, error: string }
 *
 * The worker is intentionally stateless across messages: every derive()
 * starts from the same loaded sodium instance and returns immediately.
 */

self.importScripts('sodium.js');

let ready = false;

async function ensureReady() {
    if (ready) return;
    await sodium.ready;
    ready = true;
}

self.addEventListener('message', async (event) => {
    const { id, op, passphrase, salt, opslimit, memlimit } = event.data || {};
    try {
        await ensureReady();
        if (op !== 'derive') {
            throw new Error(`Unknown op: ${op}`);
        }
        const ops = opslimit ?? 3;
        const mem = memlimit ?? 67108864; // 64 MB
        const key = sodium.crypto_pwhash(
            32,
            passphrase,
            salt,
            ops,
            mem,
            sodium.crypto_pwhash_ALG_ARGON2ID13
        );
        // Transfer the underlying buffer to avoid a copy.
        self.postMessage({ id, ok: true, key }, [key.buffer]);
    } catch (err) {
        self.postMessage({ id, ok: false, error: err && err.message ? err.message : String(err) });
    }
});
