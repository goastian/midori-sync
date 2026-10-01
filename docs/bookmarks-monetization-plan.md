# Integrated Plan — Midori Library as a Standalone Service + Billing on payments.astian.org

> Revision 2 — separate service. Decision: **sync = free with a generous quota, library = separate service and the only paywalled surface**. Billing lives only on `payments.astian.org`. This repo (`midori-sync`) never touches Stripe or cards.

## 1. Target topology

```text
Authentik (single SSO, same AUTHENTIK_CLIENT_ID)
   ├── midori-sync (current, Laravel 13) → E2E sync, FREE, no paywall
   │     DB: postgres-sync | Redis DB 0 | /api/v1/sync*, /api/ext/*
   ├── midori-library (NEW service, separate repo) → Linkwarden-style bookmarks, PAID
   │     DB: postgres-library (own) | Redis DB 1 | /api/v1/library/*
   │     + worker-fetch + worker-snapshot (2 isolated containers)
   └── payments.astian.org (billing source of truth) → plans, Stripe, checkout, webhooks
         exposes: GET /api/entitlements + POST /api/billing/webhook (signed)
```

Hard rules:
- **Zero cross FKs** between sync and library. Only correlated by `authentik_id` / `email`.
- **Zero Stripe secrets** in sync or library. Only `PAYMENTS_API_TOKEN` (entitlements read) and `PAYMENTS_WEBHOOK_SECRET` (inbound webhook validation) in library.
- Sync **never returns 402**. Only library returns `402 Payment Required { code: plan_limit, upgrade_url }`.
- Self-host: `LIBRARY_BILLING_ENABLED=false` → library behaves as local Pro, never calls payments.

## 2. What lives where

| Concern | midori-sync (free) | midori-library (paid) | payments.astian.org |
|---|---|---|---|
| E2E sync of bookmarks/history/tabs/passwords | yes, untouched | no | no |
| Enriched links, tags, collections, reader, highlights, shares, RSS, import/export | no | yes | no |
| HTML/PDF/screenshot preservation | no | yes (via workers) | no |
| Plans, Stripe checkout, invoices, cancellations | no | no (cache only) | yes |
| Entitlements (plan, limits, status) | no | consumes + caches 5 min | emits |
| Extension quick-save | reuses sync token, calls library | `POST /library/links` endpoint | upgrade link |

## 3. The 2 workers (isolated from the API)

Both in the `midori-library` repo, deployed as separate compose services. They talk to the API only through the Redis queue + S3/local storage. They never receive public HTTP traffic.

### Worker A — `worker-fetch` (metadata, cheap and fast)
- Light PHP/Node image without Chromium. Horizontally scalable (3-10 replicas).
- `FetchLinkMetadata { link_id, url }` job: validates public http(s), DNS-resolves + blocks `10/8, 172.16/12, 192.168/16, 169.254/169.254, ::1`, 10s timeout, 5MB max, follows ≤3 redirects.
- Extracts: title, OG description/image, favicon, `canonical_url`, `host`, `reading_time`, `readability_html` (readable text only, sanitized).
- Per-host rate-limit (e.g. 10 req/min per domain) to avoid being banned. Retries with backoff, `metadata_status: pending→ready/failed`.
- No billing secrets. Idempotent by `user_id + canonical_url`.

### Worker B — `worker-snapshot` (preservation, expensive and risky)
- Headless Playwright/Chromium image. Few replicas (1-2), `concurrency=2`, 2GB memory limit.
- `SnapshotPage { link_id, kinds: [html|screenshot|pdf] }` job: loads the page in a credential-less sandbox, `networkidle` ≤20s, blocks `file://`, `chrome://`, private IPs.
- Produces: original `html` (scripts stripped), WebP `screenshot` at 1280px, print-to-PDF `pdf`. Uploads to S3 (`s3://library-snapshots/{user}/{link}/{kind}`) or `storage/app/snapshots` on self-host.
- `link_snapshots (link_id, kind, storage_path, mime, size_bytes)` table. Snapshot byte quota **only counts here**, never in sync.
- Separate `snapshots` queue with 24h TTL + DLQ. Only plans with `snapshots>0` may enqueue (entitlement check before dispatch).

Target compose:
```yaml
services:
  library-api:      # Laravel API + library Inertia dashboard
  worker-fetch:     # queue=fetch, 3 replicas
  worker-snapshot:  # queue=snapshots, 1 replica, shm_size 1gb
  postgres-library:
  redis-library:
```

## 4. payments.astian.org integration (minimal contract)

Payments is the source of truth. Library **never decides the plan**, it only caches it.

### 4.1 Env (library only)
```env
LIBRARY_BILLING_ENABLED=true
PAYMENTS_BASE_URL=https://payments.astian.org
PAYMENTS_API_TOKEN=xxxx            # Bearer, entitlements:read scope only
PAYMENTS_WEBHOOK_SECRET=yyyy       # HMAC-SHA256 for inbound webhooks
PAYMENTS_UPGRADE_URL=${PAYMENTS_BASE_URL}/checkout?plan=pro
LIBRARY_ENTITLEMENT_TTL=300        # 5 min cache Redis
```

