# Midori Sync — Runbooks and SLOs

> Operational procedures for incidents, scheduled maintenance, and
> critical rotations. Audience: Midori Sync backend operators.
> For architecture see [architecture.md](architecture.md); for
> security see [security.md](security.md).

---

## 1. SLOs

### 1.1 Availability

| Service                                  | SLO    | Window     | Notes                                 |
|------------------------------------------|--------|------------|---------------------------------------|
| `/api/v1/sync/info` (read)               | 99.9%  | 30 days    | Critical for core functionality.      |
| `/api/v1/collections/*` (read)           | 99.9%  | 30 days    | Critical.                             |
| `/api/v1/collections/*` (write)          | 99.5%  | 30 days    | Tolerates more degradation under load.|
| `/api/ext/auth/*` and `/api/ext/pair/*`  | 99.5%  | 30 days    | Extension pairing/OAuth.              |
| Web dashboard                            | 99.0%  | 30 days    | UI access, does not block sync.       |

### 1.2 Latency (p95)

| Operation                   | p95 SLO   | Notes                               |
|-----------------------------|------------|-------------------------------------|
| GET `/sync/info`            | < 150 ms   | Metadata only.                      |
| GET `/collections/{name}`   | < 400 ms   | Up to 1000 records with ETag hit.   |
| POST `/collections/{name}`  | < 600 ms   | Batch UPSERT up to 500 records.     |
| OAuth poll                  | < 250 ms   | Redis lookup.                       |

### 1.3 Durability

- PostgreSQL DB: daily backups, 30-day retention, restore tested
  quarterly.
- Records with `deleted = true` (tombstones) retained for 90 days
  before permanent purge.

### 1.4 Error Budget

- 99.9% availability over 30 days = ~43 minutes of tolerated downtime.
- If more than 50% of the monthly budget is consumed within a week,
  freeze releases until root causes are resolved.

---

## 2. Runbook: Master Key Rotation (Client)

> This rotation is initiated by the user from the extension `options/`
> page. Zero-downtime operation, requiring no backend operator action
> other than monitoring.

### 2.1 Pre-flight

1. User verifies they still have access to the current seed phrase
   (`M_old`).
2. Extension generates a new seed (24-word BIP39) and derives `M_new`
   in a Web Worker.
3. UI displays a preview of the new seed and requests explicit
   confirmation.

### 2.2 Execution

1. Persist
   `rotationState = { newMnemonic, newKeyB64, completed: [], currentCollection }`
   in `storage.local`.
2. Keep `previousEncryptionKey = M_old` during the entire rotation.
3. For each collection in stable order:
   - Full scan of remote records via GET `/collections/{name}`.
   - Decrypt with `M_old`, re-encrypt with `M_new`
     (same `collectionIndex`).
   - Batch PUT with new ciphertext.
   - Mark collection in `completed[]`.
4. After all collections complete:
   - Replace `encryptionKey` with `M_new`.
   - Remove `previousEncryptionKey` and `rotationState`.

### 2.3 Resume After Crash

`getRotationStatus` reads `rotationState`. If present, options UI shows
"Resume rotation". The handler resumes from `currentCollection` without
re-prompting the user.

### 2.4 Decryption Fallback

During mixed states, `decryptBsoPayload` first attempts the active key
and, if AEAD validation fails, retries with
`previousEncryptionKey`.

### 2.5 Backend Monitoring

- Spikes in PUT requests across all collections for a user are
  expected.
- If the `sync:w:*` rate limit is hit during rotation, consider
  temporarily increasing `SYNC_RATE_LIMIT_WRITE` for the affected user.

---

## 3. Runbook: Bulk Token Revocation (Security Incident)

### 3.1 Trigger

- Confirmed bearer token leak.
- Database compromise.
- Explicit user request through the dashboard.

### 3.2 Steps

1. Operator accesses the dashboard as admin (or via tinker).
2. For a single user:
   ```bash
   php artisan tinker
   >>> app(\App\Services\SyncAuthService::class)->revokeAllForUser($userId);
   ```
3. For all users (global incident):
   ```bash
   php artisan tinker
   >>> \App\Models\SyncSession::query()->update(['revoked_at' => now()]);
   ```
4. Force immediate cleanup:
   ```bash
   php artisan sync:cleanup-expired
   ```
5. Notify users through an external channel (email).
6. Extensions detect `token_expired` and trigger re-pairing.

### 3.3 Post-mortem

- Document in `docs/adr/` if the root cause requires a contract change.
- Update `CHANGELOG.md` with an advisory.

---

## 4. Runbook: PostgreSQL Backup and Restore

