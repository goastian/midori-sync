# Midori Sync — Security

> This document is the project's living security contract.
> Any PR affecting auth, crypto, middleware, CORS, CSP, headers,
> or seed phrase storage must update it.

---

## 1. Threat Model

### 1.1 Assets

- **User data**: bookmarks, history, tabs, browser-settings,
  midori-tab, midori-privacy, and `passwords`. All E2E encrypted.
- **BIP39 seed phrase (24 words)**: the only preimage capable of
  deriving the master key. Compromise = total loss of confidentiality.
- **Master key (M)**: derived from the seed via Argon2id; per-collection
  subkeys via BLAKE2b (context `MSPv1key`).
- **Sync tokens**: bearer tokens issued by the backend; allow access to
  user ciphertext and metadata (not plaintext data).
- **Authentik accounts**: user identity; credential rotation is outside
  the scope of Midori Sync.

### 1.2 Considered Adversaries

| Adversary                      | Assumed Capability                              | Primary Mitigation                         |
|--------------------------------|-------------------------------------------------|--------------------------------------------|
| Backend operator               | Reads full DB, logs, and filesystem             | E2E: only sees ciphertext + metadata       |
| Network attacker               | Active MITM if TLS is absent                    | HTTPS + HSTS in production                 |
| Attacker with device access    | Reads extension `storage.local`                 | Optional local lock with passphrase        |
| Web attacker (CSRF / XSS)      | Injects JS into dashboard or internal pages     | CSP + Vue escaping + DOM-safe extension    |
| Malicious extension attacker   | Another extension with similar permissions      | Strict CSP origins + scoped tokens         |
| Token theft adversary          | Bearer replay until TTL expires                 | DB hashing + TTL + revocation + auditing   |
| High-compute adversary         | Offline seed brute force                        | Argon2id (ops=3, mem=64MB) + 24 words      |

### 1.3 Out of Scope

- Compromise of the host browser (keylogger, screen recorder).
- Compromise of the identity provider itself (Authentik).
- Legal coercion against the user (the seed only exists on the device).
- Side-channel attacks against the client OS or CPU.

---

## 2. E2E Encryption

Full algorithmic details: [encryption.md](encryption.md).
Invariant summary:

- **KDF**: Argon2id (`ops=3`, `mem=64 MB`) over the BIP39 seed +
  bundle-dependent fixed salt, executed in a dedicated Web Worker
  (`extension/lib/argon2-worker.js`) with synchronous fallback.
- **Per-collection subkeys**: BLAKE2b with context `MSPv1key` and
  `subkey_id = COLLECTION_INDEX[name]`. Indices are stable; changing
  them breaks decryption of existing data.
- **AEAD**: XChaCha20-Poly1305. Backend upload payload layout:
  `base64(nonce(24) || ciphertext || tag(16))`.
- **Local lock**: `M` bundle encrypted with passphrase via Argon2id +
  KDF context `MSPv1lck` (distinct from `MSPv1key`).
- **Master key rotation**: incremental, resumable cursor-based rotation
  with fallback decryption to `M_old` during mixed states. Procedure
  documented in [encryption.md](encryption.md) and
  [runbooks.md](runbooks.md).

The backend NEVER has access to the seed, `M`, subkeys, or plaintext.

---

## 3. Authentication and Sessions

### 3.1 Layers

- **Authentik (OIDC)**: user identity, dashboard login,
  foundation of the extension OAuth flow (`/api/ext/auth/start`,
  `/api/ext/auth/poll`).
- **`SyncSession`** (ADR-002): single auth layer for `/api/v1` and
  `/api/ext`. Bearer tokens with configurable TTL (`SYNC_TOKEN_TTL`).
- **Sanctum**: present as a utility for a future dashboard SPA API.
  NOT used for sync.

### 3.2 Tokens

- At-rest hashing: SHA-256. The DB never stores bearer tokens in
  plaintext.
- TTL: configurable; default 30 days. Hourly cleanup via
  `sync:cleanup-expired`.
- Individual and bulk-per-user revocation available from the
  dashboard (`Audit/Index`) and extension (device revoke).
