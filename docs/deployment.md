# Midori Sync — Deployment Guide

## Prerequisites

- Docker & Docker Compose
- An [Authentik](https://goauthentik.io/) instance for OAuth2/OIDC authentication
- A domain name with HTTPS (for production)

The default HTTP container port binds only to `127.0.0.1`. Use it for local
integration or behind a TLS reverse proxy. HTTPS is required outside loopback;
changing `APP_BIND_IP` to a public interface does not enable TLS. Set
`APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` and
`SESSION_ENCRYPT=true` for production, and expose only the TLS proxy. `APP_URL`
alone does not enforce HTTPS. The local Artisan instance on port 8000 is an
explicit development exception; it must stay bound to loopback.

The shipped nginx access-log format records method, path, status and sizes only;
it omits query strings, referrers and authorization/cookie headers. Preserve this
rule at every upstream proxy: OAuth callbacks carry short-lived authorization
codes in their URL. Do not enable request-body/header tracing or debug exception
pages around authentication. Client recovery codes and key material must never
enter URLs, access logs, analytics or crash telemetry. PHP's development server
is not a production logging or TLS boundary.

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
AUTHENTIK_NATIVE_CLIENT_ID=
AUTHENTIK_NATIVE_DISCOVERY_URL=

# Sync settings
SYNC_TOKEN_TTL=3600
SYNC_MAX_RECORD_SIZE=262144
SYNC_DEFAULT_QUOTA=104857600
SYNC_RATE_LIMIT=60
```

Desktop sign-in uses the existing confidential web client. Set `AUTHENTIK_CLIENT_ID`, `AUTHENTIK_CLIENT_SECRET`, `AUTHENTIK_BASE_URL`, `AUTHENTIK_ISSUER`, and `AUTHENTIK_REDIRECT_URI` as for the dashboard. Deploy the `sync_browser_authorizations` migration. After Authentik login, the user approves the Desktop connection in Midori Sync; a single-use, PKCE-bound authorization returns to the browser through loopback. No code is copied by the user, and the client secret stays on the server. Leave `AUTHENTIK_NATIVE_CLIENT_ID` and `AUTHENTIK_NATIVE_DISCOVERY_URL` empty unless the optional direct public OIDC flow is also configured.

Compose requires `AUTHENTIK_ISSUER` and forwards the authentication variables to the app container. Setting a value in the host's `.env` is insufficient unless the container receives it. Use the exact `issuer` from the application's `/.well-known/openid-configuration` document, including its trailing slash. Do not infer it from `AUTHENTIK_BASE_URL`: Authentik supports both shared and per-application issuers.

For optional direct Desktop sign-in, configure a separate **Public** Authentik OAuth2 provider with authorization code, PKCE S256 and the `openid`, `profile` and `email` scope mappings. The existing `AUTHENTIK_CLIENT_ID` and `AUTHENTIK_CLIENT_SECRET` belong to the dashboard's confidential login; Desktop cannot use that client secret. Set both the dashboard's confidential provider and the Desktop public provider to Authentik's shared issuer mode and the same subject mode. Set `AUTHENTIK_ISSUER` to the shared issuer, normally `https://authentik.yourdomain.com/`; keep `AUTHENTIK_NATIVE_DISCOVERY_URL` under the public provider's application slug. The Sync API advertises the public client ID and discovery URL to Desktop through `/api/v1/capabilities`; they are not hardcoded in the browser. Register the Desktop redirect URI as an anchored regex, `^http://127\.0\.0\.1:[0-9]{1,5}/midori-sync/callback$`. Do not leave the provider's redirect URI list empty. Midori chooses an available loopback port for each authorization and never embeds a client secret. Users of an existing per-provider issuer must sign in to the web dashboard again after the issuer change; a still-open web session cannot silently rebind the identity. Preserve the same subject mode when changing issuer. A local Sync API can use this flow with `SYNC_LOCAL_DEV=false` and the same HTTPS Authentik configuration.

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

### 5. Verify Desktop authentication

```bash
docker compose exec app php artisan sync:check-auth
curl --fail --silent --show-error https://sync.yourdomain.com/api/v1/capabilities
```

`sync:check-auth` validates the effective Laravel configuration without contacting Authentik or exposing credentials. It must report a configured issuer and at least one enabled Desktop login flow. The public capabilities response must contain that same `authentication.issuer` and `authentication.browser_login.version: 1` for the existing web client. `native_ready` is unrelated to login availability.

If Desktop displays "Direct account sign-in is not configured on this Sync server", inspect the public response first. An `issuer: null` with `browser_login: null` and `oidc: null` means the backend cannot authorize Desktop. `/auth/redirect` can still work in this state; `/auth/desktop` returns 503. Configure the server environment rather than overriding detection in Desktop.

After changing environment variables, recreate the app container and rebuild Laravel's configuration cache:

```bash
docker compose up -d --force-recreate app
docker compose exec app php artisan config:cache
docker compose exec app php artisan sync:check-auth
```

For Podman/Quadlet, update the file referenced by `EnvironmentFile=` in the app unit and restart that unit so the container receives the new values. The supplied `container/config/init.sh` rebuilds the configuration cache on startup. Run `podman exec midori-sync php artisan sync:check-auth` afterward. Editing only the project's `.env` or running `config:cache` inside a container with the old environment does not update its injected variables.

For the official deployment at `https://sync.astian.org`, the public configuration is:

```env
APP_URL=https://sync.astian.org
AUTHENTIK_BASE_URL=https://accounts.astian.org
AUTHENTIK_ISSUER=https://accounts.astian.org/application/o/midori-sync/
AUTHENTIK_REDIRECT_URI=https://sync.astian.org/auth/callback
```

Confirm the issuer against `https://accounts.astian.org/application/o/midori-sync/.well-known/openid-configuration` before applying it. Keep the existing confidential client ID and secret on the server. No Authentik client secret belongs in `midori-desktop`.

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

#### Trusted client addresses

Set `SYNC_TRUSTED_PROXIES` to a comma-separated list of the internal proxy IPs
or narrow CIDRs that reach the container's Nginx. Laravel uses that list before
rate limiting, authentication and security logging. An empty list trusts no
forwarding headers. Wildcards, `REMOTE_ADDR`, hostnames and zero-prefix CIDRs
are rejected during configuration loading. Only `X-Forwarded-For`,
`X-Forwarded-Proto` and `X-Forwarded-Port` are trusted; `Forwarded`,
`X-Forwarded-Host`, `X-Forwarded-Prefix` and Cloudflare headers are not read as
client identity by Laravel.

Determine the internal peer address from the container Nginx's safe access log
for an ordinary request through the host proxy. That address becomes PHP's
`REMOTE_ADDR` through FastCGI; it is not necessarily `127.0.0.1` inside a
Podman or Docker bridge. For example, **only if that peer is `10.89.0.1`**:

```env
SYNC_TRUSTED_PROXIES=10.89.0.1
```

Keep the container port bound to loopback and reachable only by the host proxy.
Do not trust the entire container subnet or all private networks. Compose passes
this variable to the app; for Quadlet, `container/setup.sh` copies it from the
project `.env` into the generated `EnvironmentFile`. Recreate/restart the app
with the updated environment and rebuild `config:cache` as described above.
Putting the variable only in the host shell does not update a running container.

#### Cloudflare -> host Nginx -> container

Use `container/nginx-reverse.conf` for this topology. Install its Cloudflare
snippet before enabling or reloading the vhost:

```bash
sudo install -d /etc/nginx/snippets
sudo install -m 644 container/nginx-cloudflare.conf /etc/nginx/snippets/midori-sync-cloudflare.conf
sudo nginx -t
sudo systemctl reload nginx
```

The host Nginx needs `ngx_http_realip_module`. The snippet accepts
`CF-Connecting-IP` only from the official Cloudflare IPv4/IPv6 ranges. Nginx
then uses the resolved `$remote_addr` for its rate/connection limits and sends
that single address in a freshly written `X-Forwarded-For` to the container.
Client-supplied forwarding chains and unused forwarding headers are removed.
Direct connections outside those ranges cannot select their own address with
`CF-Connecting-IP`. Install the snippet in the Sync server block, including the
TLS block if certbot creates a separate one; do not enable it globally for
unrelated vhosts or in the container Nginx. Laravel trusts only the internal
peer, not Cloudflare's public networks.

Use Cloudflare **Full (strict)** with a valid origin certificate, and restrict
origin ingress to Cloudflare's published ranges. Leave **Pseudo IPv4** off or
in **Add Header** mode so it does not replace real IPv6 addresses. Do not enable
a Managed Transform that removes visitor IP headers. Keep the snippet current
with both official lists, review updates, run `nginx -t` and reload:

- [Cloudflare IPv4 ranges](https://www.cloudflare.com/ips-v4)
- [Cloudflare IPv6 ranges](https://www.cloudflare.com/ips-v6)
- [Restoring visitor IPs](https://developers.cloudflare.com/support/troubleshooting/restoring-visitor-ips/restoring-original-visitor-ips/)

Before considering the finding resolved in a deployment, verify with controlled
clients that two public IPs have independent unauthenticated/failed-auth limits,
an untrusted peer cannot change its identity using forwarding headers, and the
security audit records the visitor IP. Use an isolated staging environment for
limit-exhaustion checks. A shared public NAT still shares a per-IP allowance;
this fix removes the shared proxy address from that decision.

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
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_set_header Forwarded "";
        proxy_set_header X-Forwarded-Host "";
        proxy_set_header X-Forwarded-Prefix "";
    }
}

server {
    listen 80;
    server_name sync.yourdomain.com;
    return 301 https://$host$request_uri;
}
```

The Caddy and external Nginx examples assume direct client connections. For
Cloudflare in front, use the Cloudflare configuration above rather than
forwarding Cloudflare's peer address as the visitor.

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
| `AUTHENTIK_NATIVE_CLIENT_ID` | — | Optional public Desktop OIDC client ID for direct provider login |
| `AUTHENTIK_NATIVE_DISCOVERY_URL` | — | Optional HTTPS discovery URL for that public provider; issuer must match `AUTHENTIK_ISSUER` |
| `SYNC_TOKEN_TTL` | `3600` | Token lifetime in seconds |
| `SYNC_MAX_RECORD_SIZE` | `262144` | Max record size (256 KB) |
| `SYNC_DEFAULT_QUOTA` | `104857600` | Default user quota (100 MB) |
| `SYNC_RATE_LIMIT` | `60` | API requests per minute |
