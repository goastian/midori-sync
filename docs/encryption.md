# Midori Sync — Encryption

## Overview

Midori Sync uses **end-to-end encryption (E2EE)**. The server never has access to plaintext data. All encryption and decryption happens in the browser extension.

## Algorithms

| Purpose | Algorithm | Library |
|---------|-----------|---------|
| Symmetric Encryption | XChaCha20-Poly1305 (AEAD) | libsodium |
| Key Derivation (password) | Argon2id | libsodium |
| Key Derivation (sub-keys) | BLAKE2B (crypto_kdf) | libsodium |

## Key Hierarchy

```
User Passphrase
       │
       ▼ Argon2id (ops=3, mem=64MB)
  Master Key (32 bytes)
       │
       ├── Index 0 → Wrapping Key (for key bundle encryption)
       ├── Index 1 → Bookmarks Sub-Key
       ├── Index 2 → History Sub-Key
       ├── Index 3 → Open Tabs Sub-Key
       ├── Index 4 → Browser Settings Sub-Key
       ├── Index 5 → Midori Tab Sub-Key
       ├── Index 6 → Midori Privacy Sub-Key
       └── Index 7 → Devices Sub-Key
```

Sub-keys are derived using `crypto_kdf_derive_from_key` with the context `MSPv1key` (8 bytes).

## Encryption Flow

### Encrypting a Record

1. Serialize data to JSON string
2. Get the collection sub-key from master key
3. Generate random 24-byte nonce
4. Encrypt with `crypto_aead_xchacha20poly1305_ietf_encrypt`
5. Concatenate: `nonce (24 bytes) || ciphertext || tag (16 bytes)`
6. Base64-encode the result
7. Send base64 string as `payload` field to the API

### Decrypting a Record

1. Base64-decode the `payload` field
2. Split: first 24 bytes = nonce, rest = ciphertext+tag
3. Get the collection sub-key
4. Decrypt with `crypto_aead_xchacha20poly1305_ietf_decrypt`
5. Parse JSON string

## Key Bundle

The master key itself is encrypted and stored on the server so that new devices can retrieve it:

1. Derive a **wrapping key** (sub-key index 0) from the same passphrase
2. Encrypt the master key's base64 representation with the wrapping key
3. Store as JSON: `{ v: 1, salt: "<base64>", bundle: "<encrypted>" }`

To set up a new device, the user enters their passphrase:
1. Download encrypted key bundle from server
2. Parse JSON, extract salt
3. Re-derive wrapping key from passphrase + salt
4. Decrypt to recover master key

## Security Properties

- **Zero-knowledge server**: Server stores only encrypted blobs
- **Forward secrecy**: Each record uses a random nonce
- **Authenticated encryption**: XChaCha20-Poly1305 is an AEAD cipher
- **Memory-hard KDF**: Argon2id with 64 MB prevents brute-force on weak passphrases
- **Per-collection isolation**: Compromising one sub-key doesn't expose other collections

## Client Library

The encryption library is at `extension/lib/midori-sync-crypto.js` and depends on `libsodium-wrappers-sumo` (~375 KB, sumo variant needed for Argon2id).

## Nonce Size

XChaCha20 uses a 192-bit (24-byte) nonce. This is large enough that random nonce generation is safe without risk of collision, even over billions of encryptions.
