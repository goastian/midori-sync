# Native Sync API: change feed and operations

Desktop delivery scope (2026-10-23): bookmarks, history, passwords, credit cards,
form history, addresses, tabs and portable preferences. Desktop temporarily disables
workspace, extension, extension-settings and other additional adapters while retaining
their implementations for a later update. Server capability support remains available;
it does not enable these features in Desktop. Account collections remain shared across
Desktop and Mobile; native Mobile interoperability still requires its own client tests.

Local verification (2026-10-23): 255 backend tests and 3037 assertions on isolated
PostgreSQL; eight real Desktop browser processes and 153 checks over HTTP, plus
six form-history/address processes and 222 checks. The Desktop primary delivery
report records source hashes, native/UI regressions and the intentionally deferred scope.
These results do not certify staging, a Mobile binary or other desktop platforms.


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

## Shared Desktop and Mobile account

Desktop and Mobile use the same account-scoped collection streams. Device type is metadata;
it does not partition bookmarks, history, passwords, cards, form history, addresses, workspace metadata, tabs or
portable settings. Each installation owns its session, device ID and authenticated cursors.
Tabs intentionally retain a separate snapshot per device inside the shared `tabs` collection.
Clients must agree on plaintext schemas and native cryptographic/identity vectors before
exchanging real data; successful opaque transport alone does not certify Mobile integration.
`ClientCustodyTest` exercises both directions using `desktop` and `mobile` sessions for all
nine collections, including preservation of encrypted tombstones.

Authenticated read and write rate-limit buckets belong to the account. Different accounts
behind the same IP do not consume each other's authenticated allowance; devices and
sessions of one account share its allowance even when their IPs differ. Authentication
runs before the API limiter, with CORS wrapping both so allowed clients can read 401/429
responses. Pairing and renewal keep their separate per-IP unauthenticated limit. Missing
or invalid bearer tokens on protected routes consume a separate failed-authentication
bucket per IP, using `services.sync.unauth_rate_limit` over 60 seconds; after exhaustion
further lookups stop and return uncacheable `429 rate_limited` with `Retry-After`.
`RateLimitTest`, `UnauthRateLimitTest` and `CorsTest` cover this ordering and isolation.

The `change_page_byte_budget` feature allows `GET /sync/collections/{name}/changes` to accept
`max_bytes` from 32768 to 4194304. The server counts serialized changes including JSON
escaping and reserves 16 KiB for cursor and response metadata. Desktop negotiates 2097152
bytes, matching its engine limit. Follow `has_more` and `next_cursor` even when a page has
fewer rows than `limit`; local encrypted pages can also end early at their byte limit.
Older clients can omit the parameter. A record that cannot fit individually returns 413.

Loss of client keys never authorizes an automatic data reset. The explicit `/crypto/reset`
flow still requires confirmation. Its session and crypto revision are rechecked inside the
same account-locked storage transaction that removes the records and keys; a concurrent
revocation or newer revision prevents deletion. Reset also invalidates outstanding device
enrollments so an approval from the previous key epoch cannot be reused.

## Client custody extension (2026-10-15)

`crypto_custody_version: 1`, `record_schema_versions: [1, 2]` and
`client_recovery.version: 1` and `client_recovery.registry_auth_version: 2` advertise the H1 extension. `native_ready` remains
false. The earlier descriptions below are historical where they conflict with
this extension; clients must negotiate this extension before writing.

New activation requires `identity_key: {key_id, encrypted_bundle}` and
`registry_auth` (64 lowercase hexadecimal characters) and `registry_auth_version: 2`, in addition to the data
key and current crypto context. `custody` may be explicitly `client`;
`recovery_secret` is prohibited. The identity key is separate from the eight
data-key slots and cannot share a data-key ID. Once registered, its ID cannot
change during rotation. Its bundle may be rewrapped under a new client-only
recovery code. The server receives no such code or equivalent opening secret.

State adds `custody` (`server`, `transition`, `client`), `identity_key`,
`registry_auth`, `registry_auth_version` (1 or 2, including empty state), and
nullable `rotation`. Existing accounts retain version 1 and their unchanged tag;
legacy server custody retains its existing encrypted recovery secret until migration.
The version-2 client MAC authenticates the UTF-8 JSON array:

