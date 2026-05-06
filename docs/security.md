# Midori Sync — Security

> Este documento es el contrato de seguridad vivo del proyecto.
> Cualquier PR que toque auth, crypto, middleware, CORS, CSP, headers
> o el storage del seed phrase debe actualizarlo.

---

## 1. Modelo de amenazas

### 1.1 Activos

- **Datos del usuario**: bookmarks, history, tabs, browser-settings,
  midori-tab, midori-privacy y `passwords`. Todos cifrados E2E.
- **Seed phrase BIP39 (24 palabras)**: la unica preimagen que permite
  derivar la master key. Compromiso = perdida total de
  confidencialidad.
- **Master key (M)**: derivada del seed via Argon2id; subclaves por
  coleccion via BLAKE2b (contexto `MSPv1key`).
- **Sync tokens**: bearer tokens emitidos por el backend; permiten leer
  ciphertext y meta del usuario (no datos en claro).
- **Cuentas Authentik**: identidad del usuario; rotacion de credenciales
  fuera del alcance de Midori Sync.

### 1.2 Adversarios considerados

| Adversario                      | Capacidad asumida                              | Mitigacion principal                       |
|---------------------------------|------------------------------------------------|--------------------------------------------|
| Operador del backend            | Lee DB, logs y filesystem completos            | E2E: solo ve ciphertext + metadatos        |
| Atacante en la red              | MITM activo si no hay TLS                      | HTTPS + HSTS en produccion                 |
| Atacante con acceso al device   | Lee `storage.local` de la extension            | Lock local opcional con passphrase         |
| Atacante web (CSRF / XSS)       | Inyecta JS en dashboard o paginas internas     | CSP + escape Vue + DOM-safe en extension   |
| Atacante con extension hostil   | Otra extension con permisos similares          | Origenes CSP estrictos + tokens scoped     |
| Adversario que roba un token    | Replay del Bearer hasta TTL                    | Hash en DB + TTL + revocacion + auditoria  |
| Adversario con poder de calculo | Bruteforce offline del seed                    | Argon2id (ops=3, mem=64MB) + 24 palabras   |

### 1.3 Fuera de alcance

- Compromiso del navegador host (keylogger, screen recorder).
- Compromiso del proveedor de identidad (Authentik) en si mismo.
- Coercion legal sobre el usuario (la seed esta solo en su device).
- Side-channel ataques sobre el SO o CPU del cliente.

---

## 2. Cifrado E2E

Detalle algoritmico completo: [encryption.md](encryption.md).
Resumen de invariantes:

- **KDF**: Argon2id (`ops=3`, `mem=64 MB`) sobre el seed BIP39 + salt
  fijo dependiente del bundle, ejecutado en Web Worker dedicado
  (`extension/lib/argon2-worker.js`) con fallback sincrono.
- **Subclaves por coleccion**: BLAKE2b con contexto `MSPv1key` y
  `subkey_id = COLLECTION_INDEX[name]`. Indices estables; un cambio
  rompe descifrado de datos existentes.
- **AEAD**: XChaCha20-Poly1305. Layout del payload subido al backend:
  `base64(nonce(24) || ciphertext || tag(16))`.
- **Lock local**: bundle `M` cifrado con passphrase via Argon2id +
  contexto KDF `MSPv1lck` (distinto de `MSPv1key`).
- **Rotacion de master key**: incremental, con cursor reanudable y
  fallback de descifrado a `M_old` durante estados mixtos. Procedimiento
  documentado en [encryption.md](encryption.md) y
  [runbooks.md](runbooks.md).

El backend NUNCA tiene acceso a la seed, a `M`, a las subclaves ni al
plaintext.

---

## 3. Autenticacion y sesiones

### 3.1 Capas

- **Authentik (OIDC)**: identidad del usuario, login del dashboard,
  base del flujo OAuth de la extension (`/api/ext/auth/start`,
  `/api/ext/auth/poll`).
- **`SyncSession`** (ADR-002): unica capa de auth para `/api/v1` y
  `/api/ext`. Tokens bearer con TTL configurable (`SYNC_TOKEN_TTL`).
- **Sanctum**: presente como utilidad para un futuro API SPA del
  dashboard. NO se usa para sync.

### 3.2 Tokens

- Hash en reposo: SHA-256. La DB nunca almacena el bearer en claro.
- TTL: configurable; default 30 dias. Cleanup horario via
  `sync:cleanup-expired`.
- Revocacion individual y bulk-por-usuario disponibles desde el
  dashboard (`Audit/Index`) y la extension (revoke device).
