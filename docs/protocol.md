# Midori Sync Protocol — API Surface

> ADR-001. `/api/v1` is the sole Sync API. See [api.md](api.md) for the
> protocol and [native-sync-api.md](native-sync-api.md) for native-client
> transactions and migration rules.

## Decision

Midori Desktop, the dashboard, CLI clients and test harnesses use the
versioned `/api/v1` Sync API. Authentication, device pairing, collection
changes, encrypted records, key lifecycle and revocation share the same Sync session identity.

The former browser add-on and its `/api/ext` adapter have been removed. The
server no longer accepts requests from that client. A native importer is
required before profiles with add-on data can resume synchronization; the
client blocks native writes until the migration is verified. Existing
encrypted server records are not discarded by removing the adapter.

## Contract rules

1. New capabilities are specified and tested on `/api/v1`.
2. Incompatible payload or cryptographic changes require an explicit
   version or capability negotiation. Legacy ciphertext must never be
   reinterpreted as native V2 ciphertext.
3. Quotas are enforced under the same account transaction as writes.
4. Collection writes append immutable snapshots to the change journal.
   Native clients consume opaque cursors and conditional operations.
5. A profile and account have one active Sync writer. Migration must
   preserve bookmarks, history and passwords before cutover.

## HTTP behavior

`GET /api/v1/sync/info`, `GET /api/v1/sync/status` and
`GET /api/v1/collections/{name}` support `ETag` and `If-None-Match`.
`If-Modified-Since` is accepted when the response defines `last_modified`.

`SYNC_RATE_LIMIT_READ` defaults to 120 requests per minute for `GET`,
`HEAD` and `OPTIONS`; `SYNC_RATE_LIMIT_WRITE` defaults to 60 for writes.
`SYNC_RATE_LIMIT` remains the fallback. Unauthenticated pairing and session
refresh use the separate per-IP `sync-unauth` limiter.

Gzip compression is optional through `SYNC_HTTP_COMPRESSION=true`. It is
disabled by default because standard nginx deployments already compress
responses from PHP-FPM.

