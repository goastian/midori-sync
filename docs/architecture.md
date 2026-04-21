# Midori Sync — Architecture

## Overview

Midori Sync is a self-hosted, end-to-end encrypted browser synchronization service designed for [Midori Browser](https://astian.org/midori-browser/) (Firefox-based) and Firefox. It uses a **100% custom protocol (MSP — Midori Sync Protocol)** with no dependency on Firefox Sync 1.5.

## System Components

```
┌──────────────────────────┐      ┌──────────────────────────┐
│   Browser Extension      │      │   Web Dashboard (Vue 3)  │
│   (Manifest V2/Gecko)    │      │   (Inertia.js + Vite)    │
│                          │      │                          │
│  ┌────────────────────┐  │      │  ┌────────────────────┐  │
│  │  Sync Engine       │  │      │  │  Devices / Coll.   │  │
│  │  Collection Adapt. │  │      │  │  Settings / Quota   │  │
│  │  Crypto Library    │  │      │  │                    │  │
│  └────────┬───────────┘  │      │  └────────┬───────────┘  │
└───────────┼──────────────┘      └───────────┼──────────────┘
            │ HTTPS (REST API)                │ HTTPS (Inertia)
            │                                 │
┌───────────┴─────────────────────────────────┴──────────────┐
│                    Laravel 12 Backend                       │
│                                                             │
│  ┌─────────────┐  ┌──────────────┐  ┌──────────────────┐  │
│  │ API v1      │  │ Auth         │  │ Scheduled Tasks  │  │
│  │ Controllers │  │ (Authentik)  │  │ (Cleanup/Recalc) │  │
│  ├─────────────┤  ├──────────────┤  └──────────────────┘  │
│  │ Middleware   │  │ SyncAuth     │                        │
│  │ Stack       │  │ Service      │                        │
│  ├─────────────┴──┴──────────────┤                        │
│  │      SyncStorageService       │                        │
│  └──────────────┬────────────────┘                        │
└─────────────────┼────────────────────────────────────────┘
                  │
     ┌────────────┼────────────┐
     │            │            │
┌────┴─────┐ ┌───┴──────┐ ┌──┴──────┐
│PostgreSQL│ │  Redis   │ │  Nginx  │
│   17     │ │   7      │ │ (proxy) │
└──────────┘ └──────────┘ └─────────┘
```

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Backend Framework | Laravel 12 (PHP 8.3+) |
| Frontend Framework | Vue 3 + Composition API |
| SPA Bridge | Inertia.js v3 |
| Build Tool | Vite 8 |
| CSS | TailwindCSS 4 |
| Database | PostgreSQL 17 |
| Cache/Queue | Redis 7 |
| Auth | Authentik (OAuth2/OIDC via Socialite) |
| Encryption | XChaCha20-Poly1305 (libsodium) |
| KDF | Argon2id (3 ops, 64 MB) |
| Extension | Manifest V2 (Firefox/Gecko) |
| Container | Docker (multi-stage build) |
| Process Manager | Supervisord (PHP-FPM + Nginx + Queue + Scheduler) |

## Data Flow

### Sync Upload (Client → Server)
1. Extension adapter collects local data (e.g., bookmarks)
2. Data serialized to JSON
3. Encrypted with collection-specific sub-key (XChaCha20-Poly1305)
4. Sent as base64 payload via `PUT /api/v1/collections/{name}/{id}`
5. Server stores encrypted blob with version and timestamp
6. Server updates collection stats (record count, size)

### Sync Download (Server → Client)
1. Extension requests delta: `GET /api/v1/collections/{name}?since={timestamp}`
2. Server returns records modified after `since`
3. Client decrypts each record's payload
4. Collection adapter applies changes to browser API

### Conflict Resolution
- **Strategy**: Last-Writer-Wins with microsecond timestamps
- **Conditional writes**: `X-If-Unmodified-Since` header → HTTP 412 on conflict
- **Batch operations**: Each record gets a unique timestamp (offset by 0.000001s)

## Directory Structure

```
midori-sync/
├── app/
│   ├── Console/Commands/        # Artisan commands (cleanup, recalculate)
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/V1/          # REST API controllers
│   │   │   ├── Auth/            # OAuth handler
│   │   │   └── Web/             # Inertia page controllers
│   │   └── Middleware/          # Token auth, CORS, quota, device tracking
│   ├── Models/                  # Eloquent models (7 models)
│   └── Services/                # SyncAuthService, SyncStorageService
├── database/migrations/         # 8 migration files
├── extension/                   # Browser extension (Manifest V2)
│   ├── background/              # Sync engine + collection adapters
│   ├── lib/                     # Crypto library (libsodium)
│   ├── popup/                   # Browser action popup
│   └── options/                 # Extension settings page
├── resources/js/                # Vue 3 frontend
│   ├── Layouts/                 # AppLayout.vue
│   └── Pages/                   # Dashboard, Devices, Collections, Settings
├── routes/                      # web.php, api.php, console.php
├── docker/                      # nginx, php.ini, supervisord configs
└── tests/                       # PHPUnit + Vitest
```