```text
["MidoriSync/key-registry/v2", epoch, revision, active_key_id, identity_key,
 sortedDataKeys, custody, server_recovery, stableRotation]
```

`revision` is the canonical decimal **string** from the resulting state. For each
activation, rotation start, completion, or retirement, Desktop signs current
`crypto.revision + 1`, and the backend atomically commits that revision, tag and
version. Keys are sorted by ASCII key ID. `stableRotation` is null or an object
with properties in this order: `operation_id`, `target_key_id`, sorted
`source_key_ids`, and `phase`. Identity/data bundle objects use `key_id` followed
by `encrypted_bundle`. Operational progress counters remain excluded.

Version 1 uses `"MidoriSync/key-registry/v1"` and omits `revision`; it is read-only
compatibility for modern Desktop until its next authorized mutation publishes
version 2. Old clients may omit the request version (equivalent to 1) only while
the stored registry is still version 1. A mutation cannot downgrade version 2:
`409 registry_auth_downgrade`. Reconciliation of an already published operation
returns current state without mutating its version or revision. A rotation's
idempotency fingerprint includes version 2; existing version-1 fingerprints remain
compatible. The additive schema migration refuses rollback after version-2 use.

The server stores the tag opaquely; the client verifies it using the identity key
before trusting the registry and persists a version/revision floor in its local
vault. A hostile server cannot replay an old valid version-2 registry while
inflating its revision. A new profile without a trusted checkpoint still has no
independent global freshness proof. A valid envelope shape alone does not
demonstrate recoverability or AEAD.

`GET /crypto/recovery` and `POST /crypto/recovery` always reject with
`409 server_recovery_disabled`; no session, configuration switch or migration
header can disclose the existing escrow or create new escrow. Legacy migration
requires the user's existing recovery code or a device retaining its keys.
Application-key backup/rotation remains necessary for retained legacy escrow
and session receipts.
Deleting escrow does not erase historical backups or knowledge already held by
the server. Migrating away from custody requires new data keys and reciphering.

### Durable rotation

`POST /crypto/rotation` accepts `crypto`, `operation_id`, the new `key_id` and
`encrypted_bundle`, the complete `keys` array (old keys rewrapped plus the new
one), `identity_key`, `registry_auth`, and `registry_auth_version: 2`. It atomically makes the new key active,
sets custody to `transition`, and retains old escrow until completion. Exact
replay of the same operation and request recovers current state; a changed body
with the same operation ID fails. A second rotation cannot start while the first
is rewriting. Old standalone rotate/rewrap endpoints now reject writes because
they cannot preserve the authenticated registry.

`GET /crypto/rotation` returns the same full state as `/crypto/state`. Its rotation
contains `operation_id`, `target_key_id`, `source_key_ids`, `phase`
(`rewriting` or `complete`), and integer `live_records`/`journal_records` counts.
`GET /crypto/rotation/records?limit=100&cursor=...` returns
`{rotation_id, records, has_more, next_cursor}`; each entry has `collection`,
`generation` and the ordinary `record`. The account/rotation-bound encrypted
cursor pages current records needing reciphering, including legacy tombstones
and live `h1:` history IDs. Repeat from a null cursor after a pass to catch
concurrent changes. The client must authenticate and transform each record,
persist its work, then upload with ordinary CAS operations. Empty legacy
tombstones cannot be transformed into authenticated deletes based solely on the
server response; clients must quarantine them or reconcile against trusted local
state. A page is not an
acknowledgement and has no cryptographic authority by itself.

`POST /crypto/rotation/history-target` takes `{id, generation}` and returns
`{generation, rotation_id, record}` (record is null when absent). It checks the
live session/device/identity under the account lock and requires a rewriting
rotation. The client authenticates this target before merging a collision.

