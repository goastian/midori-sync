# Midori Sync — Architecture

## Overview

Midori Sync is a self-hosted, end-to-end encrypted browser synchronization service designed for [Midori Browser](https://astian.org/midori-browser/) (Firefox-based) and Firefox. It uses a **100% custom protocol (MSP — Midori Sync Protocol)** with no dependency on Firefox Sync 1.5.

## System Components

```
┌──────────────────────────┐      ┌──────────────────────────┐
│   Midori Desktop         │      │   Web Dashboard (Vue 3)  │
│   (native Gecko)         │      │   (Inertia.js + Vite)    │
│                          │      │                          │
│  ┌────────────────────┐  │      │  ┌────────────────────┐  │
│  │  Sync / Link       │  │      │  │  Devices / Coll.   │  │
│  │  Places / Logins   │  │      │  │  Settings / Quota   │  │
│  │  Rust Crypto       │  │      │  │                    │  │
│  └────────┬───────────┘  │      │  └────────┬───────────┘  │
└───────────┼──────────────┘      └───────────┼──────────────┘
            │ HTTPS (REST API)                │ HTTPS (Inertia)
            │                                 │
┌───────────┴─────────────────────────────────┴──────────────┐
│                    Laravel 13 Backend                       │
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
| Backend Framework | Laravel 13 (PHP 8.3+) |
| Frontend Framework | Vue 3 + Composition API |
| SPA Bridge | Inertia.js v3 |
| Build Tool | Vite 8 |
| CSS | TailwindCSS 4 |
| Database | PostgreSQL 17 |
| Cache/Queue | Redis 7 |
| Auth | Authentik (OAuth2/OIDC via Socialite) |
| Encryption | XChaCha20-Poly1305 (libsodium) |
| KDF | Argon2id (3 ops, 64 MB) |
| Desktop client | Native Gecko JavaScript, C++, and Rust |
| Container | Docker (multi-stage build) |
| Process Manager | Supervisord (PHP-FPM + Nginx + Queue + Scheduler) |

## Data Flow

### Sync Upload (Client → Server)
1. Native adapters read Places, Login Manager, and Midori preferences.
2. The client encrypts versioned records with Rust before transport.
3. The client writes to `/api/v1/sync/changes` with conditional revisions and operation IDs.
4. Laravel commits records and journal events in one PostgreSQL transaction.

### Sync Download (Server → Client)
1. The native client requests a consistent bootstrap or a paged journal delta.
2. Rust authenticates and decrypts each record locally.
3. Native adapters apply accepted changes through Places and Login Manager.

### Conflict Resolution
- Native writes use revisions and operation IDs; conflicts remain explicit in the client journal.
- The legacy timestamp API remains available only during the migration window.

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
├── resources/js/                # Vue 3 frontend
│   ├── Layouts/                 # AppLayout.vue
│   └── Pages/                   # Dashboard, Devices, Collections, Settings
├── routes/                      # web.php, api.php, console.php
├── docker/                      # nginx, php.ini, supervisord configs
└── tests/                       # PHPUnit
```
