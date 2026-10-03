# Contributing to Midori Sync

> Thank you for contributing. This guide defines how to prepare the
> environment, coding standards, and the PR workflow. For architecture
> and security see [architecture.md](architecture.md) and
> [security.md](security.md).

---

## 1. Code of Conduct

Treat other contributors with respect. Reports of inappropriate conduct
or security vulnerabilities: see `SECURITY.md` at the repository root.

---

## 2. Local Setup

### Requirements

- PHP 8.3+
- Composer 2
- Node.js 20+
- Docker + Docker Compose (recommended for PostgreSQL 17 + Redis 7)
- A Midori Desktop build for native browser integration tests

### Bootstrap

```bash
git clone <repo>
cd midori-sync
cp .env.example .env
composer install
npm install
docker compose up -d        # postgres, redis, nginx (optional)
php artisan key:generate
php artisan migrate --seed
php artisan serve
npm run dev                 # Vite + Inertia HMR
```

The native client lives in the Midori Desktop repository under `src/browser/components/sync/` and `src/toolkit/components/midori-sync/`.

---

## 3. Coding Standards

### PHP / Laravel

- Laravel 12 conventions (`app/Http/Controllers` for controllers,
  `app/Http/Requests` for Form Requests, services in `app/Services`).
- Strict typing whenever possible (`declare(strict_types=1)` is not
  mandatory yet, but all new code must use typed properties and return
  types).
- Migration naming:
  `YYYY_MM_DD_HHMMSS_<verb>_<subject>`.
- Changes to public endpoints require updating
  [docs/api.md](api.md) and, if applicable,
  [docs/protocol.md](protocol.md) with an ADR if compatibility is
  broken.

### JavaScript / Vue

- ES modules in `resources/js/` serve the web dashboard; privileged browser modules live in Midori Desktop.
- Vue 3 Composition API + `<script setup>`. Inertia for navigation.
- Tailwind for styling. Dark mode via the `dark:` class and the
  `useTheme` composable.

### Crypto

- Any change to the native Rust crypto format or payload layout requires:
  1. ADR in `docs/adr/`.
  2. Update to [docs/encryption.md](encryption.md).
  3. Rust and native client compatibility tests.
  4. Migration plan for existing data if compatibility is broken.

---

## 4. Tests

```bash
# Backend
composer test
php artisan test --testsuite=Feature

# Web dashboard build
npm run build

# Specific test
php artisan test --filter=SyncAuthServiceTest
npm run build
```

### When to Add Tests

- New endpoint: Feature test under `tests/Feature/`.
- New service: unit test under `tests/Unit/` + Feature integration test
  if DB interaction exists.
- Native adapter or background handler: test in Midori Desktop's Gecko suite.
- Crypto change: test the Rust core and cross-language fixtures.

### Policy

- Do not reduce net coverage.
- Tests must be deterministic. For timing use
  `Carbon::setTestNow()` or `vi.useFakeTimers()`.
- For OAuth/Socialite flows, mock the provider with Mockery
  (do not call real Authentik).

---

## 5. Documentation

Any PR affecting one of these contracts must update the corresponding
document in the same PR:

| Change                                         | Required Doc                            |
|------------------------------------------------|-----------------------------------------|
| Backend endpoint                               | `docs/api.md`                           |
| Protocol contract / breaking change            | `docs/protocol.md` + ADR in `docs/adr/` |
| Algorithm / KDF / payload layout               | `docs/encryption.md`                    |
| DB migration with operational impact           | `docs/deployment.md`                    |
| Native adapters / client storage shape         | Midori Desktop `docs/estado-integracion-sync-nativo.md` |
| Threat model, headers, CORS, CSP               | `docs/security.md`                      |
| User-visible or operator-visible changes       | `CHANGELOG.md`                          |

New ADRs: copy template `docs/adr/0000-template.md` (if it exists) and
use incremental numbering.

---

## 6. PR Workflow

1. Branch from `main`: `feat/<topic>`, `fix/<topic>`,
   `docs/<topic>`.
2. Commits follow
   [Conventional Commits](https://www.conventionalcommits.org/):
   `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`,
   `perf:`, `security:`.
3. Before pushing:
   `composer test`, `npm run build`, `composer audit`,
   `npm audit`, lint.
4. PR description must include:
   - What changes and why.
   - Compatibility impact (data, API, extension storage).
   - Updated docs (list).
   - Security checklist if applicable
     (CORS, CSP, auth, crypto).
5. PRs touching crypto, auth, CORS, CSP, headers, or seed storage
   require explicit review and an ADR.
6. Squash merge by default.

---

## 7. Bug and Feature Reporting

- GitHub issues with labels `bug`, `feature`, `security`.
- For security vulnerabilities DO NOT open a public issue. See
  [SECURITY.md](../SECURITY.md).

---

## 8. Suggested PR Template

```markdown
## What

<one line>

## Why

<context / issue / decision>

## How

<technical summary>

## Compatibility

- [ ] Does not break public API
- [ ] Preserves or explicitly migrates legacy extension data
- [ ] Does not require manual migration

## Docs

- [ ] Updated docs from the CONTRIBUTING table as applicable
- [ ] CHANGELOG updated if user-visible changes exist

## Tests

- [ ] composer test passing
- [ ] npm run build passing
- [ ] Added tests for the change
```