`POST /crypto/rotation/history-id` atomically migrates a live historical locator.
It accepts `crypto`, `operation_id`, `rotation_id`, `generation`, `source_id`
(`h1:` plus 64 hex), `source_revision`, `target_id` (`h2:` plus 64 hex),
`source_payload` (schema-2 tombstone), `target_payload`, `target_revision`
(default `"0"`, meaning absent), `target_deleted` (default false) and `target_ttl`.
Both revisions are CAS preconditions. Existing target deletion must be preserved;
otherwise its authenticated content is merged by the client. Live target TTL is
the earliest non-null current source/target TTL, including expired timestamps;
null means infinite. Deleted target TTL is null. A client may turn an expired
record into an authenticated tombstone rather than extend its lifetime.

The source replacement happens before the target write in the same transaction,
so quota needs only final payload sizes. Both changes and the exact replay receipt
commit together; any failure rolls everything back. Response includes both new
revisions and `target_deleted`. Source mismatch returns `history_source_conflict`;
wrong target revision returns `history_target_conflict` (`history_target_exists`
when expected absent). `history_target_deleted` prevents resurrection. Exact
receipt replay survives completion/crypto revision changes with the same epoch,
device and request. Receipts from the earlier request format remain replayable.
The old locator tombstone must not erase visits moved to its new locator.

`POST /crypto/rotation/complete` takes `crypto`, `operation_id`, `registry_auth_version: 2`, and a new
`registry_auth` authenticating custody `client`, `server_recovery: false` and
rotation phase `complete`. It requires zero current records needing
reciphering/ID migration and atomically replaces the tag, removes old escrow,
and changes custody and phase. **It does not retire old data-key bundles.**
Those may still be needed to open retained journal entries. Exact completion
replay recovers state without advancing revisions or overwriting its tag again.

`DELETE /crypto/native-keys/{keyId}` takes `crypto`, `registry_auth_version: 2`, and a new `registry_auth` for
the remaining registry. It refuses active/identity keys, unfinished transitions,
and any key referenced by current records or retained journal snapshots. Unknown
or malformed historical ciphertext blocks retirement conservatively. Journal
compaction remains a separate prerequisite for retiring used keys; rotation
completion is not a claim that all old keys or historical ciphertext disappeared.

### Authenticated record controls

Client/transition writes require `context.schema_version: 2`. The AEAD plaintext
wraps `{version: 2, deleted, ttl, value}`. Encrypted tombstones retain a nonempty
payload in both records and change snapshots. The server validates only public
envelope metadata, never plaintext. The receiver verifies inner `deleted` and
`ttl` against outer metadata before applying anything. TTL is normalized to UTC
whole seconds by the transport; clients must compare canonical instants.

The server expiry job never manufactures tombstones for client/transition
accounts, because it cannot authenticate them. Clients enforce authenticated TTL
while the ciphertext remains retrievable. Retained ciphertext, including encrypted
tombstones/expired client records, counts toward record quota; journal/receipt
physical storage still needs its separate retention budget.

### Authenticated global controls

Capabilities advertise `authenticated_controls`, `journal_compaction`,
`client_recovery.authenticated_controls: true`, `compaction_version: 1` and
`history_reconciliation_version: 1`. `collections` lists all fifteen streams,
including `form-history`, `addresses`, `workspaces`, `site-permissions`, `extensions` and `extension-settings`; their native KDF indices are 12, 13, 14, 15, 16 and 17.
Credit cards retain index 11. Workspace metadata requires `workspace_metadata` and
`data_schemas.workspaces: 1`; its encrypted value is exactly `{version: 1, name, icon}`
under a stable `w1:<uuid>` record ID. It does not contain a window ID, selected workspace,
default role or native tab membership. Desktop materializes received metadata automatically
in normal windows; an in-use deletion or a 25-workspace capacity limit becomes a visible
conflict. The shared contract is [workspace metadata](workspace-metadata.md). Form values, names and address fields stay inside
ciphertext, never in public record IDs.

Custom blocking text uses `midori-privacy`, `data_schemas["midori-privacy"]: 1`
and the explicit `custom_filters` feature. Its manifest and account-derived
immutable parts follow the [shared custom-filter contract](custom-filters.md).
This capability does not cover catalog list choices or site permissions.

Portable site blocks and exceptions use the independent `site-permissions`
collection, generation 1 and `site_permissions` feature. Sensitive grants remain
local. The [site-permission contract](site-permissions.md) specifies identities,
permitted actions, expiry, conflicts and persistence requirements. Desktop
integration is still in development; server capabilities do not certify clients.

