# Midori Sync — Deployment Guide

## Prerequisites

- Docker & Docker Compose
- An [Authentik](https://goauthentik.io/) instance for OAuth2/OIDC authentication
- A domain name with HTTPS (for production)

## Quick Start (Docker Compose)

### 1. Clone the repository

```bash
git clone https://github.com/nickel-org/midori-sync.git
cd midori-sync
```

### 2. Configure environment

```bash
cp .env.example .env
```

Edit `.env` with your settings:

```env
# App
APP_URL=https://sync.yourdomain.com

# Database
DB_HOST=postgres
DB_DATABASE=midori_sync
DB_USERNAME=midori
DB_PASSWORD=<strong-random-password>

# Authentik OAuth
AUTHENTIK_CLIENT_ID=<from-authentik>
AUTHENTIK_CLIENT_SECRET=<from-authentik>
AUTHENTIK_BASE_URL=https://authentik.yourdomain.com
AUTHENTIK_ISSUER=https://authentik.yourdomain.com/application/o/<application-slug>/
AUTHENTIK_REDIRECT_URI=https://sync.yourdomain.com/auth/callback
AUTHENTIK_NATIVE_CLIENT_ID=<public-desktop-client-id>
AUTHENTIK_NATIVE_DISCOVERY_URL=https://authentik.yourdomain.com/application/o/<application-slug>/.well-known/openid-configuration

# Sync settings
SYNC_TOKEN_TTL=3600
SYNC_MAX_RECORD_SIZE=262144
SYNC_DEFAULT_QUOTA=104857600
SYNC_RATE_LIMIT=60
```

For direct Desktop sign-in, configure a separate **Public** Authentik OAuth2 provider with authorization code, PKCE S256 and the `openid`, `profile` and `email` scope mappings. Set both the dashboard's confidential provider and the Desktop public provider to Authentik's shared issuer mode and the same subject mode. Set `AUTHENTIK_ISSUER` to the shared issuer, normally `https://authentik.yourdomain.com/`; keep `AUTHENTIK_NATIVE_DISCOVERY_URL` under the public provider's application slug. Register the Desktop redirect URI as an anchored regex, `^http://127\.0\.0\.1:[0-9]{1,5}/midori-sync/callback$`. Do not leave the provider's redirect URI list empty. Midori chooses an available loopback port for each authorization and never embeds a client secret. Users of an existing per-provider issuer must sign in to the web dashboard again after the issuer change; a still-open web session cannot silently rebind the identity. Preserve the same subject mode when changing issuer. A local Sync API can use this flow with `SYNC_LOCAL_DEV=false` and the same HTTPS Authentik configuration.

### 3. Start services

```bash
docker compose up -d
```

This starts:
- **app**: Laravel + Nginx + PHP-FPM + Queue Worker + Scheduler + Sync notification daemon
- **postgres**: PostgreSQL 17
- **redis**: Redis 7

### 4. Run migrations

```bash
docker compose exec app php artisan migrate --seed
```


## Authentik Setup

1. In Authentik Admin, create a new **OAuth2/OpenID Provider**
2. Set redirect URI to `https://sync.yourdomain.com/auth/callback`
3. Copy the Client ID and Client Secret to `.env`
4. Create an **Application** linked to the provider
5. (Optional) Configure groups/access control in Authentik

## Reverse Proxy (Production)

For production, put a reverse proxy (Nginx, Caddy, Traefik) in front with HTTPS.

### Built-in vhosts

The repository ships two nginx vhost templates under `docker/`:

| File                  | Purpose                                                 |
|-----------------------|---------------------------------------------------------|
| `docker/nginx.conf`   | Default (HTTP plain, used by `Dockerfile` for dev/CI).  |
| `docker/nginx.dev.conf` | Same as default; explicit dev profile.                |
| `docker/nginx.prod.conf` | **Production**: HTTPS forced, HSTS, CSP, HTTP→HTTPS 301 redirect. |

To swap to the production vhost, override the `Dockerfile` `COPY` step
or mount it via compose:

```yaml
services:
  app:
    volumes:
      - ./docker/nginx.prod.conf:/etc/nginx/http.d/default.conf:ro
      - ./certs/fullchain.pem:/etc/nginx/ssl/fullchain.pem:ro
      - ./certs/privkey.pem:/etc/nginx/ssl/privkey.pem:ro
```

`nginx.prod.conf` enforces:

- `return 301 https://$host$request_uri` for every plain HTTP request
  (except `/.well-known/acme-challenge/` for ACME renewals).
- `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`.
- A conservative `Content-Security-Policy` as a defense in depth on top
  of the application-level CSP emitted by `App\Http\Middleware\SecurityHeaders`.
- `X-Frame-Options: DENY`, `Permissions-Policy: camera=(), microphone=()...`,
  `Referrer-Policy: strict-origin-when-cross-origin`.

The application also emits HSTS itself when `APP_ENV=production`, so
the header is present even if a TLS-terminating proxy upstream forgets
to add one.

### CORS allow-list (production)

Set `CORS_ALLOWED_ORIGINS` to a comma-separated list of exact origins
allowed to call `/api/v1`. Use
`CORS_ALLOWED_ORIGIN_PATTERNS` for regex-matched origins (without
delimiters; anchored automatically). Example:

```
CORS_ALLOWED_ORIGINS=https://dashboard.midori-sync.example
```

Origins outside this list receive no CORS response headers, which
causes the browser to block the response. There is no wildcard
fallback in production.

### Reverse proxy templates

#### Caddy example

```
sync.yourdomain.com {
    reverse_proxy localhost:8080
}
```

#### Nginx (external proxy) example

```nginx
server {
    listen 443 ssl http2;
    server_name sync.yourdomain.com;

    ssl_certificate /etc/ssl/certs/sync.crt;
    ssl_certificate_key /etc/ssl/private/sync.key;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;

    location / {
        proxy_pass http://localhost:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

server {
    listen 80;
    server_name sync.yourdomain.com;
    return 301 https://$host$request_uri;
}
```

## Maintenance

### Scheduled Tasks (automatic via Supervisor)

- **Cleanup expired records/sessions**: Every hour
- **Recalculate storage usage**: Daily

### Manual Commands

```bash
# Cleanup expired records and sessions
docker compose exec app php artisan sync:cleanup-expired

# Recalculate storage for all users
docker compose exec app php artisan sync:recalculate-usage

# Recalculate for a specific user
docker compose exec app php artisan sync:recalculate-usage --user=1
```

### Backups

```bash
# Database backup
docker compose exec postgres pg_dump -U midori midori_sync > backup.sql
```

Back up the deployment's `APP_KEY` securely alongside the database. Native
Sync now encrypts account recovery secrets with that key; a database backup
without its matching application key cannot restore those secrets on a new
device. Restrict access to the key and the `/api/v1/crypto/recovery` endpoint,
which requires an authenticated native device session. Rotating `APP_KEY`
requires retaining the previous key through Laravel's `APP_PREVIOUS_KEYS`
until stored recovery secrets have been re-encrypted. This server-custody
model allows login-only restoration but means server compromise can expose
the material needed to decrypt synchronized browser data.

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_URL` | `http://localhost` | Application URL |
| `APP_KEY` | — | Encryption key for account recovery secrets; back up securely |
| `APP_PREVIOUS_KEYS` | — | Previous keys needed while rotating encrypted recovery data |
| `DB_CONNECTION` | `pgsql` | Database driver |
| `DB_HOST` | `postgres` | Database host |
| `DB_DATABASE` | `midori_sync` | Database name |
| `CACHE_STORE` | `redis` | Cache driver |
| `SESSION_DRIVER` | `redis` | Session driver |
| `QUEUE_CONNECTION` | `redis` | Queue driver |
| `AUTHENTIK_CLIENT_ID` | — | OAuth client ID |
| `AUTHENTIK_CLIENT_SECRET` | — | OAuth secret |
| `AUTHENTIK_BASE_URL` | — | Authentik instance URL |
| `AUTHENTIK_ISSUER` | — | Exact `issuer` from this application's OpenID discovery document; required for native pairing |
| `AUTHENTIK_NATIVE_CLIENT_ID` | — | Public Desktop OIDC client ID; enables the native token exchange when set with discovery URL |
| `AUTHENTIK_NATIVE_DISCOVERY_URL` | — | HTTPS discovery URL on the exact issuer origin; issuer must match `AUTHENTIK_ISSUER` |
| `SYNC_TOKEN_TTL` | `3600` | Token lifetime in seconds |
| `SYNC_MAX_RECORD_SIZE` | `262144` | Max record size (256 KB) |
| `SYNC_DEFAULT_QUOTA` | `104857600` | Default user quota (100 MB) |
| `SYNC_RATE_LIMIT` | `60` | API requests per minute |
