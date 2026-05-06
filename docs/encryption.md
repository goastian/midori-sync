# Midori Sync — Encryption

## Overview

Midori Sync uses **end-to-end encryption (E2EE)**. The server never has access to plaintext data. All encryption and decryption happens in the browser extension.

## Algorithms

| Purpose | Algorithm | Library |
|---------|-----------|---------|
| Symmetric Encryption | XChaCha20-Poly1305 (AEAD) | libsodium |
| Key Derivation (password) | Argon2id | libsodium |
| Key Derivation (sub-keys) | BLAKE2B (crypto_kdf) | libsodium |
| URL hashing (record IDs) | BLAKE2b-128 (`crypto_generichash`) | libsodium |

## Key Hierarchy

```
User Passphrase (BIP39 mnemonic, 12 words)
       │
       ▼ Argon2id (ops=3, mem=64 MB, ALG_ARGON2ID13)
  Master Key (32 bytes)
       │
       ▼ crypto_kdf_derive_from_key, context = "MSPv1key"
       │
       ├── Index 0 → Wrapping Key (server-side key bundle)
       ├── Index 1 → Bookmarks
       ├── Index 2 → History
       ├── Index 3 → Tabs                 (alias: open-tabs, legacy)
       ├── Index 4 → Browser Settings
       ├── Index 5 → Midori Tab
       ├── Index 6 → Midori Privacy
       ├── Index 7 → Devices
       └── Index 8 → Passwords
```

The 8-byte KDF context `MSPv1key` separates collection sub-keys from
unrelated key derivations (see "Local lock", below).

### Stable index contract

KDF indices are part of the on-the-wire contract. They MUST never be
reordered or repurposed: doing so silently re-derives a sub-key for the
wrong collection, producing data that decrypts cleanly but is typed
incorrectly. New collections claim a fresh index. Legacy aliases
(`open-tabs` → 3) reuse an existing index on purpose so that
already-uploaded ciphertexts remain decryptable.

A guardrail test at `tests/collection-scope.test.js` enforces that the
backend `CollectionSeeder`, the extension `COLLECTION_INDEX`, and the
adapter files under `extension/background/collection-adapters/` stay in
sync.

## Payload Layout

The wire format for every encrypted record is base64 of a single
contiguous byte string:

```
+-----------------------+----------------------------------+--------------+
| nonce (24 bytes)      | ciphertext (= |plaintext| bytes) | tag (16 B)   |
+-----------------------+----------------------------------+--------------+
```

The base64 alphabet is libsodium's `ORIGINAL` variant (RFC 4648 with
`+`/`/` and `=` padding).

### Invariants

- `len(nonce) == crypto_aead_xchacha20poly1305_ietf_NPUBBYTES == 24`.
- `len(tag) == crypto_aead_xchacha20poly1305_ietf_ABYTES == 16`.
- `len(combined) == 24 + len(plaintext) + 16`.
- The nonce is generated with `randombytes_buf` per encryption call.
  XChaCha20's 192-bit nonce makes random selection collision-safe at any
  realistic scale.
- Encryption is randomized in the (key, plaintext) pair, so two
  consecutive encryptions of the same plaintext yield distinct
  ciphertexts.
- Authentication is computed over `(ciphertext, ad=nil)`. Flipping any
  byte of the combined payload (nonce, ciphertext, or tag) MUST cause
  decryption to fail with an error rather than return manipulated
  plaintext.

These invariants are validated by `tests/crypto.test.js` under the
"roundtrip properties" describe block.

## Encryption Flow

### Encrypting a Record

1. Serialize data to a JSON string.
2. Get the collection sub-key from the master key (see hierarchy).
3. Generate a random 24-byte nonce.
4. Encrypt with `crypto_aead_xchacha20poly1305_ietf_encrypt`.
5. Concatenate: `nonce (24) || ciphertext || tag (16)`.
6. Base64-encode the concatenated bytes.
7. Send the base64 string as the `payload` field to the API.

### Decrypting a Record

1. Base64-decode the `payload` field.
2. Split: first 24 bytes = nonce, the remaining bytes = ciphertext+tag.
3. Get the collection sub-key.
4. Decrypt with `crypto_aead_xchacha20poly1305_ietf_decrypt`.
5. Parse the JSON string.

## Server-side Key Bundle

The master key itself is encrypted and stored on the server so that new
devices can retrieve it after authentication:

1. Derive a **wrapping key** (sub-key index 0) from the same passphrase.
2. Encrypt the master key's base64 representation with the wrapping key.
3. Store as JSON: `{ v: 1, salt: "<base64>", bundle: "<encrypted>" }`.

To set up a new device the user enters their passphrase:

1. Download the encrypted key bundle from the server.
2. Parse JSON, extract `salt`.
3. Re-derive the wrapping key from passphrase + salt.
4. Decrypt to recover the master key.

## Argon2id execution

Argon2id is memory-hard (64 MB) and CPU-bound (`opslimit=3`). To keep
the background page / service worker responsive, it is executed inside
a dedicated Web Worker at `extension/lib/argon2-worker.js`. The worker
loads its own copy of libsodium so the rest of the extension does not
need to import the sumo build until it actually performs other crypto
(lazy-loading objective).

