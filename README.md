# Midori Sync

Self-hosted synchronization service for Midori and Firefox with end-to-end encryption, a Laravel backend, a browser extension, and a web dashboard to inspect devices, collections, and storage usage.

## Current Status

The repository already has a functional and usable foundation:

- Laravel 13 backend with `v1` sync API and `ext` API for the extension.
- Web and extension authentication via Authentik using Socialite.
- PostgreSQL persistence with Redis for cache/session/queue in Docker deployments.
- Extension with popup, options, setup, crypto library with libsodium, and adapters for multiple collections.
- Web dashboard with pages for dashboard, devices, collections, and settings.

There is also clear technical debt:

- There are two API surfaces (`/api/ext` and `/api/v1`) with overlapping logic.
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

### Extension

- Manifest V2 for Gecko/Firefox
- Popup for login, sync status, and manual actions
- Options page for server settings, collections, and seed phrase
- Setup page to generate or recover the seed phrase
- `midori-sync-crypto.js` library with Argon2id + XChaCha20-Poly1305

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
  Http/Requests/           API v1 and ext Form Requests
  Models/                  User, Device, Record, SyncSession, etc.
  Services/                Core auth and storage logic
database/
  migrations/              Main sync schema
  seeders/                 Collection seeder
docs/                      API, architecture, encryption, deployment, plan
extension/                 Browser extension
resources/js/              Frontend Vue 3 + Inertia
routes/                    web.php, api.php, console.php
tests/                     PHPUnit y Vitest
```

## Requirements

### For Docker

- Docker
- Docker Compose
- Authentik instance accessible from the application

### For Local Development

- PHP 8.3+
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

# current JS tests
npx vitest run tests/crypto.test.js

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

- The extension is still not consolidated around a single sync engine.
- Tests are still missing for adapters, middleware, `SyncAuthService`, and E2E flows.
- Operational docs such as `protocol.md`, `extension-dev.md`, and `contributing.md` are still missing.
- The dashboard still does not include an activity chart or device rename flow.

## License

This project is distributed under the AGPL license. See `LICENSE` for the full text.