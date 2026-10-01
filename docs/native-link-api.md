# Native Link save, browse and changes

`POST /api/library/v1/saves` adds persistent save receipts alongside the existing
Link API. The [OpenAPI contract](native-link.openapi.json) describes this route.
The native durable queue and popup save action use this route.
`native_ready` remains false.

`GET /api/library/v1/links` lists the authenticated account's current links in
descending `(created_at, id)` order. It accepts `per_page` from 1 to 50 (default
25) and an opaque `cursor` returned as `next_cursor`. A null cursor means the
last page. Results contain URL, title, a description capped at 5000 characters,
collection ID, read/archive/favorite/pin flags, tag names and timestamps. Article
HTML and snapshots are excluded. A matching index supports keyset pagination;
soft-deleted links and links owned by other accounts are excluded. The response
uses `Cache-Control: no-store` and the same selected instance and bearer session
as native saves. Desktop validates the bounded response and URL before using it.

Browsing shows the current library; the separate `GET /api/library/v1/changes`
reports edits and deletions in commit order. Desktop now applies these events
to a durable encrypted metadata cache. Automatic refresh, the full library UI
and conditional offline edits still remain to complete Link convergence.
The browse endpoint is advertised as `link.browse_version=1` and
`link.browse_page_limit=50`.

The change feed returns up to 50 link events and 524288 bytes per page, with
`generation`, `snapshot_sequence`, `has_more` and an opaque `next_cursor`.
The cursor is encrypted, bound to the account and generation, and fences a page
sequence so writes made during pagination appear on the next poll. After
applying a page, persist its cursor; continue immediately while `has_more` is
true, otherwise retain it for the next poll. A generation mismatch requires a
fresh download. Live events contain a server-owned revision and the current
metadata snapshot; deletion events carry the link ID, revision and null value.
No article HTML or snapshot is included. PostgreSQL triggers record native,
web, API, job and tag changes in the same transaction as the link write. They
also backfill existing live links when the migration runs. Events are retained
until account deletion; there is no pruning policy yet. Collection, annotation
and preservation changes are outside this link metadata feed. The capability
fields are `link.feed_version=1`, `link.feed_page_limit=50` and
`link.feed_page_bytes=524288`.

The feed passed four focused feature tests, including backfill, cursor fencing,
account isolation, rollback, tag updates and tombstones. Two process-based PostgreSQL
tests observed an actual stream-lock wait and verified commit and rollback
order. The complete Laravel suite passed 248 tests and 1971 assertions; both
OpenAPI contracts validate. Gecko consumed a save and a tombstone from two
devices, then applied them to its encrypted cache. Evidence is under
`artifacts/link-feed-*` and `artifacts/link-cache-*`.

The browse implementation passed two focused PostgreSQL tests for equal-time
keyset ordering, account isolation, soft deletion, omission of article HTML,
capabilities and malformed cursors. The complete Laravel suite passed 242 tests
and 1904 assertions, followed by the focused malformed-cursor regression.
Both OpenAPI documents validate. The native Gecko/Laravel round trip passed 136
assertions, including browsing after a save and after deletion; the separate
A/B/C/D profile run passed. Evidence is in the Desktop workspace under
`artifacts/link-browse-*`.

The Desktop client, independent encrypted local journal and durable queue are
implemented and tested against this route. The combined native integration passed
93 assertions with real Rust/Gecko, Laravel and PostgreSQL, including reopening
a pending operation before Sync E2EE setup, replaying a receipt after deletion,
and recovering a deliberately lost save response after reopening the queue.
The popup now provides explicit save consent, local/server confirmation and
pending-save management. It is tested separately against a local HTTP fixture
inside Gecko. Explicit local account access now restores the encrypted queue without HTTP,
including expired sessions; it cannot authorize delivery. The enlarged Gecko
integration passed 98 assertions against Laravel/PostgreSQL. Session renewal and
automatic scheduling were added later, under separate validation and feature
flags; these client features do not imply native readiness.

Use the explicitly selected Sync instance, including localhost. This joint
deployment accepts the same bearer session as `/api/library/links`. It does not
redirect to the official instance. Fine-grained scopes and a separate audience
for an independently deployed Link service remain pending.

```json
{
  "operation_id": "bde30c59-62d2-41bf-b530-1a86ca70a6ef",
  "url": "https://example.com/article",
  "title": "An article",
  "tags": ["reading"]
}
```

The client must persist the UUID v4 and request before sending. Keep both after
a timeout, an uncertain server error or a lost response. Retry the same request
with the same UUID. Generate a new UUID only for a distinct user operation.

The response contains only `version: 1`, `operation_id`, `link_id` and `created`.
It excludes article HTML, URL, title, tags and account data. An original creation
returns 201 with `created: true`; canonical deduplication returns 200 with
`created: false`, preserving existing fields. A replay returns the original body
and status, including after later edits or soft/hard deletion. **The receipt is
historical confirmation, not proof that the link still exists.** Replaying a
deleted save cannot recreate it. A new explicit save with a new operation UUID
can create another link after deletion. The change feed conveys later state.

Optional fields are title, collection ID and up to twenty tags. Tags are trimmed,
lowercased, deduplicated and sorted for comparison. An omitted/null optional field
has the same meaning. The URL is trimmed; changing tracking parameters still
changes the request fingerprint, even when a different operation would deduplicate
to the same canonical link. Unknown fields are ignored. A used UUID with different
effective data returns `409 idempotency_conflict`, without changing the old receipt
or the library. UUIDs are scoped to the account, not the device.

The body is limited to 32768 bytes, URL/title to 2000 characters and each tag to
64 characters. Capabilities expose `link.save_version`, `link.save_request_bytes`
and `link.save_receipts_per_account`. The limit is 100000 receipts per account;
new operations then return `409 operation_capacity`. Existing receipts remain
replayable. There is no automatic receipt expiry or pruning: deleting them would
allow an old client to recreate data. Safe retirement needs a future generation
or equivalent protocol. Do not roll back/drop the receipt table while clients
can replay these operations. Deleting the account removes its receipts by FK.

Before mutation, the service normalizes the URL and gets entitlement limits.
Within one transaction it locks the account, checks the receipt again, checks
receipt capacity, validates collection ownership, deduplicates and checks link
quota, then writes the link, tags and receipt. A successful receipt lookup avoids
DNS, billing and current collection lookups. A failed quota/ownership/URL check
does not reserve the UUID. Rollback also discards metadata-job callbacks. These
callbacks run after commit; durable recovery of a crash before dispatch still
needs the planned job outbox.

Success and domain-error responses use `Cache-Control: no-store`. The backend
retains its current processed Link mode: it can read the saved URL and title.
Loopback API access does not relax the existing fetch-target restrictions.
This change does not complete SSRF hardening, a private Link mode, the full
offline library UI, conditional editing/deletion, undo, or server-assigned link ID migration.

Validation: `npm run test:sync` passed 188 PHP tests / 1393 assertions against
isolated PostgreSQL. Nine save-contract tests cover historical receipts, edited
and deleted links, conflicts, ownership, cross-account UUID reuse, quota,
rollback, malformed/oversized requests and all 100000 receipt slots. Three new
process-based concurrency cases observe an actual account-row lock for replay,
conflict and rollback. Evidence: `artifacts/sync-link-receipts-postgres.log` in
the Desktop workspace. The test launcher stopped its PostgreSQL instance.
