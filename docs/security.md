# Midori Sync — Security

> This document is the project's living security contract.
> Any PR affecting auth, crypto, middleware, CORS, CSP, headers,
> or seed phrase storage must update it.

---

## 1. Threat Model

### 1.1 Assets

- **User data**: bookmarks, history, tabs, browser-settings,
  midori-tab, midori-privacy, and `passwords`. All E2E encrypted.
- **Native recovery secret**: protects the V2 key bundle held by the client.
- **Legacy seed and master key**: needed only while migrating V1 ciphertext.
- **Sync tokens**: bearer tokens issued by the backend; allow access to
  user ciphertext and metadata (not plaintext data).
- **Authentik accounts**: user identity; credential rotation is outside
  the scope of Midori Sync.

### 1.2 Considered Adversaries

| Adversary                      | Assumed Capability                              | Primary Mitigation                         |
|--------------------------------|-------------------------------------------------|--------------------------------------------|
| Backend operator               | Reads full DB, logs, and filesystem             | E2E: only sees ciphertext + metadata       |
| Network attacker               | Active MITM if TLS is absent                    | HTTPS + HSTS in production                 |
| Attacker with device access    | Reads local profile and old extension storage   | OS-backed native secret store and profile protection |
| Web attacker (CSRF / XSS)      | Injects JS into dashboard or internal pages     | CSP + Vue escaping + privileged client boundary |
| Malicious extension attacker   | Another extension with similar permissions      | Native privileged modules and scoped tokens |
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

- **Legacy KDF**: Argon2id (`ops=3`, `mem=64 MB`) over the old seed and bundle salt. The retired extension performed it in a worker.
- **Native V2 KDF**: Argon2id13 with a versioned recovery bundle, implemented in Rust; see [native-sync-api.md](native-sync-api.md).
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

- **Authentik (OIDC)**: dashboard identity and the account issuer for native device pairing.
- **`SyncSession`** (ADR-002): single auth layer for `/api/v1` and
  `/api/ext`. Bearer tokens with configurable TTL (`SYNC_TOKEN_TTL`).
- **Sanctum**: present as a utility for a future dashboard SPA API.
  NOT used for sync.

### 3.2 Tokens

- At-rest hashing: SHA-256. The DB never stores bearer tokens in
  plaintext.
- TTL: configurable; default 30 days. Hourly cleanup via
  `sync:cleanup-expired`.
- Individual and bulk-per-user revocation available from the dashboard; native devices can revoke their sessions.
- Auditing: IP, truncated User-Agent, `last_used_at`, `last_seen_ip`.

### 3.3 Manual Pairing

The dashboard issues a short-lived, single-use code for the native client via `/devices/pairing-code`; `/api/v1/pair/redeem` consumes it transactionally. Legacy `/api/ext/pair` routes remain temporarily for installed clients.

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

### 4.2 CORS (`SyncApiCors`)

- Config-based whitelisting via `CORS_ALLOWED_ORIGINS`
  (TODO: currently `*`).
- Preflight: `OPTIONS` responds only with allowed headers.
- `Origin` echo only if present in the allowlist.
- Expected origins: configured dashboard and API clients. Local defaults do not include browser-extension origins.

## 5. Rate Limiting and Quotas

- `sync` rate limiter with independent buckets:
  - `sync:r:*` (read, `SYNC_RATE_LIMIT_READ`).
  - `sync:w:*` (write, `SYNC_RATE_LIMIT_WRITE`).
- Coverage: `RateLimitTest`.
- Per-user quota via `SyncQuota` inside the storage transaction, serialized
  with other writes by an account lock. Count net replacement bytes;
  tombstones and expired records are excluded. Shrinking and deletion remain
  available above quota. Coverage: `EnforceQuotaTest`, `SyncOperationsTest`.

---

## 6. Client Storage and Local Lock

Native Desktop stores its account state in the browser profile and keeps
secrets behind its privileged Sync service; see the native client contract
and Midori Desktop migration status for the current protection boundary.

Previously installed extension profiles may still hold a seed, master key,
or encrypted `lockBundle` in `browser.storage.local`. The legacy lock used
the `MSPv1lck` context and Argon2id. These values must be imported through
the verified migration path and removed only after a durable checkpoint.

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
- Legacy storage migration (seed, `lockBundle`, `rotationState`).
- `/api/v1` or `/api/ext` contracts with backward compatibility impact.
