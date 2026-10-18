# Midori Sync — Security

> This document is the project's living security contract.
> Any PR affecting auth, crypto, middleware, CORS, CSP, headers,
> or seed phrase storage must update it.

---

## 1. Threat Model

### 1.1 Assets

- **User data**: bookmarks, history, tabs, browser-settings,
  midori-tab, midori-privacy, devices, passwords, credit cards, form history
  and addresses. Native client-custody payloads are encrypted end to end.
  Historical server-custody accounts remain recoverable by the server until
  migration; deleting escrow cannot erase older backups or prior knowledge.
- **Native recovery secret**: protects the V2 key bundle held by the client.
- **Legacy seed and master key**: needed only while migrating V1 ciphertext.
- **Sync tokens**: bearer tokens issued by the backend; allow access to
  user ciphertext and metadata (not plaintext data).
- **Authentik accounts**: user identity; credential rotation is outside
  the scope of Midori Sync.

### 1.2 Considered Adversaries

| Adversary                      | Assumed Capability                              | Primary Mitigation                         |
|--------------------------------|-------------------------------------------------|--------------------------------------------|
| Backend operator | Reads DB, backups and application keys; may alter responses | Client custody with independent recovery code, authenticated registry/records/controls; legacy escrow remains a historical exception |
| Network attacker               | Active MITM if TLS is absent                    | HTTPS + HSTS in production                 |
| Attacker with device access    | Reads local profile and old extension storage   | OS-backed native secret store and profile protection |
| Web attacker (CSRF / XSS)      | Injects JS into dashboard or internal pages     | CSP + Vue escaping + privileged client boundary |
| Malicious extension attacker   | Another extension with similar permissions      | Native privileged modules and account/device-bound tokens |
| Token theft adversary          | Bearer replay until TTL expires                 | DB hashing + TTL + revocation + auditing   |
| High-compute adversary         | Offline seed brute force                        | Argon2id13 (ops=3, mem=64MB); high-entropy native recovery code      |

### 1.3 Out of Scope

- Compromise of the host browser (keylogger, screen recorder).
- Compromise of the identity provider itself (Authentik).
- Legal coercion against the user (the seed only exists on the device).
- Side-channel attacks against the client OS or CPU.

---

## 2. E2E Encryption

Current native contract: [native-sync-api.md](native-sync-api.md). The older
[encryption.md](encryption.md) describes the retired extension format.

- Native bundle wrapping uses Argon2id13 and `MSPv2key`. Record/local/identity
  subkeys use `MSPv2rec`, `MSPv2loc` and `MSPv2ids`, with fixed collection indices.
- XChaCha20-Poly1305 authenticates ciphertext, scope and record context. Schema 2
  additionally authenticates deleted/TTL semantics inside the encrypted body.
- Identity and data keys are separate. Controls use the identity devices subkey;
  the server checks authorization and CAS, stores the MAC opaquely, and cannot
  manufacture a valid client proof. Clients verify before local destructive work.
- New activation never accepts an opening secret. Server recovery endpoints reject
  disclosure and creation. Legacy migration uses a new data key, resumable
  rewriting, explicit legacy-deletion review, history-ID reconciliation and final
  registry confirmation before removing escrow. Current encrypted tombstones and
  compacted snapshots keep offline devices recoverable while old keys retire.
- Backend compromise does not reveal new client-custody plaintext from the DB,
  encrypted backups and application keys alone. It can still deny service, withhold
  data and observe sizes/timing, account/device IDs and collection names.
- Registry MAC version 2 authenticates the resulting crypto revision. Activation,
  rotation, completion and retirement sign and commit the previous revision plus
  one; version-2 accounts cannot downgrade. Version-1 accounts retain their old
  MAC until an authorized modern mutation upgrades it. Locally anchored authenticated
  revisions reject known replay/rollback even after old keys have been retired. A newly recovered device
  has no independent global freshness oracle; a full malicious-server rollback
  cannot be ruled out solely by AEAD or the latest supplied registry. Protection
  does not revoke knowledge retained by a previously authorized device.

The [synthetic encrypted backup/restore check](backup-restore-verification.md)
separates backup keys, application keys and client recovery keys. Historical
server escrow and old backups retain their earlier recovery properties.

---

## 3. Authentication and Sessions

### 3.1 Layers

- **Authentik (OIDC)**: dashboard identity and the account issuer for native device pairing.
- **`SyncSession`** (ADR-002): auth layer for `/api/v1`. Bearer tokens
  have configurable TTL (`SYNC_TOKEN_TTL`).
- **Sanctum**: present as a utility for a future dashboard SPA API.
  NOT used for sync.

### 3.2 Tokens

- At-rest hashing: SHA-256. The DB never stores bearer tokens in
  plaintext.
- TTL: configurable for paired sessions. Renewable native sessions have separate
  short-lived access and rotating refresh credentials, bounded lifetime and
  idempotent response recovery; expiry is checked on every request.
- Individual and bulk-per-user revocation available from the dashboard; native devices can revoke their sessions.
- Auditing: IP, truncated User-Agent, `last_used_at`, `last_seen_ip`.

Native sessions grant account-wide ciphertext operations for their bound identity
and owned device; there are no per-collection or read-only grants. Revocation is
rechecked inside the account transaction for feed/key reads and writes. Possessing
a bearer alone does not supply a client recovery secret or valid MAC. An authorized
malicious device may still delete server data or deny service; encryption is not an
availability guarantee. Browser authorization uses explicit account confirmation,
request correlation, one-use authorization codes and PKCE.

### 3.3 Manual Pairing

The dashboard issues a short-lived, single-use code for the native client via `/devices/pairing-code`; `/api/v1/pair/redeem` consumes it transactionally.

---

## 4. Headers and Web Policies

### 4.1 nginx (production, `nginx.prod.conf`)

- `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`
- `Content-Security-Policy`: `default-src 'self'; script-src 'self';
  style-src 'self' 'unsafe-inline'; connect-src 'self' <authentik>;
  img-src 'self' data:; frame-ancestors 'none'`
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy`: disables microphone, camera, and geolocation.

The generic HTTP nginx template and Artisan server are for loopback development
or a private upstream behind TLS. Compose binds loopback by default. The production
template requires TLS 1.2/1.3; an `APP_URL` setting alone is not enforcement. Access
logs omit query strings/referrers/authorization headers to avoid OAuth code leakage.
Production must disable debug pages and request/body tracing, including upstream
proxies and telemetry; the local HTTP test is not a production TLS validation.

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
- `/api/v1` contracts with backward compatibility impact.