### 4.2 Entitlements (pull, with safe cache + fallback)
- `GET {PAYMENTS_BASE_URL}/api/entitlements?authentik_id={sub}&email={email}` with `Authorization: Bearer {PAYMENTS_API_TOKEN}`.
- Response:
```json
{ "authentik_id": "abc", "email": "u@x.com", "plan": "free|pro|team",
  "status": "active|past_due|canceled|trialing",
  "limits": { "max_links": 100, "max_snapshots": 50, "snapshot_kinds": ["html"],
               "ai_tags": false, "rss_feeds": 3, "api": true, "collaborators": 0 },
  "current_period_end": "2026-10-18T00:00:00Z" }
```
- Library stores it in Redis `ent:{user_id}` with 300s TTL + `billing_cache (user_id PK, plan, status, limits json, synced_at)` table to degrade gracefully if payments is down.
- Payments outage: **open reads, writes under the last cached plan** (fail-closed only when there was never a cache → free). Never 500 because billing is down. Log to the `security` channel.

### 4.3 Checkout (user pushed towards payments)
- Library dashboard "Upgrade" button → `302 {PAYMENTS_UPGRADE_URL}&uid={authentik_id}&back={library}/billing/return`. Payments does OIDC against the same Authentik, so `uid` cannot be forged.
- On return, library invalidates the `ent:{user}` cache and pulls fresh. Works without webhooks (poll on return).

### 4.4 Webhooks (payments pushing towards library)
- payments → `POST {LIBRARY_URL}/api/billing/webhook` on `subscription.created|updated|deleted`, `invoice.paid|failed`.
- Headers: `X-Payments-Signature: hmac_sha256(raw_body, PAYMENTS_WEBHOOK_SECRET)`, `X-Payments-Event`. Library answers 200 fast and enqueues `RefreshEntitlement {authentik_id}` (fresh pull + counter refresh + `SecurityLog::billing.entitlement refreshed`).
- 401 on invalid signature (`sync-unauth`-style rate-limit). Idempotency via `event_id` stored 24h.

### 4.5 Enforcement in library
- `CheckLibraryEntitlement` middleware before `store/bulk/preserve/rss/share`: compares real usage (SQL counts) vs `limits`. On excess → `402 { error, code: plan_limit, limit, used, upgrade_url }`.
- Extension maps `plan_limit` → "Upgrade at payments.astian.org" banner (reuses `ext-errors.js`).
- Sync **does not install this middleware**. `SYNC_DEFAULT_QUOTA` stays as a technical anti-abuse quota, not a paywall.

## 5. Data model (postgres-library only)

```text
users_mirror (id PK, authentik_id unique, email unique, name, avatar_url)  # minimal Authentik mirror, no password
billing_cache (user_id PK FK, plan, status, limits jsonb, synced_at)
billing_events (id, event_id unique, type, payload jsonb, created_at)      # webhook idempotency
tags (id, user_id FK, name, color, unique(user_id,name))
library_collections (id, user_id FK, parent_id nullable FK, name, description, is_public, public_slug unique nullable)
links (id uuid PK, user_id FK, collection_id nullable FK, url, canonical_url, host, title, description, favicon_url, og_image_url, reading_time_min, is_read, is_archived, is_favorite, is_pinned, metadata_status, snapshot_status, tsv tsvector, created_at, last_preserved_at, deleted_at nullable)
link_tag (link_id, tag_id, composite PK)
link_snapshots (id, link_id FK, kind, storage_path, mime, size_bytes, created_at)
highlights (id, link_id FK, user_id FK, quote, note, color, anchor jsonb, created_at)
link_shares (id, link_id FK, token unique, expires_at, created_at)
rss_feeds (id, user_id FK, collection_id FK, url, last_fetched_at)
import_jobs (id, user_id FK, source, status, total, processed, errors jsonb, file_path, created_at)
```

No local `plans/subscriptions`: limits come from payments. Only `billing_cache`.

## 6. Updated roadmap (decoupled billing)

- **L0 Contracts (3-5 days):** OpenAPI `GET /entitlements` + signed webhook against a payments stub; `billing_cache` + `RefreshEntitlement` job + HMAC/idempotency tests. No scraping yet.
- **L1 Library core (2 wks):** link/tag/collection CRUD + bulk + trash + `tsv` search + Netscape import/export. Enforcement with `limits.max_links` from cache. Basic `Library/Index/Show` dashboard.
- **L2 worker-fetch (1 wk):** container deploy + SSRF guards + OG/readability + retries. `LIBRARY_FETCH_ENABLED` flag.
- **L3 worker-snapshot (1-2 wks):** Playwright + S3 + `max_snapshots/snapshot_kinds` quotas + Preservation tab.
- **L4 Full monetization (1 wk):** Upgrade button → payments, return invalidates cache, `402 upgrade_url` in the extension, `/billing` page (current plan, usage, manage-at-payments button), `billing-outage` runbook.
- **L5 Teams/RSS/API (2 wks):** workspaces + public shares + Sanctum tokens + RSS follow, with `collaborators/rss_feeds/api` limits from entitlements.

Each phase: green `composer test` + `pint`, `docs/library-*.md` docs, `CHANGELOG.md` entry. Guardrail: test fails if `STRIPE_*` or a `subscriptions` table shows up in midori-sync or midori-library.

## 7. Risks and settled decisions

- Free sync forever: avoids cannibalizing the base and keeps the commercial message simple ("free sync, pay for permanent memory").
- Double DB: +1 Postgres of ops cost, but keeps snapshot `VACUUM`s from degrading sync and allows different backup/retention (sync 90d, Pro snapshots 2 years).
- If payments is down: open reads + writes under the last known plan. Never lock users out of their knowledge over a billing outage.
- Snapshot abuse: hard byte quota + per-plan kind allowlist + queue with DLQ + `LIBRARY_SNAPSHOTS=false` kill-switch.

---
*Next step: L0 — payments stub + `billing_cache` + HMAC verification on branch `feat/library-billing-contract`, without touching sync.*