### 4.1 Daily Backup

```bash
# Assumes docker compose service "postgres"
docker compose exec postgres pg_dump -U midori_sync midori_sync \
  | gzip > backups/midori-sync-$(date +%F).sql.gz
```

Retention: rolling 30 days.

### 4.2 Restore

```bash
gunzip -c backups/midori-sync-2026-05-06.sql.gz \
  | docker compose exec -T postgres psql -U midori_sync midori_sync
```

After restore:

```bash
php artisan migrate                    # idempotent
php artisan sync:recalculate-usage     # rebuild quotas
```

### 4.3 Restore Testing

Quarterly. Restore into staging environment and run
`composer test --testsuite=Feature`. Document results.

---

## 5. Runbook: Orphaned Data Cleanup

### 5.1 Expired Tokens

- Scheduled command: `sync:cleanup-expired` (hourly).
- Manual:
  ```bash
  php artisan sync:cleanup-expired
  ```

### 5.2 Expired Tombstone Records

- Tombstones older than 90 days are purged through a scheduled task
  (TODO: add command if missing).

### 5.3 Usage Recalculation

```bash
php artisan sync:recalculate-usage
```

Use after restore, large migration, or if quota metrics diverge.

---

## 6. Runbook: Health Monitoring

### 6.1 Endpoints / Channels

- `GET /up` (Laravel default) — 200 OK indicates the app is alive.
- Structured logs in `storage/logs/laravel.log`
  (TODO: move to stdout in production so Docker/journald can capture
  them).
- Redis: `redis-cli ping`.
- PostgreSQL: `pg_isready`.

### 6.2 Recommended Alerts

| Alert                                           | Threshold               | Severity |
|-------------------------------------------------|-------------------------|-----------|
| 5xx rate on `/api/*`                            | >1% over 5 min          | High      |
| p95 latency `/collections/*` write              | >1s over 10 min         | High      |
| Rate limit hits >X% of traffic                  | >5% over 15 min         | Medium    |
| Token validation failures spike                 | x10 over 24h baseline   | High      |
| PostgreSQL free disk space                      | <15%                    | High      |
| Redis memory usage                              | >80% maxmemory          | Medium    |
| Daily backups failing                           | 1 failure               | Critical  |

### 6.3 On-call

- Weekly rotation documented in the operator internal system.
- Runbook first, escalate to maintainer if unresolved after 1 hour.

---

## 7. Runbook: Deploy

See [deployment.md](deployment.md) for Docker image and configuration
details.

### 7.1 Pre-deploy Checklist

- [ ] CI green on `main`.
- [ ] `composer audit` and `npm audit` without critical findings.
- [ ] `CHANGELOG.md` updated.
- [ ] Migrations reviewed (non-destructive or documented rollback plan).

### 7.2 Deploy

```bash
docker compose pull
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

### 7.3 Rollback

- Revert to the previous image registry tag.
- If a non-reversible migration occurred, restore backup + replay WAL
  up to the point before deploy.

---

## 8. Runbook: Quota Incident

### 8.1 Symptom

User reports 403 with `quota_exceeded` despite deleting data.

### 8.2 Diagnosis

```bash
php artisan tinker
>>> \App\Models\User::find($id)->records()->where('deleted', false)->sum('size_bytes')
>>> \App\Models\User::find($id)->storage_used
```

If values diverge, recalculate:

```bash
php artisan sync:recalculate-usage --user=$id
```

### 8.3 Temporary Mitigation

Increase the user's quota through the dashboard or command, and
document it in the ticket.

---

## 9. Runbook: Credential Rotation (Operator)

### 9.1 Laravel `APP_KEY`

DO NOT rotate without a plan: it invalidates encrypted sessions and
cookies. If necessary:

1. Remove traffic via maintenance mode (`php artisan down`).
2. `php artisan key:generate`.
3. Invalidate sessions (`SyncSession`s are unaffected since they are
   independent tokens).
4. `php artisan up`.

### 9.2 DB / Redis Credentials

1. Create new user with identical permissions.
2. Update `.env`.
3. Rolling restart of the app.
4. Drop old user.

### 9.3 Authentik Client Secret

1. Rotate in Authentik.
2. Update `.env` (`AUTHENTIK_CLIENT_SECRET`).
3. Rolling restart. Existing logins remain valid through the session
   cookie; new OAuth flows use the new secret.

---

## 10. Command Index

```bash
# Health
php artisan about
php artisan migrate:status

# Cleanup
php artisan sync:cleanup-expired
php artisan sync:recalculate-usage

# Tests
composer test
npm test

# Tinker (ad-hoc admin)
php artisan tinker
```
