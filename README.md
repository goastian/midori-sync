# Midori Sync

Self-hosted synchronization and Link service for Midori Desktop, with end-to-end encrypted Sync records, a Laravel backend, and a web dashboard.

## Current Status

The repository already has a functional and usable foundation:

- Laravel 13 backend with the native `v1` Sync API.
- Web authentication via Authentik using Socialite and native device pairing.
- PostgreSQL persistence with Redis for cache/session/queue in Docker deployments.
- Midori Desktop owns the native popup, browser data adapters, background scheduler, and Rust/C++ cryptography.
- Web dashboard with pages for dashboard, devices, collections, and settings.

There is also clear technical debt:

- The collection seeder does not fully match the original project scope.
- Test coverage and development documentation are still partial.

The updated status details and execution plan live in `docs/plan-status.md`.

## Main Components

### Backend

- Laravel 13 with PHP 8.3+
- REST API for sessions, records, devices, and crypto key bundles
- `SyncAuthService` and `SyncStorageService` services
- Middleware for quota checks, device tracking, CORS, and token validation
- Scheduled commands for TTL cleanup and usage recalculation

### Web dashboard

- Dashboard with basic metrics and recent activity
- Connected device management
- Navigation across synced collections
- Settings for quota and server-side data deletion

## Project Structure

```text
app/
  Console/Commands/        Scheduled commands
  Http/Controllers/        API, auth, and web controllers
  Http/Middleware/         Token, quota, CORS, tracking
  Http/Requests/           API v1 Form Requests
  Models/                  User, Device, Record, SyncSession, etc.
  Services/                Core auth and storage logic
database/
  migrations/              Main sync schema
  seeders/                 Collection seeder
docs/                      API, architecture, encryption, deployment, plan
resources/js/              Frontend Vue 3 + Inertia
routes/                    web.php, api.php, console.php
tests/                     PHPUnit
```

The former extension source is available in historical commit `33690ac8b621980ded566bea370fa1efb37b5300`. New Desktop builds do not package the extension, and the server exposes only the native API. Existing profiles still require the native data migration described below.

## Requirements

### For Docker

- Docker
- Docker Compose
- Authentik instance accessible from the application

### For Local Development

- PHP 8.3+ with curl, intl, sodium and PostgreSQL extensions
- Composer 2+
- Node.js 20+
- PostgreSQL 17+
- Redis 7+

## Docker Quick Start

1. Clone the repository and enter the directory.
2. Create your environment file from `.env.example`.
3. Configure at least `APP_URL`, `DB_*`, `AUTHENTIK_*`, and the sync values.
4. Start the services.
5. Generate the Laravel key and run migrations with seed data.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

By default, the container publishes the app on port `8000`, so the expected local URL is:

```text
http://localhost:8000
```

## Local Development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
composer run dev
```

`composer run dev` starts the Laravel server, queue worker, logs, and Vite in parallel.

## Relevant Environment Variables

```env
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=midori_sync
DB_USERNAME=midori
DB_PASSWORD=secret

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

AUTHENTIK_CLIENT_ID=
AUTHENTIK_CLIENT_SECRET=
AUTHENTIK_BASE_URL=https://auth.example.com
AUTHENTIK_ISSUER=https://auth.example.com/application/o/<application-slug>/
AUTHENTIK_REDIRECT_URI=${APP_URL}/auth/callback

SYNC_TOKEN_TTL=3600
SYNC_MAX_RECORD_SIZE=262144
SYNC_DEFAULT_QUOTA=104857600
SYNC_RATE_LIMIT=60
```

## Useful Commands

```bash
# backend tests
composer test

# dashboard production build
npm run build

# cleanup TTL records and expired sessions
php artisan sync:cleanup-expired

# recalculate usage for all users
php artisan sync:recalculate-usage

# recalculate usage for a specific user
php artisan sync:recalculate-usage --user=1
```

## Available Documentation

- `docs/api.md`: current MSP API reference.
- `docs/architecture.md`: technical architecture overview.
- `docs/encryption.md`: encryption model and key hierarchy.
- `docs/deployment.md`: Docker deployment guide.
- `docs/plan-status.md`: updated audit and prioritized backlog.

## Known Limitations

- Existing profiles and server records from the retired extension still need a verified native migration path.
- Former extension clients cannot connect to this server after the removal of their API routes.
- Native OAuth/PKCE, broader multiplatform testing, and full Link library management remain in progress.

## License

This project is distributed under the AGPL license. See `LICENSE` for the full text.