- Auditoria: IP, User-Agent (truncado), `last_used_at`, `last_seen_ip`.

### 3.3 Pairing manual

`/api/ext/pair` -> `/api/ext/pair/redeem` con codigo de un solo uso,
expiracion corta (TTL configurable) y proteccion contra replay
(`PairingFlowTest`).

---

## 4. Headers y politicas web

### 4.1 nginx (produccion, `nginx.prod.conf`)

- `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`
- `Content-Security-Policy`: `default-src 'self'; script-src 'self';
  style-src 'self' 'unsafe-inline'; connect-src 'self' <authentik>;
  img-src 'self' data:; frame-ancestors 'none'`
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy`: deshabilita microfono, camara, geolocalizacion.

> Estado actual: HSTS y CSP son TODO abierto en `docker/nginx.conf`
> (Bloque 2 / Fase 7). Ver [plan-status.md](plan-status.md).

### 4.2 CORS (`CorsForExtension`)

- Whitelisting por configuracion `CORS_ALLOWED_ORIGINS` (TODO: hoy
  `*`).
- Preflight: `OPTIONS` responde solo con headers permitidos.
- Echo de `Origin` solo si pertenece a la lista.
- Origenes esperados:
  `moz-extension://<uuid>`, dominio del dashboard,
  `https://accounts.astian.org`.

### 4.3 Extension (CSP en `manifest.json` + meta tags)

```
script-src 'self';
object-src 'self';
connect-src 'self' http://localhost:8000 https://sync.astian.org https://accounts.astian.org;
style-src 'self' 'unsafe-inline';
img-src 'self' data: https:;
default-src 'self';
```

Meta `Content-Security-Policy` replicada en `options/options.html`,
`popup/popup.html`, `setup/setup.html`.

---

## 5. Rate limiting y quotas

- Rate limiter `sync` con buckets independientes:
  - `sync:r:*` (lectura, `SYNC_RATE_LIMIT_READ`).
  - `sync:w:*` (escritura, `SYNC_RATE_LIMIT_WRITE`).
- Cobertura: `RateLimitTest`.
- Quota por usuario via `EnforceQuota`. Tombstones excluidos del
  computo. GETs exentos. Cobertura: `EnforceQuotaTest`.

---

## 6. Storage del cliente y lock local

- Seed phrase y master key viven en `browser.storage.local`. Sin lock
  local estan en claro (mitigacion: solo accesibles por la extension
  misma; CSP impide inyeccion externa).
- Lock local opcional: el usuario activa una passphrase; el seed y `M`
  se borran de `storage.local` y solo queda `lockBundle` cifrado bajo
  contexto `MSPv1lck`.
- Lock por inactividad: alarm con timeout configurable (default 15 min).
- Unlock requiere derivar Argon2id desde la passphrase; mismas
  parametrizaciones que la KDF principal.

---

## 7. Logging y auditoria

- Eventos sensibles que DEBEN loguearse de forma estructurada (TODO
  parcial — Bloque 2 abierto):
  - login / logout / pairing / OAuth complete.
  - revocacion de token (single y bulk).
  - cambios de cuota.
  - borrado de coleccion / borrado total.
  - fallos repetidos de auth (>=N en M minutos).
- Auditoria visible al usuario: `Audit/Index` lista sesiones activas y
  expiradas con IP, UA, device y permite revoke individual y revoke-all.

---

## 8. Dependencias

- `composer audit` y `npm audit` deben correr en CI por PR (TODO
  abierto en Fase 7).
- Dependabot recomendado para PRs automatizados de seguridad.
- SBOM: pendiente (`syft` u OWASP Dependency-Track) — Fase 8.

---

## 9. Reporte de vulnerabilidades

Ver [SECURITY.md](../SECURITY.md) en raiz del repo. Resumen:

- NO abrir issue publica.
- Email a `security@astian.org` (PGP en `.well-known/security.txt`).
- Triage objetivo: <72h. Fix objetivo: <30 dias para criticas.

---

## 10. Cambios que requieren ADR

Cualquier cambio en estas areas requiere ADR en `docs/adr/`:

- KDF, AEAD, layout del payload o `COLLECTION_INDEX`.
- Capa de auth (`SyncSession`, Sanctum, Authentik).
- CORS / CSP / HSTS / headers de seguridad.
- Storage shape de la extension (seed, lockBundle, rotationState).
- Contrato `/api/v1` o `/api/ext` con impacto retrocompatible.