- Auditing: IP, truncated User-Agent, `last_used_at`, `last_seen_ip`.

### 3.3 Manual Pairing

`/api/ext/pair` -> `/api/ext/pair/redeem` using a single-use code,
short expiration (configurable TTL), and replay protection
(`PairingFlowTest`).

---

## 4. Headers and Web Policies

### 4.1 nginx (production, `nginx.prod.conf`)

- `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`
- `Content-Security-Policy`: `default-src 'self'; script-src 'self';
  style-src 'self' 'unsafe-inline'; connect-src 'self' <authentik>;
  img-src 'self' data:; frame-ancestors 'none'`
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy`: disables microphone, camera, and geolocation.

> Current status: HSTS and CSP are currently TODO/open in
> `docker/nginx.conf` (Block 2 / Phase 7). See
> [plan-status.md](plan-status.md).

### 4.2 CORS (`CorsForExtension`)

- Config-based whitelisting via `CORS_ALLOWED_ORIGINS`
  (TODO: currently `*`).
- Preflight: `OPTIONS` responds only with allowed headers.
- `Origin` echo only if present in the allowlist.
- Expected origins:
  `moz-extension://<uuid>`, dashboard domain,
  `https://accounts.astian.org`.

### 4.3 Extension (CSP in `manifest.json` + meta tags)

```text
script-src 'self';
object-src 'self';
connect-src 'self' http://localhost:8000 https://sync.astian.org https://accounts.astian.org;
style-src 'self' 'unsafe-inline';
img-src 'self' data: https:;
default-src 'self';
```

`Content-Security-Policy` meta tag replicated in
`options/options.html`, `popup/popup.html`,
`setup/setup.html`.

---

## 5. Rate Limiting and Quotas

- `sync` rate limiter with independent buckets:
  - `sync:r:*` (read, `SYNC_RATE_LIMIT_READ`).
  - `sync:w:*` (write, `SYNC_RATE_LIMIT_WRITE`).
- Coverage: `RateLimitTest`.
- Per-user quota via `EnforceQuota`. Tombstones excluded from
  accounting. GET requests exempt. Coverage: `EnforceQuotaTest`.

---

## 6. Client Storage and Local Lock

- Seed phrase and master key live in `browser.storage.local`. Without
  local lock they remain in plaintext (mitigation: only accessible by
  the extension itself; CSP prevents external injection).
- Optional local lock: the user enables a passphrase; the seed and `M`
  are removed from `storage.local` and only an encrypted `lockBundle`
  remains under context `MSPv1lck`.
- Idle lock: alarm with configurable timeout (default 15 min).
- Unlock requires Argon2id derivation from the passphrase using the
  same parameters as the primary KDF.

---

## 7. Logging and Auditing

- Sensitive events that MUST be logged in structured form
  (partial TODO — Block 2 still open):
  - login / logout / pairing / OAuth complete.
  - token revocation (single and bulk).
  - quota changes.
  - collection deletion / full wipe.
  - repeated auth failures (>=N within M minutes).
- User-visible auditing: `Audit/Index` lists active and expired
  sessions with IP, UA, device, and allows individual or global revoke.

---

## 8. Dependencies

- `composer audit` and `npm audit` must run in CI for every PR
  (TODO still open in Phase 7).
- Dependabot recommended for automated security PRs.
- SBOM pending (`syft` or OWASP Dependency-Track) — Phase 8.

---

## 9. Vulnerability Reporting

See [SECURITY.md](../SECURITY.md) at the repository root. Summary:

- DO NOT open a public issue.
- Email `security@astian.org` (PGP in `.well-known/security.txt`).
- Target triage: <72h. Target fix: <30 days for critical issues.

---

## 10. Changes Requiring an ADR

Any change in these areas requires an ADR under `docs/adr/`:

- KDF, AEAD, payload layout, or `COLLECTION_INDEX`.
- Auth layer (`SyncSession`, Sanctum, Authentik).
- CORS / CSP / HSTS / security headers.
- Extension storage shape (seed, `lockBundle`, `rotationState`).
- `/api/v1` or `/api/ext` contracts with backward compatibility impact.
