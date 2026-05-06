# Changelog

Todos los cambios notables al proyecto se documentan en este archivo.

El formato sigue [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
y el proyecto usa [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Documentacion operativa: `docs/extension-dev.md`, `docs/contributing.md`,
  `docs/security.md`, `docs/runbooks.md`.
- `SECURITY.md` en raiz y `public/.well-known/security.txt`.
- Este `CHANGELOG.md`.

## [0.5.0] - 2026-05-06

### Added

- Fase 5 web dashboard cerrada: grafico 7/30d, dark mode, filtros y
  paginacion en `Audit/Index`, vista detallada de cuotas por coleccion.
- Fase 4 extension cerrada: gestion de dispositivos en options/popup,
  export/import de configuracion, borrado remoto, estados de error
  con codigo, CSP en manifest + paginas internas.
- Plan de migracion a Manifest V3 (`docs/extension-mv3-migration.md`).
- Tests Vitest de extension: `adapters`, `http-errors`, `sync-engine`,
  `pairing-oauth`.
- `ExtDeviceController` (`/api/ext/devices`, `/api/ext/data` wipe) y
  `ExtDeviceFlowTest`.
- `extension/lib/ext-errors.js` para clasificacion centralizada de
  errores HTTP.

### Fixed

- Revoke handler de devices: query corregida usando FK PK correcto
  (`devices.id` en lugar de `devices.device_id`).

## [0.4.0] - 2026-05-05

### Added

- Fase 3 crypto cerrada: Argon2id en Web Worker dedicado
  (`extension/lib/argon2-worker.js`) con fallback sincrono.
- Lock local opcional con passphrase + timeout de inactividad
  (contexto KDF `MSPv1lck`).
- Rotacion incremental de master key con cursor reanudable y fallback
  a `M_old`. UI en options + indicador de lock en popup.
- Property tests sobre crypto (`tests/crypto.test.js`).
- Guardrail `tests/collection-scope.test.js` para consistencia
  seeder ↔ `COLLECTION_INDEX` ↔ adapters.
- Consolidacion `passwords` (indice 8), `tabs` canonico, `open-tabs`
  alias retrocompatible. Migracion
  `2026_05_05_000002_consolidate_open_tabs_into_tabs`.
- Indice parcial `records_active_delta_index` (driver-aware).
- ADR-002: `docs/adr/0002-sanctum-vs-syncsession.md`.
- Tests dedicados: `SyncAuthServiceTest`, `ValidateSyncTokenTest`,
  `EnforceQuotaTest`, `ExtAuthFlowTest`, `PairingFlowTest`.

### Changed

- `docs/encryption.md` reescrito: layout exacto del payload, contrato
  de indices estables, ejecucion en worker, modo lock, rotacion
  incremental.

## [0.3.0] - 2026-05-04

### Added

- Fase 2 motor de sync cerrada: ADR-001 (`/api/v1` canonico,
  `/api/ext` adaptador), UPSERT nativo en `batchUpsert()`,
  ETag/`If-None-Match`/`Last-Modified`, compresion negociada
  (`NegotiateCompression`), rate limit segmentado read/write.
- Cache de `Collection::findByName()` con invalidacion por eventos.
- Vista `Audit/Index` con revoke individual y bulk.
- Hash BLAKE2b-128 en derivacion de IDs (reemplaza DJB2 inseguro).

## [0.2.0] - 2026-05-03

### Added

- Fase 1 fundacion completa: Laravel 12, PostgreSQL 17, Redis 7,
  Docker Compose, migraciones nucleo, modelos, autenticacion via
  Authentik (`AuthController`).

## [0.1.0] - 2026-05-01

### Added

- Bootstrap inicial del proyecto.

---

[Unreleased]: https://github.com/astian-org/midori-sync/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/astian-org/midori-sync/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/astian-org/midori-sync/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/astian-org/midori-sync/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/astian-org/midori-sync/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/astian-org/midori-sync/releases/tag/v0.1.0
