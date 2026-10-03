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
| `/api/v1/pair/*`                        | 99.5%  | 30 days    | Native device pairing.               |
| Web dashboard                            | 99.0%  | 30 days    | UI access, does not block sync.       |

### 1.2 Latency (p95)

| Operation                   | p95 SLO   | Notes                               |
|-----------------------------|------------|-------------------------------------|
| GET `/sync/info`            | < 150 ms   | Metadata only.                      |
| GET `/collections/{name}`   | < 400 ms   | Up to 1000 records with ETag hit.   |
| POST `/collections/{name}`  | < 600 ms   | Batch UPSERT up to 500 records.     |
| POST `/pair/redeem`         | < 250 ms   | Single-use code exchange.           |

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

## 2. Runbook: Bulk Token Revocation (Security Incident)

### 2.1 Trigger

- Confirmed bearer token leak.
- Database compromise.
- Explicit user request through the dashboard.

### 2.2 Steps

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
6. Native Desktop sessions detect `token_expired` and require the user to reconnect the account.

### 2.3 Post-mortem

- Document in `docs/adr/` if the root cause requires a contract change.
- Update `CHANGELOG.md` with an advisory.

---

## 3. Runbook: PostgreSQL Backup and Restore

### 3.1 Daily Backup

```bash
# Assumes docker compose service "postgres"
docker compose exec postgres pg_dump -U midori_sync midori_sync \
  | gzip > backups/midori-sync-$(date +%F).sql.gz
```

Retention: rolling 30 days.

### 3.2 Restore

```bash
gunzip -c backups/midori-sync-2026-05-06.sql.gz \
  | docker compose exec -T postgres psql -U midori_sync midori_sync
```

After restore:

```bash
php artisan migrate                    # idempotent
php artisan sync:recalculate-usage     # rebuild quotas
```

### 3.3 Restore Testing

Quarterly. Restore into staging environment and run
`composer test --testsuite=Feature`. Document results.

---

## 4. Runbook: Orphaned Data Cleanup

### 4.1 Expired Tokens

- Scheduled command: `sync:cleanup-expired` (hourly).
- Manual:
  ```bash
  php artisan sync:cleanup-expired
  ```

### 4.2 Expired Tombstone Records

- Tombstones older than 90 days are purged through a scheduled task
  (TODO: add command if missing).

### 4.3 Usage Recalculation

```bash
php artisan sync:recalculate-usage
```

Use after restore, large migration, or if quota metrics diverge.

---

## 5. Runbook: Health Monitoring

### 5.1 Endpoints / Channels

- `GET /up` (Laravel default) — 200 OK indicates the app is alive.
- Structured logs in `storage/logs/laravel.log`
  (TODO: move to stdout in production so Docker/journald can capture
  them).
- Redis: `redis-cli ping`.
- PostgreSQL: `pg_isready`.

### 5.2 Recommended Alerts

| Alert                                           | Threshold               | Severity |
|-------------------------------------------------|-------------------------|-----------|
| 5xx rate on `/api/*`                            | >1% over 5 min          | High      |
| p95 latency `/collections/*` write              | >1s over 10 min         | High      |
| Rate limit hits >X% of traffic                  | >5% over 15 min         | Medium    |
| Token validation failures spike                 | x10 over 24h baseline   | High      |
| PostgreSQL free disk space                      | <15%                    | High      |
| Redis memory usage                              | >80% maxmemory          | Medium    |
| Daily backups failing                           | 1 failure               | Critical  |

### 5.3 On-call

- Weekly rotation documented in the operator internal system.
- Runbook first, escalate to maintainer if unresolved after 1 hour.

---

## 6. Runbook: Deploy

See [deployment.md](deployment.md) for Docker image and configuration
details.

### 6.1 Pre-deploy Checklist

- [ ] CI green on `main`.
- [ ] `composer audit` and `npm audit` without critical findings.
- [ ] `CHANGELOG.md` updated.
- [ ] Migrations reviewed (non-destructive or documented rollback plan).

### 6.2 Deploy

```bash
docker compose pull
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

### 6.3 Rollback

- Revert to the previous image registry tag.
- If a non-reversible migration occurred, restore backup + replay WAL
  up to the point before deploy.

---

## 7. Runbook: Quota Incident

### 7.1 Symptom

User reports 403 with `quota_exceeded` despite deleting data.

### 7.2 Diagnosis

```bash
php artisan tinker
>>> \App\Models\User::find($id)->records()->where('deleted', false)->sum('size_bytes')
>>> \App\Models\User::find($id)->storage_used
```

If values diverge, recalculate:

```bash
php artisan sync:recalculate-usage --user=$id
```

### 7.3 Temporary Mitigation

Increase the user's quota through the dashboard or command, and
document it in the ticket.

---

## 8. Runbook: Credential Rotation (Operator)

### 8.1 Laravel `APP_KEY`

DO NOT rotate without a plan: it invalidates encrypted sessions and
cookies. If necessary:

1. Remove traffic via maintenance mode (`php artisan down`).
2. `php artisan key:generate`.
3. Invalidate sessions (`SyncSession`s are unaffected since they are
   independent tokens).
4. `php artisan up`.

### 8.2 DB / Redis Credentials

1. Create new user with identical permissions.
2. Update `.env`.
3. Rolling restart of the app.
4. Drop old user.

### 8.3 Authentik Client Secret

1. Rotate in Authentik.
2. Update `.env` (`AUTHENTIK_CLIENT_SECRET`).
3. Rolling restart. Existing logins remain valid through the session
   cookie; new OAuth flows use the new secret.

---

## 9. Command Index

```bash
# Health
php artisan about
php artisan migrate:status

# Cleanup
php artisan sync:cleanup-expired
php artisan sync:recalculate-usage

# Tests
composer test
npm run build

# Tinker (ad-hoc admin)
php artisan tinker
```
