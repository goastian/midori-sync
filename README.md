# Midori Sync

End-to-end encrypted browser synchronization service for the Midori browser. Compatible with the Firefox Sync 1.5 protocol, authenticated via Authentik SSO (OAuth2/OIDC).

## Features

- **End-to-end encryption** — Your data is encrypted client-side with a separate passphrase. The server never sees your decrypted data.
- **Firefox Sync 1.5 compatible** — Works with the built-in sync engine of Firefox-based browsers.
- **Authentik SSO** — Single Sign-On via OAuth2/OIDC with Authentik.
- **Sync everything** — Bookmarks, passwords, open tabs, browsing history, form data, add-ons, and more.
- **User dashboard** — Web panel to manage connected devices, view sync statistics, and configure settings.
- **Self-hostable** — Deploy with Docker or install manually on your own infrastructure.
- **PostgreSQL 18** — Robust, production-ready database backend.

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Backend | Laravel 12 (PHP 8.3+) |
| Frontend | Vue 3 + Inertia.js + TailwindCSS |
| Database | PostgreSQL 18 |
| Auth | Authentik (OAuth2/OIDC via Socialite) |
| Encryption | Client-side AES-256-GCM, PBKDF2 + HKDF key derivation |
| API | Firefox Sync Storage 1.5 + custom TokenServer |

## Quick Start with Docker

```bash
git clone https://github.com/user/midori-sync.git
cd midori-sync
cp .env.example .env
# Edit .env with your Authentik and database credentials
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
```

Visit `http://localhost:8000` to access the landing page.

## Manual Installation

### Prerequisites

- PHP 8.3+ with extensions: `pdo_pgsql`, `pgsql`, `intl`, `zip`, `bcmath`, `mbstring`
- Composer 2+
- Node.js 20+ and npm
- PostgreSQL 18

### Steps

```bash
git clone https://github.com/user/midori-sync.git
cd midori-sync
chmod +x setup.sh
./setup.sh
```

Or manually:

```bash
cp .env.example .env
# Edit .env with your credentials

composer install
php artisan key:generate
npm ci && npm run build
php artisan migrate --force
php artisan serve
```

## Authentik Configuration

Create an OAuth2/OpenID provider in your Authentik instance:

1. Go to **Applications → Providers → Create**
2. Select **OAuth2/OpenID Provider**
3. Configure:
   - **Name:** Midori Sync
   - **Authorization flow:** default-provider-authorization-implicit-consent
   - **Client type:** Confidential
   - **Client ID:** (copy to `AUTHENTIK_CLIENT_ID` in `.env`)
   - **Client Secret:** (copy to `AUTHENTIK_CLIENT_SECRET` in `.env`)
   - **Redirect URIs:** `http://your-domain:8000/auth/authentik/callback`
   - **Scopes:** `openid`, `profile`, `email`
4. Create an **Application** linked to this provider
5. Update your `.env`:

```env
AUTHENTIK_BASE_URL=https://your-authentik-instance.example.com
AUTHENTIK_CLIENT_ID=your-client-id
AUTHENTIK_CLIENT_SECRET=your-client-secret
AUTHENTIK_REDIRECT_URI=http://your-domain:8000/auth/authentik/callback
```

## Browser Configuration

To connect your Midori browser to this sync server:

1. Open `about:config` in the address bar
2. Set `identity.sync.tokenserver.uri` to:
   ```
   http://your-domain:8000/api/1.0/sync/1.5
   ```
3. Restart the browser
4. Sign in via the Sync option — you'll be redirected to Authentik
5. Set your encryption passphrase when prompted

> **Important:** The encryption passphrase is separate from your Authentik login. It encrypts your data locally and is never sent to the server. If you lose it, your synced data cannot be recovered.

## API Endpoints

### TokenServer

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/1.0/sync/1.5` | Exchange Authentik Bearer token for Hawk credentials |

### Sync Storage 1.5

All storage endpoints require Hawk authentication (obtained from TokenServer).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/1.5/{uid}/info/collections` | Collection timestamps |
| GET | `/api/1.5/{uid}/info/quota` | Storage quota |
| GET | `/api/1.5/{uid}/info/collection_usage` | Usage per collection |
| GET | `/api/1.5/{uid}/info/collection_counts` | Item counts per collection |
| GET | `/api/1.5/{uid}/storage/{collection}` | List BSOs |
| GET | `/api/1.5/{uid}/storage/{collection}/{id}` | Get single BSO |
| PUT | `/api/1.5/{uid}/storage/{collection}/{id}` | Create/update BSO |
| POST | `/api/1.5/{uid}/storage/{collection}` | Batch upload BSOs |
| DELETE | `/api/1.5/{uid}/storage/{collection}` | Delete collection |
| DELETE | `/api/1.5/{uid}/storage/{collection}/{id}` | Delete BSO |
| DELETE | `/api/1.5/{uid}` | Delete all user data |

### Health Checks

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/__heartbeat__` | Application health check |
| GET | `/api/__lbheartbeat__` | Load balancer health check |

## Encryption Model

```
Passphrase (entered by user in browser)
    │
    ▼
PBKDF2-SHA256 (600,000 rounds, salt = user_id)
    │
    ▼
Master Key (256 bits)
    │
    ├── HKDF(info="midori-sync-encryption") → Encryption Key (AES-256-GCM)
    └── HKDF(info="midori-sync-hmac")       → HMAC Key (verification)

Each BSO is encrypted client-side before upload.
The server stores only opaque encrypted blobs.
```

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_URL` | Public URL of the application | `http://localhost:8000` |
| `DB_CONNECTION` | Database driver | `pgsql` |
| `DB_HOST` | PostgreSQL host | `127.0.0.1` |
| `DB_DATABASE` | Database name | `midori_sync` |
| `AUTHENTIK_BASE_URL` | Authentik instance URL | — |
| `AUTHENTIK_CLIENT_ID` | OAuth2 client ID | — |
| `AUTHENTIK_CLIENT_SECRET` | OAuth2 client secret | — |
| `AUTHENTIK_REDIRECT_URI` | OAuth2 callback URL | — |
| `SYNC_HAWK_TOKEN_DURATION` | Hawk token lifetime (seconds) | `3600` |
| `SYNC_DEFAULT_QUOTA_BYTES` | Default storage quota per user | `104857600` (100MB) |

## License

AGPL-3.0 — See [LICENSE](LICENSE) for details.