Official extension references use `extensions`, `data_schemas.extensions: 1`
and `extension_catalog`. The [extension contract](extensions.md) defines the
closed catalog-ID schema, explicit uninstall provenance and local permission
confirmation. Desktop integration is in development.

Extension configuration has its independent `extension-settings` collection,
`data_schemas["extension-settings"]: 1`, feature `extension_settings` and KDF 17.
The [configuration contract](extension-settings.md) defines extension-scoped
canonical JSON, encrypted parts/manifests, per-key merges and atomic native
replacement. It covers only data an eligible extension stores in `storage.sync`.
Installation, permission grants and other extension storage keep their own local
rules. The Desktop adapter is under validation; Mobile support is not certified.

A control contains exactly `version: 1`, `epoch`, `collection`, `operation_id`,
`previous_generation`, a new client-chosen `generation`, `control_revision`
(decimal string), `kind`, `clear_before_ms` (integer) and `proof` (64 lowercase
hex). The revision is the current control revision plus one; generation and
revision are checked atomically. The client identity `devices` subkey authenticates
the UTF-8 JSON array `["MidoriSync/control/v1", epoch, collection, operation_id,
previous_generation, generation, control_revision, kind, clear_before_ms]` using
the native identity derivation. PHP/libsodium compatibility vectors are in
`tests/Fixtures/h1-controls.json`. The server stores the MAC opaquely; the receiver
must verify it and its locally anchored revision before deleting native data.

`POST /sync/collections/history/clear` adds `control` to the existing request.
It must have kind `history_clear`, a positive UTC millisecond cutoff and matching
outer operation ID/previous generation. The response includes that exact control.
`DELETE /sync/data` takes `{crypto, operation_id, controls}` with one
`collection_clear` control for every advertised collection, all sharing the
operation ID and `clear_before_ms: 0`. It atomically clears records and old journals,
returns `{operation_id, controls, deleted_records}`, and preserves the crypto
epoch, key registry, identity and recovery bundles. These operations have durable,
device-bound exact replay receipts. Changed receipt content fails.

Every change page includes `control_revision` and `control` (initially `"0"` and
null). Generation conflicts return `409 reset_required` plus those fields and
current generation, allowing verification before any local reset. History-clear
pages also retain the compatibility `reset: {kind: "history_clear", clear_before}`
marker, with exactly the millisecond cutoff in the signed control. The client
checks that equality; the unsigned marker alone never authorizes deletion. Unsigned or
replayed controls never authorize deletion. Protected requests without a control
fail with `authenticated_control_required`. Legacy server-custody accounts retain
the old unsigned routes. Administrative dashboard deletion remains an explicit
owner operation; it cannot create a proof authorizing native erasure.

### Journal compaction and key retirement

`POST /sync/collections/{name}/compact` takes `{crypto, operation_id, generation,
snapshot_sequence}` and returns `{operation_id, generation, compacted_through,
snapshot_sequence, records}`. It requires client custody and current sequence CAS
(`snapshot_conflict` on a concurrent change). Every current record must use the
active schema-2 key; otherwise it fails with `rotation_records_pending`. It
replaces old changes with current ciphertext snapshots, including authenticated
tombstones, while preserving record revisions, generation and operation receipts.
The new snapshots receive monotonically increasing feed sequences.

An old cursor automatically rebases to this snapshot and returns
`snapshot_rebased: true`, plus `compacted_through`. Its page fence excludes later
writes until the following pass. The client keeps native data/outbox and applies
these records by their original revisions; rebasing never authorizes a local
clear. Fresh devices can bootstrap using only the current key. Offline cursors
remain usable. Compaction receipts survive key retirement for lost-response
recovery. After compacting every advertised stream, the client can retire unused
old keys through the existing reference checks; tests rotate beyond eight keys
without increasing the eight-slot limit. Authenticated tombstones remain retained;
physical receipt/tombstone retention still needs a production storage policy.

### Validation and local integration

Use an isolated PostgreSQL database named `midori_sync_test_*`; the shared test
base checks this before `RefreshDatabase` can run. Never execute schema resets
against the live service database. Apply the H1 migration incrementally to an
existing installation, not with `migrate:fresh`.