`MidoriSyncCrypto.deriveKeys()` automatically delegates to the worker
when running inside the extension. In environments without `Worker`
support (Node tests, sandboxes) it falls back to a synchronous
`crypto_pwhash` call. If the worker errors out at runtime, the next
`deriveKeys()` call also degrades to the synchronous path so a broken
worker never blocks login.

## Local Lock (optional)

Without configuration the seed phrase and the encryption key live in
plaintext inside `browser.storage.local`. Users who want at-rest
protection can opt in to a **local passphrase**:

- The seed phrase + encryption key are wrapped under a key derived from
  the local passphrase (Argon2id, KDF context `MSPv1lck` to keep this
  derivation distinct from collection sub-keys).
- After the passphrase is set, plaintext copies are removed from
  `browser.storage.local`. Only the encrypted bundle remains at rest.
- An **inactivity alarm** (default 15 minutes, configurable per user)
  wipes the in-memory key. The next user action requires `unlockEncryption`.
- The plaintext seed phrase, while unlocked, is held only in memory.
  Locking wipes it; viewing it again requires re-unlocking.

Lock-related IPC messages exposed by the background page:

| Message | Purpose |
|---------|---------|
| `getLockStatus` | `{ hasPassphrase, locked, timeoutMinutes }` |
| `enableLocalPassphrase` | Set or rotate the local passphrase |
| `disableLocalPassphrase` | Remove the passphrase, restore plaintext storage |
| `lockEncryption` | Manual lock now |
| `unlockEncryption` | Decrypt the bundle and rehydrate the key |

When no local passphrase is set, the existing behavior is preserved
verbatim: plaintext seed in `storage.local`, no inactivity timeout.

## Master Key Rotation

Rotation is needed when the user wants to invalidate a leaked
passphrase, change KDF parameters, or recover from suspected device
compromise. The procedure is **incremental** so a rotation can survive
a power loss between collections.

### Trigger

Initiated from the extension Settings UI ("Rotate master key"). The
caller supplies the *current* passphrase and a new passphrase.

### Procedure

1. **Verify** the current passphrase by decrypting the server-side key
   bundle. Refuse to continue on failure.
2. **Generate** a fresh 16-byte salt and derive a new master key
   `M_new = Argon2id(new_passphrase, salt_new)`.
3. **Encrypt** `M_new` under a wrapping key derived from `new_passphrase
   + salt_new` and upload the new bundle to the server with version
   `v+1`. The server keeps the previous bundle until the migration
   completes (so other devices can still read existing data).
4. For each collection `C`, in order:
    1. Page records server-side using the existing `delta` cursor and
       `version` filter.
    2. For every page, decrypt with `M_old`'s sub-key for `C`, re-encrypt
       with `M_new`'s sub-key for `C`, and upload via the existing
       `batchUpsert` UPSERT. The server-side `version` column advances
       naturally and conditional GETs on other devices invalidate.
    3. Persist a per-collection rotation cursor in `browser.storage.local`
       (`{ rotationCursor: { [collection]: lastRotatedDelta } }`).
       Resuming after interruption picks up at the cursor.
5. Once **all** collections report a cursor at or beyond the latest
   `delta` observed in step 1, mark rotation complete: delete the
   previous bundle on the server, drop the rotation cursor, and overwrite
   `M_old` in memory with zeros.
6. Other devices observe the bundle version bump and prompt for the new
   passphrase on their next sync.

### Invariants during rotation

- `M_old` and `M_new` coexist in memory only (never both on disk) until
  step 5.
- A record is never deleted as part of rotation; it is only re-encrypted.
  A failed rotation leaves the system in a consistent but mixed state
  (some collections under `M_new`, others under `M_old`), which the next
  resume cycle finishes.
- Reads during rotation are best-effort: if decrypt with `M_new` fails,
  fall back to `M_old` for one cycle, then give up. This bounds the
  blast radius of a corrupt bundle.

### Out of scope (intentionally)

- Forward secrecy across rotations: rotation does not re-key the
  server-side `version` chain, so an attacker who recorded ciphertexts
  before rotation can still decrypt them with `M_old`.
- Atomic global rotation: by construction the procedure is per-collection
  and idempotent so it can resume after browser restarts.

## Security Properties

- **Zero-knowledge server**: server stores only encrypted blobs.
- **Forward secrecy across records**: each record uses a random nonce.
- **Authenticated encryption**: XChaCha20-Poly1305 is an AEAD cipher;
  any tampering causes decryption to fail.
- **Memory-hard KDF**: Argon2id with 64 MB raises the cost of brute-force
  on weak passphrases.
- **Per-collection isolation**: compromising one sub-key does not expose
  other collections.
- **Off-main-thread KDF**: Argon2id runs in a dedicated worker so the
  background page stays responsive during login and rotation.

## Client Library

The encryption library is at `extension/lib/midori-sync-crypto.js` and
depends on `libsodium-wrappers-sumo` (~375 KB; the sumo variant is
required for Argon2id). The Argon2id worker at
`extension/lib/argon2-worker.js` loads its own copy of libsodium so the
extension can avoid pulling sumo into pages that only need messaging.

## Nonce Size

XChaCha20 uses a 192-bit (24-byte) nonce. This is large enough that
random nonce generation is safe without risk of collision, even over
billions of encryptions.
