# Midori Sync Protocol — API Surface

> ADR-001. Architectural decision regarding the coexistence of `/api/ext`
> and `/api/v1`. Complements `docs/api.md` (canonical MSP reference) and
> `docs/architecture.md`.

## Context

There are currently two HTTP surfaces in `routes/api.php`:

- `/api/v1/*` — Full Midori Sync Protocol (MSP). Canonical and versioned
  server surface.
- `/api/ext/*` — Simplified adapter for the extension (flat BSO format,
  OAuth callback, pairing).

The question was whether both should coexist, or whether one should absorb
the other. This document establishes the decision.

## Decision

`/api/v1` is the canonical and long-term surface. Any new client
(CLI, mobile, test harness) must consume `/api/v1`.

`/api/ext` remains the **official extension adapter** on top of the same
backend (`SyncStorageService`, `SyncAuthService`). It is not a parallel
engine: it is a thin transport layer designed for the browser environment
(MV2 manifest, no `X-If-Unmodified-Since` headers, no granular batch
conflicts, no deep linking).

It is maintained because:

- The flat format `[{id, payload, modified}, ...]` is cheaper to generate
  in the browser and is frozen for compatibility with the local store.
- The extension OAuth flow (`/api/ext/auth/start` + `poll`) has distinct
  properties: it is a handshake flow that does not exist in `/api/v1`.
- It decouples MSP evolution from extensions already installed by users.

## Golden Rules

1. **Single backend.** Both surfaces must delegate to
   `App\Services\SyncStorageService` and `App\Services\SyncAuthService`.
   Business logic duplication in `Api\Ext` controllers is not allowed.
2. **`/api/v1` first.** Any new capability (collections, delta sync,
   quotas, etags) must first be specified and validated in `/api/v1`.
3. **`/api/ext` only adapts.** If `/api/ext` requires a new endpoint,
   it must be a projection of an existing `/api/v1` endpoint, not an
   exclusive feature.
4. **No breaking changes in `/api/ext`.** As long as distributed extensions
   exist, the flat BSO contract remains stable. Incompatible changes require
   an `/api/ext/v2`.
5. **Shared middleware.** Both surfaces use `ValidateSyncToken`,
   `TrackDevice`, `EnforceQuota`, and `CorsForExtension` where applicable.

## Mapping Table

| Operation              | `/api/v1`                         | `/api/ext`                         |
|------------------------|-----------------------------------|------------------------------------|
| OAuth exchange         | `POST /auth/token`                | `GET /auth/start` + `/auth/poll`   |
| Revoke token           | `DELETE /auth/token`              | `POST /logout`                     |
| List records           | `GET /collections/{name}`         | `GET /storage/{collection}?newer=` |
| Single upsert          | `PUT /collections/{name}/{id}`    | (does not exist — batched instead) |
| Batch upsert           | `POST /collections/{name}`        | `POST /storage/{collection}`       |
| Delete record          | `DELETE /collections/{name}/{id}` | (next: `deleted: true` flag)       |
| Sync information       | `GET /sync/info`                  | `GET /storage/info`                |
| Collection status      | `GET /sync/status`                | `GET/POST /sync/status`            |
| Pairing (generate)     | —                                 | `POST /pair`                       |
| Pairing (redeem)       | —                                 | `POST /pair/redeem`                |
| Crypto key bundle      | `GET/POST /crypto/keys`           | (same route, mounted under `ext`)  |

## Conditional Headers

`/api/v1` implements `ETag` + `If-None-Match` on cacheable read endpoints
(`GET /sync/info`, `GET /sync/status`,
`GET /collections/{name}`). It also accepts `If-Modified-Since` when the
response includes a defined `last_modified`.

`/api/ext` currently **does not** implement etags: the extension uses
delta sync through `newer=` instead. If needed, it can be introduced in
an MSP v2.

## Compression

Gzip compression is available as an optional middleware
(`NegotiateCompression`) controlled by `SYNC_HTTP_COMPRESSION=true`. By
default it is disabled because standard nginx deployments already gzip
responses from the PHP-FPM upstream. Enabling it in PHP only makes sense
in deployments without nginx or without a reverse proxy.

## Rate Limiting

The `sync` limiter applies to `/api/*` and differentiates between reads
(more permissive) and writes (more restrictive), with separate keys so
that a burst of reads does not consume the write quota:

- `SYNC_RATE_LIMIT_READ` (default 120/min) — `GET`, `HEAD`, `OPTIONS`.
- `SYNC_RATE_LIMIT_WRITE` (default 60/min) — `POST`, `PUT`, `PATCH`,
  `DELETE`.

The legacy variable `SYNC_RATE_LIMIT` is still respected as a fallback for
both.

## Criteria for Deprecating `/api/ext`

`/api/ext` may be removed when:

1. The extension supports Manifest V3 and an equivalent `/api/v1` MSP
   client.
2. The extension OAuth flow is rebuilt on top of `/api/v1` (with a
   documented handshake endpoint).
3. No supported ecosystem installations remain using the flat BSO contract.

Until then, `/api/ext` remains a first-class API, although its public
contract is narrower than `/api/v1`.
