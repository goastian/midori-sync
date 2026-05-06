# Midori Sync Protocol — Surface de API

> ADR-001. Decision arquitectonica sobre la coexistencia de `/api/ext` y
> `/api/v1`. Complementa `docs/api.md` (referencia canonica MSP) y
> `docs/architecture.md`.

## Contexto

Hoy existen dos superficies HTTP en `routes/api.php`:

- `/api/v1/*` — Midori Sync Protocol (MSP) completo. Superficie canonica y
  versionada del servidor.
- `/api/ext/*` — Adaptador simplificado para la extension (formato flat de
  BSOs, OAuth callback, pairing).

La duda era si ambos deben existir, o si uno debe absorber al otro. Este
documento fija la decision.

## Decision

`/api/v1` es la superficie canonica y de largo plazo. Cualquier cliente
nuevo (CLI, mobile, test harness) debe consumir `/api/v1`.

`/api/ext` queda como **adaptador oficial de la extension** sobre el mismo
backend (`SyncStorageService`, `SyncAuthService`). No es un motor paralelo:
es una capa fina de transporte pensada para el entorno del navegador
(manifest MV2, sin headers `X-If-Unmodified-Since`, sin conflictos batch
granulares, sin deep linking).

Se mantiene porque:

- El formato flat `[{id, payload, modified}, ...]` es mas barato de generar
  en el navegador y esta congelado por compatibilidad con el store local.
- El flujo OAuth de extension (`/api/ext/auth/start` + `poll`) tiene
  propiedades distintas: es un handshake que no existe en `/api/v1`.
- Desacopla la evolucion del MSP de la extension instalada en usuarios.

## Reglas de oro

1. **Un solo backend.** Ambas superficies deben delegar en
   `App\Services\SyncStorageService` y `App\Services\SyncAuthService`. No
   se permite duplicar logica de negocio en controllers de `Api\Ext`.
2. **`/api/v1` primero.** Toda nueva capacidad (colecciones, delta sync,
   quotas, etags) se especifica y valida primero en `/api/v1`.
3. **`/api/ext` solo adapta.** Si `/api/ext` necesita un endpoint nuevo,
   debe ser una proyeccion de uno existente en `/api/v1`, no una feature
   exclusiva.
4. **Sin breaking changes en `/api/ext`.** Mientras haya extensiones
   distribuidas, el contrato flat de BSOs es estable. Cambios incompatibles
   requieren un `/api/ext/v2`.
5. **Mismo middleware.** Ambas superficies usan `ValidateSyncToken`,
   `TrackDevice`, `EnforceQuota` y `CorsForExtension` cuando aplican.

## Tabla de correspondencias

| Operacion              | `/api/v1`                         | `/api/ext`                         |
|------------------------|-----------------------------------|------------------------------------|
| Intercambio OAuth      | `POST /auth/token`                | `GET /auth/start` + `/auth/poll`   |
| Revocar token          | `DELETE /auth/token`              | `POST /logout`                     |
| Listar records         | `GET /collections/{name}`         | `GET /storage/{collection}?newer=` |
| Upsert unitario        | `PUT /collections/{name}/{id}`    | (no existe — se batchea)           |
| Batch upsert           | `POST /collections/{name}`        | `POST /storage/{collection}`       |
| Borrar record          | `DELETE /collections/{name}/{id}` | (proximo: flag `deleted: true`)    |
| Informacion de sync    | `GET /sync/info`                  | `GET /storage/info`                |
| Estado por coleccion   | `GET /sync/status`                | `GET/POST /sync/status`            |
| Pairing (generar)      | —                                 | `POST /pair`                       |
| Pairing (redimir)      | —                                 | `POST /pair/redeem`                |
| Crypto key bundle      | `GET/POST /crypto/keys`           | (misma ruta, montada en `ext`)     |

## Headers condicionales

`/api/v1` implementa `ETag` + `If-None-Match` en endpoints de lectura
cacheables (`GET /sync/info`, `GET /sync/status`,
`GET /collections/{name}`). Tambien acepta `If-Modified-Since` cuando la
respuesta tenga un `last_modified` definido.

`/api/ext` **no** implementa etags por ahora: la extension usa delta sync
por `newer=` en su lugar. Si se necesita, se agrega en un MSP v2.

## Compresion

La compresion gzip esta disponible como middleware opcional
(`NegotiateCompression`) controlado por `SYNC_HTTP_COMPRESSION=true`. Por
default viene apagada porque en despliegues estandar nginx ya gzipa las
respuestas del upstream PHP-FPM. Activarla en PHP solo tiene sentido en
despliegues sin nginx / sin reverse proxy.

## Rate limiting

El limiter `sync` aplica a `/api/*` y diferencia lectura (mas generoso) de
escritura (mas estricto), con claves separadas para que una rafaga de
lecturas no consuma la cuota de escritura:

- `SYNC_RATE_LIMIT_READ` (default 120/min) — `GET`, `HEAD`, `OPTIONS`.
- `SYNC_RATE_LIMIT_WRITE` (default 60/min) — `POST`, `PUT`, `PATCH`,
  `DELETE`.

La variable legacy `SYNC_RATE_LIMIT` sigue respetada como fallback de
ambas.

## Criterios para deprecar `/api/ext`

`/api/ext` se podra eliminar cuando:

1. La extension soporte Manifest V3 y un cliente MSP `/api/v1` equivalente.
2. El flujo OAuth de extension se rehaga sobre `/api/v1` (con un endpoint
   de handshake documentado).
3. No queden instalaciones con el contrato BSO flat en el ecosistema
   soportado.

Hasta entonces, `/api/ext` es una API de primera clase, solo que su
contrato publico es mas acotado que el de `/api/v1`.
