# Native Sync API: change feed and operations

Status: initial implementation with local PostgreSQL runtime and concurrency coverage.
`GET /api/v1/capabilities` deliberately returns `native_ready: false` until the
remaining native protocol and migration gates are met. This is not a release
announcement. PostgreSQL is the database for deployment, isolated development
and integration tests. Artisan manages database preparation, migrations and
synthetic accounts through `sync:dev`.

The machine-readable [OpenAPI 3.1 contract](native-sync.openapi.json) describes
the implemented native-client profile, including session revocation, request
and response schemas, per-operation outcomes, byte limits and reset behavior.
The native OIDC exchange is available when a public Authentik client is configured; Desktop completes authorization code with PKCE before calling it.
The retired extension routes have been removed. The format
follows the [OpenAPI specification](https://spec.openapis.org/oas/v3.1.0.html).

## Routes

| Method | Path below `/api/v1` | Purpose |
| --- | --- | --- |
| GET | `/capabilities` | Public capability and size discovery, no credentials required |
| POST | `/pair` | Issue a short-lived code from an authenticated account |
| POST | `/pair/redeem` | Consume a code atomically and register a device/session |
| POST | `/auth/native-token` | Verify a public-client OIDC authorization and issue a renewable native device session |
| GET | `/account` | Return the session's bound account identity, device and expiry |
| POST | `/auth/refresh` | Rotate renewable credentials or recover the last response with the same operation UUID |
| DELETE | `/auth/refresh` | Revoke the session using current or consumed renewal proof, including after access expiry |
| DELETE | `/auth/token` | Revoke the session using a valid access bearer |
| POST | `/sync/notifications/ticket` | Issue a one-use, 30-second ticket for the change-notification stream |
| GET | `/sync/notifications/stream` | Receive account-scoped change hints over a dedicated event stream |
| GET | `/sync/collections/{name}/changes` | Ordered change page; optional `cursor` and `limit` |
| POST | `/sync/collections/{name}/ack` | Acknowledge an applied page with `{ "cursor": "..." }` |
| POST | `/sync/collections/{name}/operations` | Conditional, idempotent writes with individual outcomes |
| GET | `/crypto/state` | Native key registry, activation state and conditional-write epoch/revision |
| POST | `/crypto/activate` | Activate V2 for an empty account with explicit handling of incompatible sessions |
| POST | `/crypto/rotate` | Register a new active key while retaining previous recovery bundles |
| PUT | `/crypto/native-keys/{keyId}` | Replace one wrapped recovery bundle conditionally |
| DELETE | `/sync/data` | Explicit conditional erasure of Sync data and key bundles |

The three collection routes require a valid bearer session bound to a device
owned by the account. They use the authenticated session's device, never a
device identifier supplied in the operation body. Legacy sessions without a
device receive `409 device_required`. Responses use `Cache-Control: no-store`.

Pairing codes are stored as SHA-256 hashes, expire after the configured TTL and
are consumed in the same transaction that creates the device and session.
Device names are labels, not identity keys. Each redemption receives a new UUID.
Pairing is available through `/api/v1/pair` and `/api/v1/pair/redeem`.
Pairing establishes a session; it does not transfer E2EE keys.

The notification ticket endpoint requires a valid native V2 bearer bound to an
owned device. It returns `version`, `ticket` and `expires_at` with `no-store`.
Only the ticket hash is stored; issuing another ticket replaces the prior one.
Connect to `/sync/notifications/stream` with `Authorization: MidoriNotification
<ticket>`. The one-use ticket is consumed on connection. A `changed` event
contains only `{}`; clients then read the normal Sync feeds. The stream
sends a heartbeat every 20 seconds and closes after 15 minutes, so clients must
obtain a fresh ticket before reconnecting. The production nginx route proxies
this stream to a supervised Artisan process outside PHP-FPM. Desktop listens
while background Sync is eligible and retains change-feed polling as a
fallback.

Accounts with encrypted data from the retired client remain protected against accidental V2 activation. Stored account data is not erased by removing the extension-specific migration API.

For an authenticated web account, open **Dashboard → Connect Midori Desktop**
or **Devices → Generate pairing code**. The web-only `POST /devices/pairing-code`
uses the session and CSRF protection to return the same one-use, five-minute
code without exposing an API bearer token to the page. Enter it in the Sync popup in Midori Desktop and select **Connect account**. The page shows a
countdown and clears the code when it expires. Code generation is limited to
five requests per minute per account. In local development, `sync:dev pair`
remains available for the synthetic account when web OAuth is not configured.
The isolated local launcher also shows **Open local Sync** on its homepage;
this signs in to its prepared synthetic account and opens the Devices page.
That entry is only registered in the explicit local/testing launcher and only
accepts requests from a loopback address.

### Native account identity

Capabilities include `account_version: 1` and `authentication` with `pairing`,
`development` and nullable `issuer`. The desktop must check these separately
from `native_ready`; the latter still remains false.

Before consuming the code or creating a device/session, the server checks the
user's stored issuer against the configured issuer. A successful response includes
`identity` with `issuer`, `subject` and `kind` (`development` or `oidc`). Native
sessions store `protocol_version: 2`; previously issued version 1 sessions
remain stored until expiry or revocation but their bearer tokens receive HTTP 401
on current Sync routes. The native pairing response also returns the user ID
as a string. These session versions are server-issued metadata, not a request
header that an old session can supply to claim compatibility.

`GET /account` requires an authenticated, unexpired session and a device owned
by its user. It returns `account_version`, the same `identity`, `user` (ID as a
string, name and email), `device` (UUID, name and type), and `expires_at`. It does
not return a token. Responses use `Cache-Control: no-store`. A missing device
returns `409 device_required`; absent or mismatched identity returns
`409 native_identity_required` or `409 identity_issuer_mismatch`.

The migration adds nullable `users.authentik_issuer` without assigning an issuer
to existing accounts. `AUTHENTIK_ISSUER` must be the exact HTTPS issuer from the
provider configuration, including its path/trailing slash, without credentials,
query or fragment. It is distinct from `AUTHENTIK_BASE_URL`. The web OAuth
callback binds an unbound account to the configured issuer after obtaining its
subject from Authentik's userinfo endpoint. It preserves any existing issuer
binding; accounts with a different binding cannot generate a native web pairing
code. Authenticated web sessions with no binding are bound when they request a
code after the server issuer is configured. If `AUTHENTIK_ISSUER` is missing,
the web code endpoint returns `503 server_issuer_not_configured` and does not
create a code.
This endpoint is an assertion from the authenticated Sync server, not a
replacement for OIDC token validation or PKCE.

Only explicit local/testing mode plus `SYNC_LOCAL_DEV=true` selects
`urn:midori:sync:local`. `sync:dev prepare` binds the synthetic account to this
issuer, including databases prepared before the new field existed. Production
never selects that issuer even if the development flag is set. `sync:dev pair`
requires the prepared binding and does not assign one implicitly. The desktop
must only accept development identities in its explicitly selected loopback
environment. Server URL remains part of the cryptographic account scope, so
different local server instances do not share a scope merely because they use
the same development issuer.

### Renewable native sessions

`authentication.refresh.version: 1` advertises the implemented backend contract,
not client readiness. Pairing must explicitly include `native_refresh: true`.
Otherwise it retains access-only credentials. Native Desktop requests renewal
when the server advertises it. Old sessions cannot be upgraded by presenting
their access token.

Renewable pairing returns `refresh_version`, `refresh_token` and
`refresh_expires_at` in addition to its normal response. The renewal secret is
32 random bytes with an `mrf_` prefix and hexadecimal encoding; the server stores
its domain-separated SHA-256 hash. Access lasts 60–3600 seconds (configured TTL
clamped to that range). Renewal ends absolutely after 30 days from pairing.

`POST /auth/refresh` accepts exactly `refresh_token` and a version-4 UUID
`operation_id`. Persist that operation before sending it. Rotation locks the
user, session and owned device, verifies the original issuer/subject/device
binding, replaces both credentials in the same session and preserves absolute
renewal expiry. Access cannot extend past that expiry. It does not create a new
device, alter cryptographic keys or grant additional permissions.

The latest rotation has an authenticated encrypted response receipt, bound to
session, consumed proof hash and operation UUID. Repeating the same operation
recovers that exact response, even if its access token has since expired. Its
`expires_in` reflects original issuance; clients must inspect `expires_at`, save
the recovered renewal proof durably and rotate again before declaring online
access. Earlier receipt bodies are cleared, but their consumed hashes and UUIDs
remain until session deletion. Replaying an earlier operation returns
`409 refresh_superseded`. Reusing any consumed proof with a different UUID
commits deletion of that session and its receipts, then returns
`401 refresh_reused`. Other sessions remain valid.

`DELETE /auth/refresh` accepts exactly `refresh_token`. Current or consumed
proof revokes the same family, including when access expired. Unknown or already
revoked proof also returns an empty 204. Access-token revocation, device deletion
(web/v1) and audit revocation cascade to all renewal receipts. Scheduled
cleanup retains an expired access token while its renewal authorization lives.
The audit page counts such sessions as active and shows the authorization end
date so they remain revocable.

Access-authenticated logout revokes the already authenticated session ID under
the shared account lock. It must not look up the token hash again after middleware
authentication: a concurrent rotation could replace that hash and leave a live
session behind a successful 204 response. Individual and bulk audit revocation
use the same account lock as rotation and session issuance.

Both proof endpoints reject requests above 4096 UTF-8 bytes and use JSON errors
with `Cache-Control: no-store`, including malformed requests without an Accept
header and throttle responses. Do not put either secret in URLs or logs. Honor
`Retry-After`. A session rotates at most once per `min(60, floor(access TTL / 2))`
seconds; exact receipt recovery bypasses only this interval, not network rate
limits. Capacity is 1024 rotations per session and 32 unexpired renewable
sessions per account. Limits reject before changing credentials or consuming a
pairing code. Their values are published with the refresh capability.

Receipts use Laravel application encryption, never Sync E2EE keys. Preserve
`APP_PREVIOUS_KEYS` during application-key rotation until affected receipts have
expired or been replaced. An unreadable/mismatched receipt returns
`503 refresh_receipt_unavailable` without rotating again. Reuse detection and
replay recovery require retaining consumed-proof records until family deletion.
The migration refuses rollback while renewable sessions exist. MSP renewal does
not implement OAuth/PKCE, scoped grants or existing-account reauthorization.

## Download and acknowledgement

Start without a cursor. The server returns `generation`, `changes`,
`snapshot_sequence`, `has_more` and `next_cursor`. Each change contains a decimal
string `sequence` and a `record` with `id`, decimal string `revision`, opaque
`payload`, boolean `deleted` and nullable ISO 8601 `ttl`. A tombstone has an empty
payload. Clients must honor TTL even if the scheduled expiry job has not yet
emitted its tombstone.

Sequences and revisions are strings so JavaScript does not round large counters.
The first page fixes a snapshot boundary. While `has_more` is true, use its
`next_cursor` to finish that boundary. Later writes appear on the following poll,
after the boundary is complete. Immutable snapshots preserve intermediate values
even if a record is edited or deleted between pages. The limit is 1–100 records;
pages additionally cap the serialized record data at 4 MiB.

A client may persist a download cursor after durably retaining the complete
page and every pending application intent, allowing later pages to resolve
dependencies. It must track application progress separately: POST the cursor
to `/ack` only after every covered change is durably applied, or explicitly
quarantined with enough information to recover it. Native position projections
must also finish before acknowledgement. Downloading alone does not acknowledge
anything. Repeated or out-of-order acknowledgements cannot lower the stored
position.

Cursors are authenticated encrypted server tokens bound to account, collection,
device and generation. Treat them as opaque and URL-encode them in queries.
Malformed or cross-scope tokens return `400 invalid_cursor`. A full data wipe
rotates generations and removes journals, operation receipts and device
acknowledgements atomically. Old cursors then return `409 reset_required`.
Restart download without a cursor and reconcile against the new generation;
never blindly re-upload all old local data after a reset.

## Conditional operations

First read the collection feed to obtain its current generation. Example body:

```json
{
  "generation": "00000000-0000-4000-8000-000000000001",
  "operations": [
    {
      "operation_id": "00000000-0000-4000-8000-000000000002",
      "id": "bookmark-guid",
      "base_revision": "0",
      "payload": "opaque-client-encrypted-record"
    }
  ]
}
```

Generate a fresh UUID per logical operation and persist it in the client outbox
before sending. `base_revision: "0"` means the record must not exist. Existing
records, including tombstones, require their current revision. Deletion uses
`deleted: true`; omit the payload or send an empty one. TTL is optional. The
server never decrypts record contents. In native mode it validates the public
envelope metadata described below before accepting a new operation.


## Storage and transition

### Native key registry and write barrier

Capabilities advertise `crypto_state_version: 1` and native `crypto_versions: [2]`.
`native_ready` remains false. All native key endpoints require a live version-2
session, an owned device and a bound account identity. They recheck the session
inside the account transaction and lock its row against concurrent revocation.
They return `Cache-Control: no-store`; they never receive a master key or
recovery secret.

`GET /crypto/state` creates a state row lazily and returns:

```json
{
  "crypto_state_version": 1,
  "epoch": "00000000-0000-4000-8000-000000000001",
  "revision": "0",
  "mode": "legacy",
  "active_key_id": null,
  "incompatible_sessions": 0,
  "migration_required": false,
  "keys": []
}
```

Each key entry has `key_id` and `encrypted_bundle` (a JSON string containing the
V2 recovery envelope). The registry has at most eight keys. Key IDs must encode
exactly 16 bytes in canonical unpadded base64url. Bundle versions, Argon2id
parameters and base64 sizes must match the native format. Bundles are bounded
to 2048 bytes. Shape validation cannot prove the AEAD tag, account scope or
recoverability; the native client must verify those cryptographically.

`migration_required` indicates that an account without an active native key
still has records, a legacy bundle or retained journal entries. The desktop
can explain the migration requirement before generating a recovery code. This
is a snapshot for presentation; activation rechecks the same condition under
the account lock and never relies on an earlier client check.

Activation and rotation requests contain:

```json
{
  "crypto": {
    "epoch": "00000000-0000-4000-8000-000000000001",
    "revision": "0"
  },
  "key_id": "ICEiIyQlJicoKSorLC0uLw",
  "encrypted_bundle": "V2 recovery-envelope JSON"
}
```

`crypto.revision` is a decimal string, distinct from the old integer bundle
counter and from record revisions. The epoch and revision must exactly match
the account state; otherwise the entire request returns
`409 crypto_state_conflict`. Key-state mutations increment the revision.
After a lost response, read the state and verify the expected key/bundle before
retrying or disposing of a newly generated local key. A conflict is not proof
that the earlier request failed.

`POST /crypto/activate` requires no existing records (including tombstones),
legacy recovery bundle or retained changes. Otherwise it returns
`409 legacy_migration_required` without modifying them. It does not implement
or silently perform conversion. Existing live version-1 sessions cause
`409 incompatible_devices`; the caller may explicitly add
`revoke_incompatible: true` after the user chooses to revoke them. Revocation,
key registration, journal-generation reset and activation commit together.
Activation never implicitly revokes sessions merely because the boolean was
omitted. Expired sessions cannot write and do not block activation.

After activation, unconditional legacy upsert, batch, delete, wipe and bundle
replacement are rejected within the account lock. Issuing new version-1
sessions is also rejected there. A request admitted before activation must
acquire this same lock before it can mutate records. Native writers must use
conditional operations and include the current `crypto` object alongside the
collection `generation` and `operations`.

For new writes, the server requires a V2 `purpose: record` envelope using the
active key. Its collection, ID, generation, schema and base revision must
match the operation. Unknown/incorrect fields and sizes yield an item-level
`invalid` result without a record or receipt. A previously stored matching
receipt is returned before applying the current active-key check, so an
already-applied operation can still be resolved after rotation; the request
must first refresh its `crypto` precondition. Deletes retain the ordinary
authenticated, conditional tombstone contract. Server TTL cleanup remains
authorized to emit tombstones.

`POST /crypto/rotate` registers a different key ID, makes it active and retains
all earlier bundles and encrypted records. It does not recipher records or
complete a multi-device rotation. `PUT /crypto/native-keys/{keyId}` accepts
`crypto` and `encrypted_bundle` to rewrap an existing key without changing the
active key. Reusing a registered ID for rotation returns `409 key_id_exists`;
exceeding eight retained keys returns `409 key_capacity_reached`. Retirement,
safe pruning, multi-key recovery-secret changes and resumable client reciphering
remain pending. The server exposes no unsafe key-deletion shortcut.

`DELETE /sync/data` takes the current `crypto` object. It removes Sync records,
bundles, receipts and acknowledgements, and rotates both the key-state epoch
and collection generations. A native account
stays native with no active key, and can activate a new key against the new
epoch. Old version-zero creation requests cannot pass the new epoch, and a
wipe never restores legacy write access. The explicit authenticated dashboard
wipe uses the same reset path. Rolling back the new schema is refused once a
native account exists, including after erasure, rather than dropping its write
barrier.

Migrations add `sync_streams`, `sync_changes`, `sync_device_cursors` and
`sync_operations`; no existing data is rewritten by the schema migrations.
The first access to a collection initializes its journal from existing records
under the account lock, in bounded chunks. The initial implementation replays
the retained log for bootstrap. Snapshot compaction is still pending.

All service writes lock the account before reading revisions, quota or the
sequence. PostgreSQL holds that row lock until commit, so a higher sequence
cannot commit ahead of a lower one in the same account. Counter, record, receipt
and change are committed together; rollback leaves no committed sequence gap.
Different accounts remain independent. This serialization favors correctness;
measure contention before optimizing the lock scope.

Legacy upsert/batch/delete and expiry paths append to this same journal.
Collection deletion and TTL cleanup now retain versioned tombstones. A full
account data wipe deletes payloads and rotates stream generations. The journal
does not yet prune history or tombstones; implement retention, snapshot
compaction and acknowledgement/generation rules before production rollout.

Legacy accounts still permit unconditional writes through the old API. Fresh-account native
activation closes those paths transactionally. The extension-specific conversion
routes were retired; populated older accounts remain blocked from V2 activation
until their data is recovered through a verified migration outside the shipped client.
The complete Desktop migration and popup are tracked in the parent repository's
`docs/plan-integracion-sync-nativo.md`.

## Validation

`native-sync.openapi.json` passed `openapi-spec-validator` 0.7.2. All 13
documented operations match the route inventory emitted by `php artisan
route:list --path=api/v1 --json`; its 155 internal references resolve and all
operation IDs are unique. Evidence in Desktop: `artifacts/sync-openapi-validation.log`
and `artifacts/sync-openapi-routes-check.log`. Another 38 schema checks passed
against independent libsodium-generated envelopes and protocol examples
(`artifacts/sync-openapi-fixtures.log`). A temporary copy of the existing PHP
generator used `base_revision: "0"`: the original wide-counter crypto fixture
correctly falls outside the current API revision range. Local-storage envelopes are rejected as Sync collection writes. These checks do not replace
AEAD verification, runtime response validation or the PostgreSQL tests below.

`SyncChangeJournalTest`, `SyncOperationsTest`, `EnforceQuotaTest` and existing
storage/API/scheduled-command suites cover stable page boundaries, replay,
scope isolation, reset, partial batches, deletion, TTL, quotas and rollback.
Run these against an isolated PostgreSQL database. `SyncPostgresConcurrencyTest`
uses separate processes/connections and observes a real database lock wait to
verify commit ordering, competing CAS, rollback, shared quotas and native
activation racing legacy writes, session issuance or another activation. It refuses
to reset a database unless the application is in testing mode and its database
name starts with `midori_sync_test`. The test requires `pcntl`.

`NativeCryptoTest` covers activation, scope isolation, explicit incompatible
session revocation, rejected legacy mutation paths, metadata validation,
rotation/rewrap, bounded key history, idempotency after rotation, erasure and
schema-rollback protection. Its independent libsodium fixture lives in
`tests/Fixtures/native-sodium.json`; it does not import extension code. The full
suite passed 170 tests and 1194 assertions on isolated PostgreSQL 18.6, including
nine cases that observed an actual cross-process database lock.
