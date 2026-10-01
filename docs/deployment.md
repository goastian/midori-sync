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
AUTHENTIK_REDIRECT_URI=https://sync.yourdomain.com/auth/callback

# Sync settings
SYNC_TOKEN_TTL=3600
SYNC_MAX_RECORD_SIZE=262144
SYNC_DEFAULT_QUOTA=104857600
SYNC_RATE_LIMIT=60
```

### 3. Start services

```bash
docker compose up -d
```

This starts:
- **app**: Laravel + Nginx + PHP-FPM + Queue Worker + Scheduler
- **postgres**: PostgreSQL 17
- **redis**: Redis 7

### 4. Run migrations

```bash
docker compose exec app php artisan migrate --seed
```

### 5. Generate app key

```bash
docker compose exec app php artisan key:generate
```

The application is now running at `http://localhost:8080`.

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
allowed to call `/api/ext` and `/api/v1`. Use
`CORS_ALLOWED_ORIGIN_PATTERNS` for regex-matched origins (without
delimiters; anchored automatically). Example:

```
CORS_ALLOWED_ORIGINS=https://dashboard.midori-sync.example
CORS_ALLOWED_ORIGIN_PATTERNS=^moz-extension://[a-z0-9-]+$,^chrome-extension://[a-z0-9]+$
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

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_URL` | `http://localhost` | Application URL |
| `DB_CONNECTION` | `pgsql` | Database driver |
| `DB_HOST` | `postgres` | Database host |
| `DB_DATABASE` | `midori_sync` | Database name |
| `CACHE_STORE` | `redis` | Cache driver |
| `SESSION_DRIVER` | `redis` | Session driver |
| `QUEUE_CONNECTION` | `redis` | Queue driver |
| `AUTHENTIK_CLIENT_ID` | — | OAuth client ID |
| `AUTHENTIK_CLIENT_SECRET` | — | OAuth secret |
| `AUTHENTIK_BASE_URL` | — | Authentik instance URL |
| `SYNC_TOKEN_TTL` | `3600` | Token lifetime in seconds |
| `SYNC_MAX_RECORD_SIZE` | `262144` | Max record size (256 KB) |
| `SYNC_DEFAULT_QUOTA` | `104857600` | Default user quota (100 MB) |
| `SYNC_RATE_LIMIT` | `60` | API requests per minute |