For tests against an already running loopback service on port 8000,
`php artisan sync:test-account --fresh` creates a uniquely marked synthetic
`h1.*@local.invalid` account and writes four short-lived pairing codes into
`/tmp/midori-sync-h1-fixture.json` with mode 0600. It validates the running issuer
and custody capability, does not start another server, and never prints codes.
Without `--fresh`, it renews codes for only the account identified by that private
fixture, preserving data for continuation. `--fresh` does not delete previous
synthetic accounts or touch real users. Archive logs without including this file.

For the opt-in legacy migration test, `sync:test-account --seed-legacy-deletion`
requires that existing fixture to be private (0600), still identify its synthetic
account/database/issuer, and refer to an activated client account before its first
rotation. It injects one explicitly synthetic empty legacy bookmark tombstone and
adds `legacyDeletion` metadata to the fixture, without renewing pairing codes or
altering any other account. Production endpoints continue rejecting such writes.

The [encrypted backup/restore check](backup-restore-verification.md) demonstrates
restoration of synthetic PostgreSQL data while preserving client custody and
keeping backup, application and client recovery keys separate.

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
| POST | `/sync/collections/{name}/compact` | CAS compaction into current ciphertext snapshots |
| POST | `/sync/collections/history/clear` | Authenticated cross-device history clear |
| GET | `/crypto/state` | Native key registry, activation state and conditional-write epoch/revision |
| POST | `/crypto/activate` | Activate V2 for an empty account with explicit handling of incompatible sessions |
| GET/POST | `/crypto/recovery` | Disabled; never disclose or create server recovery escrow |
| GET/POST | `/crypto/rotation` | Read state or start a resumable authenticated key rotation |
| GET | `/crypto/rotation/records` | Page current records requiring reciphering or history-ID migration |
| POST | `/crypto/rotation/history-target` | Read the authenticated collision target during migration |
| POST | `/crypto/rotation/history-id` | Atomically reconcile both history locators with CAS |
| POST | `/crypto/rotation/complete` | Finish current-record migration and remove legacy escrow |
| DELETE | `/crypto/native-keys/{keyId}` | Retire a key only without current or journal references |
| POST | `/crypto/rotate` | Disabled; use resumable rotation |
| PUT | `/crypto/native-keys/{keyId}` | Disabled; use resumable rotation |
| POST/GET | `/crypto/enrollments` | Request device enrollment (creator) or list live pending requests (approvers) |
| GET/DELETE | `/crypto/enrollments/{id}` | Creator polls status/result, or cancels and erases the row |
| POST | `/crypto/enrollments/{id}/approve` | Another device relays an opaque end-to-end encrypted payload |
| POST | `/crypto/enrollments/{id}/deny` | Another device rejects a pending request |
| DELETE | `/sync/data` | Authenticated multi-collection clear; legacy reset also erases key bundles |

The collection routes require a valid bearer session bound to a device
owned by the account. They use the authenticated session's device, never a
device identifier supplied in the operation body. Legacy sessions without a
device receive `409 device_required`. Responses use `Cache-Control: no-store`.

Pairing codes are stored as SHA-256 hashes, expire after the configured TTL and
are consumed in the same transaction that creates the device and session.
Device names are labels, not identity keys. Each redemption receives a new UUID.
Pairing is available through `/api/v1/pair` and `/api/v1/pair/redeem`.
Pairing establishes a session. Recovery additionally requires the user's code or
an authenticated transfer from an authorized device; the server cannot provide it.

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

## Device enrollment

`crypto.enrollment: true` in `/capabilities` advertises device enrollment: a
signed-in device B without keys asks an unlocked device A of the same account
to approve it. The server only relays opaque data and never sees keys. All
routes require a valid bearer, are scoped to the account and use `throttle:sync`.

Public keys (`public_key`, `approver_public_key`) are base64 or base64url of a
65-byte uncompressed P-256 point (`0x04` prefix), at most 128 characters.

