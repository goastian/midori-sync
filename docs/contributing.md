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
- Firefox or Midori Browser to test the extension

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

For the extension see [extension-dev.md](extension-dev.md).

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

- ES modules in `resources/js/`, classic scripts in `extension/`
  (MV2 manifest). Do not mix them.
- Vue 3 Composition API + `<script setup>`. Inertia for navigation.
- Tailwind for styling. Dark mode via the `dark:` class and the
  `useTheme` composable.
- In the extension: NO `innerHTML` with server-controlled strings. Use
  `textContent` and the DOM API.

### Crypto

- Any change to `extension/lib/midori-sync-crypto.js`,
  `COLLECTION_INDEX`, or payload layout requires:
  1. ADR in `docs/adr/`.
  2. Update to [docs/encryption.md](encryption.md).
  3. Tests in `tests/crypto.test.js`
     (including property tests).
  4. Migration plan for existing data if compatibility is broken.

---

## 4. Tests

```bash
# Backend
composer test
php artisan test --testsuite=Feature

# JS (includes extension/tests/)
npm test

# Interactive Vitest
npx vitest

# Specific test
php artisan test --filter=SyncAuthServiceTest
npx vitest run tests/crypto.test.js
```

### When to Add Tests

- New endpoint: Feature test under `tests/Feature/`.
- New service: unit test under `tests/Unit/` + Feature integration test
  if DB interaction exists.
- Extension adapter: test in
  `extension/tests/adapters.test.js`.
- Background handler: test in
  `extension/tests/sync-engine.test.js`.
- Crypto change: test in `tests/crypto.test.js` with property tests.

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
| Adapters / handlers / storage shape            | `docs/extension-dev.md`                 |
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
   `composer test`, `npm test`, `composer audit`,
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
- [ ] Does not change extension storage shape
- [ ] Does not require manual migration

## Docs

- [ ] Updated docs from the CONTRIBUTING table as applicable
- [ ] CHANGELOG updated if user-visible changes exist

## Tests

- [ ] composer test passing
- [ ] npm test passing
- [ ] Added tests for the change
```
