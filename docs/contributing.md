# Contributing to Midori Sync

> Gracias por contribuir. Esta guia define como preparar el entorno,
> los estandares de codigo y el flujo de PR. Para arquitectura y
> seguridad ver [architecture.md](architecture.md) y
> [security.md](security.md).

---

## 1. Codigo de conducta

Tratar con respeto a otros contribuidores. Reportes de conducta
inapropiada o vulnerabilidades de seguridad: ver `SECURITY.md` en la
raiz del repo.

---

## 2. Setup local

### Requisitos

- PHP 8.3+
- Composer 2
- Node.js 20+
- Docker + Docker Compose (recomendado para PostgreSQL 17 + Redis 7)
- Firefox o Midori Browser para probar la extension

### Bootstrap

```bash
git clone <repo>
cd midori-sync
cp .env.example .env
composer install
npm install
docker compose up -d        # postgres, redis, nginx (opcional)
php artisan key:generate
php artisan migrate --seed
php artisan serve
npm run dev                 # Vite + Inertia HMR
```

Para la extension ver [extension-dev.md](extension-dev.md).

---

## 3. Estandares de codigo

### PHP / Laravel

- Convenciones Laravel 12 (controllers en `app/Http/Controllers`, Form
  Requests en `app/Http/Requests`, servicios en `app/Services`).
- Tipado estricto cuando sea posible (`declare(strict_types=1)` no es
  obligatorio aun, pero todo codigo nuevo debe usar typed properties y
  return types).
- Nombres de migraciones: `YYYY_MM_DD_HHMMSS_<verb>_<subject>`.
- Cambios en endpoints publicos requieren actualizar
  [docs/api.md](api.md) y, si aplica, [docs/protocol.md](protocol.md)
  con un ADR si rompe compatibilidad.

### JavaScript / Vue

- ES modules en `resources/js/`, scripts clasicos en `extension/`
  (manifest MV2). No mezclar.
- Vue 3 Composition API + `<script setup>`. Inertia para navegacion.
- Tailwind para estilos. Dark mode via clase `dark:` y composable
  `useTheme`.
- En la extension: NO `innerHTML` con strings server-controlled. Usar
  `textContent` y DOM API.

### Crypto

- Cualquier cambio en `extension/lib/midori-sync-crypto.js`,
  `COLLECTION_INDEX` o layout del payload requiere:
  1. ADR en `docs/adr/`.
  2. Actualizacion de [docs/encryption.md](encryption.md).
  3. Tests en `tests/crypto.test.js` (incluir property tests).
  4. Plan de migracion para datos existentes si rompe compat.

---

## 4. Tests

```bash
# Backend
composer test
php artisan test --testsuite=Feature

# JS (incluye extension/tests/)
npm test

# Vitest interactivo
npx vitest

# Test puntual
php artisan test --filter=SyncAuthServiceTest
npx vitest run tests/crypto.test.js
```

### Cuando agregar tests

- Endpoint nuevo: test Feature en `tests/Feature/`.
- Servicio nuevo: test unitario en `tests/Unit/` + integracion en
  Feature si toca DB.
- Adapter de extension: test en `extension/tests/adapters.test.js`.
- Handler de background: test en `extension/tests/sync-engine.test.js`.
- Cambio en crypto: test en `tests/crypto.test.js` con property tests.

### Politica

- No bajar coverage neto.
- Tests deterministas. Para timing usar `Carbon::setTestNow()` o
  `vi.useFakeTimers()`.
- Para flujos OAuth/Socialite, mockear el provider con Mockery (no
  llamar Authentik real).

---

## 5. Documentacion

Todo PR que afecte uno de estos contratos debe actualizar el doc
correspondiente en el mismo PR:

| Cambio                                        | Doc obligatorio                          |
|-----------------------------------------------|------------------------------------------|
| Endpoint backend                              | `docs/api.md`                            |
| Contrato de protocolo / breaking              | `docs/protocol.md` + ADR en `docs/adr/`  |
| Algoritmo / KDF / payload layout              | `docs/encryption.md`                     |
| Migracion de DB con impacto operativo         | `docs/deployment.md`                     |
| Adapters / handlers / shape de storage        | `docs/extension-dev.md`                  |
| Modelo de amenazas, headers, CORS, CSP        | `docs/security.md`                       |
| Cambios visibles al usuario o operador        | `CHANGELOG.md`                           |

ADRs nuevos: copiar plantilla `docs/adr/0000-template.md` (si existe) y
usar numeracion incremental.

---

## 6. Flujo de PR

1. Branch desde `main`: `feat/<topic>`, `fix/<topic>`, `docs/<topic>`.
2. Commits siguen
   [Conventional Commits](https://www.conventionalcommits.org/):
   `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`, `perf:`,
   `security:`.
3. Antes de push: `composer test`, `npm test`, `composer audit`,
   `npm audit`, lint.
4. PR description debe incluir:
   - Que cambia y por que.
   - Impacto en compat (datos, API, storage de extension).
   - Docs actualizados (lista).
   - Checklist de seguridad si aplica (CORS, CSP, auth, crypto).
5. PRs que tocan crypto, auth, CORS, CSP, headers o storage del seed
   requieren revision explicita y ADR.
6. Squash merge por defecto.

---

## 7. Reporte de bugs y features

- Issues en GitHub con labels `bug`, `feature`, `security`.
- Para vulnerabilidades de seguridad NO abrir issue publica. Ver
  [SECURITY.md](../SECURITY.md).

---

## 8. Plantilla de PR (sugerida)

```markdown
## Que

<una linea>

## Por que

<contexto / issue / decision>

## Como

<resumen tecnico>

## Compat

- [ ] No rompe API publica
- [ ] No cambia shape de storage de extension
- [ ] No requiere migracion manual

## Docs

- [ ] Actualicé los docs de la tabla del CONTRIBUTING segun aplique
- [ ] CHANGELOG actualizado si hay cambio visible

## Tests

- [ ] composer test verde
- [ ] npm test verde
- [ ] Agregue tests para el cambio
```