| Request | Response |
| --- | --- |
| `POST /crypto/enrollments` `{public_key, device_name?<=100}` | `201 {id, status:"pending", expires_at}` |
| `GET /crypto/enrollments` | `200 {enrollments:[{id, public_key, device_name, created_at, expires_at}]}`, pending and unexpired only, never payloads |
| `GET /crypto/enrollments/{id}` (creating session only) | `200 {id, status, expires_at}` with `status` one of `pending`, `approved`, `denied`, `expired`; when approved also `approver_public_key` and `payload` |
| `POST /crypto/enrollments/{id}/approve` `{approver_public_key, payload<=16384}` | `204` |
| `POST /crypto/enrollments/{id}/deny` | `204` |
| `DELETE /crypto/enrollments/{id}` (creating session only) | `204`, erases the row and payload |

Requests live for 10 minutes. An account has at most three live pending
requests (`429 too_many_enrollments`); a new request from the same session
replaces that session's previous one. Unknown ids, other accounts and, for
read/cancel, other sessions return `404 enrollment_not_found`. Approving or
denying from the creating session or device returns `403 self_approval_forbidden`;
a request that is not pending or has expired returns `409 enrollment_not_pending`.
Denial clears any payload. Expired rows (and their payloads) are removed by
`sync:cleanup-expired`; expired rows are never served. Creating a request also
emits a change hint on the account's notification stream so approvers wake up.

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
`deleted: true`; historical server-custody accounts omit the payload or send an
empty one, while client/transition custody requires the authenticated schema-2
tombstone payload. TTL is optional. The
server never decrypts record contents. In native mode it validates the public
envelope metadata described below before accepting a new operation.


## Storage and transition

### Native key registry and write barrier

Capabilities advertise `crypto_state_version: 1` and native `crypto_versions: [2]`.
`native_ready` remains false. All native key endpoints require a live version-2
session, an owned device and a bound account identity. They recheck the session
inside the account transaction and lock its row against concurrent revocation.
They return `Cache-Control: no-store`. New native activation uses client custody
as specified above. Historical escrow remains encrypted under the Laravel
application key until migration completes; protect and back up `APP_KEY` for
this retained material and authentication receipts.

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
  "server_recovery": false,
  "custody": "server",
  "identity_key": null,
  "registry_auth": null,
  "registry_auth_version": 1,
  "rotation": null,
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

Activation and rotation requests include these common fields, in addition to
the required identity, registry authentication and rotation fields specified above:

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

Historical native activation uploaded `recovery_secret`; this is now prohibited.
`server_recovery` advertises whether the current epoch still has historical
escrow. Both recovery endpoints reject all authenticated requests; restoring
keys requires a user-held code or an existing device with the local keys.

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
conditional tombstone contract. Client/transition tombstones additionally carry
authenticated controls, and server TTL cleanup cannot emit tombstones for them.

`POST /crypto/rotate` and `PUT /crypto/native-keys/{keyId}` now reject writes with
`409 durable_rotation_required`; use the resumable rotation contract above.
Reusing a registered ID for rotation returns `409 key_id_exists`; exceeding eight
retained data keys returns `409 key_capacity_reached`. Identity has its own slot.
Safe retirement checks both current records and retained journal snapshots.

For historical server-custody accounts, `DELETE /sync/data` takes the current
`crypto` object. Client/transition custody requires the signed controls described above.
The historical route removes Sync records,
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
the retained log for bootstrap, or the current-record snapshot after compaction.

All service writes lock the account before reading revisions, quota or the
sequence. PostgreSQL holds that row lock until commit, so a higher sequence
cannot commit ahead of a lower one in the same account. Counter, record, receipt
and change are committed together; rollback leaves no committed sequence gap.
Different accounts remain independent. This serialization favors correctness;
measure contention before optimizing the lock scope.

Feed reads and acknowledgements recheck the live version-2 session, owned device
and bound identity after acquiring the same account lock. A request admitted by
middleware before session revocation cannot read or acknowledge after that
revocation commits. Key-registry reads and rotation-record export use this same
transactional authorization check.

Legacy upsert/batch/delete and expiry paths append to this same journal.
Collection deletion and TTL cleanup now retain versioned tombstones. A full
account data wipe deletes payloads and rotates stream generations. The journal
retains tombstones after compaction; production retention and physical storage
budgets remain separate from the safe key-retirement mechanism.

Legacy accounts still permit unconditional writes through the old API. Fresh-account native
activation closes those paths transactionally. The extension-specific conversion
routes were retired; populated older accounts remain blocked from V2 activation
until their data is recovered through a verified migration outside the shipped client.
The complete Desktop migration and popup are tracked in the parent repository's
`docs/plan-integracion-sync-nativo.md`.

## Validation

H1 validation on 2026-10-15: the PostgreSQL suite passed 203 tests and 1801
assertions (`artifacts/h1-backend-full-verified.log`). After the additional
history TTL/replay and malformed-ciphertext retirement fixes, the three affected
suites passed 32 tests and 584 assertions
(`artifacts/h1-backend-final-regression.log`). Final focused checks after the
quota and session-revocation fixes:
54 crypto/concurrency tests with 832 assertions
(`artifacts/h1-session-revocation-regression.log`), 28 HTTP feed/operations/history
tests with 221 assertions (`artifacts/h1-feed-revocation-regression.log`), and
16 custody tests with 254 assertions (`artifacts/h1-custody-final-regression.log`).
The concurrency cases admit a request before revocation, verify an actual
PostgreSQL lock wait, and distinguish committed revocation from rollback.
These tests validate server
transactions and envelope metadata; they do not prove native client AEAD.
The updated OpenAPI JSON has 292 resolving references and 28 unique operations;
27 match Laravel routes and the stream is served by its notification daemon
(`artifacts/h1-openapi-final-review.log`). A formal OpenAPI validator was not available
for this revision.

The pre-H1 `native-sync.openapi.json` passed `openapi-spec-validator` 0.7.2. All 13
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


### H3 data schemas and compatibility

Capabilities advertise `bookmark_labels`, `history_parts`, `history_visit_removals` and
`tab_fragments`, and `data_schemas` (highest client plaintext generation per collection:
`bookmarks`, `history` and `tabs` are 2, the others 1). The server never reads those fields: a
bookmark `tags`/`keyword`, a history `part`/`removed`, or a tab manifest/fragment is opaque
ciphertext. Record identifiers stay opaque strings of at most 255 bytes; tab fragments use
`<device-uuid>:<index>` and history parts use the usual `h2:` digest of
`[url, day, partStart, partLength]`. Credit cards are never exposed by the web dashboard.

Older clients reject unknown plaintext fields and quarantine the record; they keep their local
data and resume when updated. Clients must not publish the new fields while `data_schemas`
is absent or lower than their generation.

### Account-password key capsule (version 1)

Capabilities advertise `crypto.password_unlock_version: 1`. `CryptoState.password_unlock` indicates a capsule matching the current epoch and key IDs. `PUT /crypto/password` stores `{crypto:{epoch,revision},capsule:{version:1,key:{key_id,encrypted_bundle},payload}}` under the account lock after session and revision checks. Requires client custody without active rotation. Maximum request 65536 UTF-8 bytes; capsule payload 49152 bytes, protecting at most 32768 plaintext bytes. It rejects plaintext `password` and `recovery_secret`, does not advance the registry revision and never rewrites data records.

`GET /crypto/password` returns `{epoch,capsule}` or `409 password_backup_unavailable`, with `Cache-Control: no-store`. Rotation/key retirement invalidates stale capsules; explicit crypto reset deletes them. The envelope must use native v2 `purpose:local`, collection `browser-settings`, ID `sync-password-backup-v1`, generation=crypto epoch, schema 1 and base revision `0`. The password wraps a separate random native key using UTF-8 `MidoriSync/account-password/v1\0` + the exact unnormalized password, with the standard Argon2id/native bundle. The payload protects local wrapping material, current data/identity bundles and an optional known recovery code. Clients verify AEAD, scope, epoch, key IDs and the authenticated registry before saving recovered keys.

OIDC never supplies the password: users enter it locally. Existing accounts need retained local keys or their recovery code to configure the capsule. An Astian password change needs an explicit capsule update from an unlocked profile; an old capsule may still need the previous password. Rewrapping does not revoke historical keys or backups; compromise requires key rotation. Offline guessing and an identity provider that captures the password are part of this mode’s threat model. Desktop/Mobile byte contract and migration: [account password design](../../docs/sync-clave-cuenta.md).
