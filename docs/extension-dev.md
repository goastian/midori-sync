# Midori Sync — Extension Development Guide

> Audiencia: desarrolladores que trabajan sobre `extension/` (browser
> extension MV2/Gecko, base para migracion MV3).
> Para arquitectura general ver [architecture.md](architecture.md);
> para crypto ver [encryption.md](encryption.md); para protocolo
> backend ver [protocol.md](protocol.md).

---

## 1. Setup local

### Requisitos

- Midori Browser o Firefox >= 115.
- Node.js >= 20 y npm para correr la suite Vitest.
- Backend Midori Sync corriendo en `http://localhost:8000` (Docker
  recomendado: `docker compose up -d`).

### Cargar la extension

Firefox / Midori (MV2):

1. Abrir `about:debugging#/runtime/this-firefox`.
2. "Load Temporary Add-on" -> seleccionar `extension/manifest.json`.
3. La extension queda activa hasta cerrar el navegador.

Para iterar mas rapido, mantener `about:debugging` abierto y usar
"Reload" tras cada cambio. El estado en `storage.local` (seed phrase,
device id, ultimos cursores) sobrevive a recargas pero NO a desinstalar.

### Backend de pruebas

```bash
docker compose up -d
php artisan migrate --seed
php artisan serve
```

El primer pairing requiere un usuario autenticado en el dashboard. La
extension lo redirige a `http://localhost:8000` para login Authentik
y luego completa OAuth via `/api/ext/auth/start` + `/api/ext/auth/poll`
o pairing manual (`/api/ext/pair` + `/api/ext/pair/redeem`).

---

## 2. Layout del repo `extension/`

```
extension/
  manifest.json          # MV2 hoy, plan MV3 en extension-mv3-migration.md
  background/
    background.js        # motor unico de sync (handlers + dispatch)
    collection-adapters/ # bookmarks, history, tabs, browser-settings,
                         # midori-tab, midori-privacy
  lib/
    midori-sync-crypto.js  # Argon2id, KDF, XChaCha20-Poly1305
    argon2-worker.js       # Web Worker dedicado (carga su libsodium)
    ext-errors.js          # clasificacion de errores HTTP -> codigos
    sodium.js / sodium-wrappers
  options/                 # pagina de configuracion (devices, rotacion,
                           # export/import, wipe remoto, lock local)
  popup/                   # popup compacto (estado, sync now, lock)
  setup/                   # primer pairing / setup wizard
  icons/
  tests/                   # Vitest (adapters, http-errors, sync-engine,
                           # pairing-oauth)
```

---

## 3. Modelo del background

`background/background.js` corre como background page persistente (MV2).
Centraliza el ciclo de sync y expone una API por `runtime.onMessage`.

### 3.1 Dispatch de mensajes

Patron actual:

```js
const handlers = {
  syncNow,
  listDevices,
  renameDevice,
  revokeDevice,
  wipeServerData,
  exportConfig,
  importConfig,
  enableLocalPassphrase,
  disableLocalPassphrase,
  lockEncryption,
  unlockEncryption,
  getLockStatus,
  previewKeyRotation,
  performKeyRotation,
  getRotationStatus,
  abortKeyRotation,
  // ...
};

browser.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  const fn = handlers[msg.type];
  if (!fn) return false;
  Promise.resolve(fn(msg.data))
    .then((result) => sendResponse({ ok: true, data: result }))
    .catch((err) => sendResponse({ ok: false, error: err.message, code: err.code }));
  return true; // keep channel open for async response
});
```

Cuando se agrega un nuevo handler:

1. Definir la funcion async en `background.js`.
2. Registrarla en el objeto `handlers`.
3. Llamarla desde options/popup con
   `browser.runtime.sendMessage({ type, data })` envuelto por `sendBg()`.

### 3.2 sendBg() y propagacion de errores

`options/` y `popup/` usan un wrapper `sendBg(type, data)` que:

- Llama `runtime.sendMessage`.
- Si la respuesta es `{ ok: false, error, code }`, lanza un `Error` con
  `.code` (clasificacion semantica) y `.message` (texto legible).

Esto evita que los callers pierdan el codigo del error. Mapear codigos
a strings UI usa la tabla en `lib/ext-errors.js`.

### 3.3 HTTP

Todas las llamadas pasan por `extFetchJson(path, opts)` que:

- Inyecta `Authorization: Bearer <token>` desde `storage.local`.
- Clasifica respuestas via `classifyHttpError()` -> codigos `network`,
  `token_expired`, `quota_exceeded`, `collection_disabled`, `forbidden`,
  `not_found`, `precondition_failed`, `rate_limited`, `server_error`.
- En `token_expired` (401) limpia el token local y dispara re-pairing.

`fetchFn` se inyecta como parametro para permitir tests sin jsdom.

---

## 4. Contrato de un adapter de coleccion

Un adapter vive en `background/collection-adapters/<name>.js` y debe
exponer un objeto con esta forma:

```js
globalThis.MidoriSyncAdapters = globalThis.MidoriSyncAdapters || {};
globalThis.MidoriSyncAdapters['bookmarks'] = {
  collection: 'bookmarks',
  collectionIndex: 0, // estable, ver COLLECTION_INDEX en lib/midori-sync-crypto.js

  // Pull desde la fuente de verdad del navegador (browser.bookmarks, ...)
  // y devolver registros normalizados.
  async snapshot() {
    return [
      {
        id: 'sha256-or-blake2b-hex',
        payload: { /* contenido a cifrar */ },
        deleted: false,
        modified_at: 1700000000,
      },
    ];
  },

  // Aplicar registros remotos al navegador.
  async apply(records) {
    for (const r of records) { /* ... */ }
  },

  // Opcional: limpiar estado local cuando el usuario hace wipe.
  async reset() {},
};
```

### 4.1 IDs

- IDs derivados de URL usan `BLAKE2b-128` (hex) via
  `sodium.crypto_generichash(16, url)`. Nunca DJB2 ni hashes inseguros.
- IDs derivados de objetos del navegador con id estable (bookmarks,
  history) deben mantenerse constantes a traves de sync para que el
  upsert server-side funcione.

### 4.2 Indices crypto

`COLLECTION_INDEX` (en `lib/midori-sync-crypto.js`) asigna un indice
inmutable por coleccion. NUNCA reasignarlo: rompe descifrado de datos
existentes. Para una coleccion nueva, usar el siguiente indice libre y
actualizar:

1. `COLLECTION_INDEX` en `lib/midori-sync-crypto.js`.
2. `CollectionSeeder` en `database/seeders/CollectionSeeder.php`.
3. El adapter en `extension/background/collection-adapters/`.
4. La lista `ALL_COLLECTIONS` en `options/`.

El guardrail `tests/collection-scope.test.js` falla si los tres lados
divergen.

### 4.3 Aliases retrocompatibles

Aliases (ej. `open-tabs` -> `tabs`) viven SOLO en `COLLECTION_INDEX`
(ambos nombres apuntan al mismo indice). El seeder y los adapters usan
el nombre canonico.

---

## 5. Crypto desde un adapter

El adapter NO cifra: devuelve `payload` en claro. El motor cifra antes
de subir y descifra al bajar usando la subclave derivada del
`collectionIndex`.

```js
// En background.js, simplificado
const subKey = await crypto.deriveSubKey(masterKey, adapter.collectionIndex, 'MSPv1key');
const ciphertext = await crypto.encrypt(subKey, JSON.stringify(record.payload));
// ciphertext = base64(nonce(24) || ct || tag(16))
```

Layout del payload, contexto KDF (`MSPv1key` para datos, `MSPv1lck`
para lock local) y rotacion de master key estan documentados en
[encryption.md](encryption.md).

---

## 6. Storage local

Convenciones de claves en `browser.storage.local`:

| Clave                     | Contenido                                              |
|---------------------------|--------------------------------------------------------|
| `serverUrl`               | Base URL del backend (`http://localhost:8000`).        |
| `syncToken`               | Token Bearer (cleartext en `local`; mitigacion: lock). |
| `deviceId`                | UUID asignado por backend.                             |
| `seedPhrase`              | BIP39 24 palabras (oculto si lock activo).             |
| `encryptionKey`           | Master key derivada (oculto si lock activo).           |
| `lockBundle`              | `{ ciphertext, salt, kdf, version }` cuando hay lock.  |
| `lastSyncTimes`           | `{ <collection>: <unix_ts> }` por coleccion.           |
| `rotationState`           | Checkpoint de rotacion en curso.                       |
| `previousEncryptionKey`   | `M_old` durante rotacion (fallback de descifrado).     |
| `syncSettings`            | Toggles por coleccion + intervalo.                     |

Versionar siempre que cambie el shape: agregar `version: N` al objeto y
migracion explicita en `background.js` (`migrateStorage()`).

---

## 7. Tests

```bash
# Toda la suite JS (incluye extension/tests/)
npm test

# Solo extension
npx vitest run extension/tests/
```

Patron usado para cargar scripts no-modulo (manifest MV2 los carga como
`<script>` clasicos):

```js
const code = fs.readFileSync('extension/lib/ext-errors.js', 'utf8');
new Function(code).call(globalThis);
const { classifyHttpError } = globalThis.MidoriSyncExtErrors;
```

Para tests del motor de sync, mockear `fetch` y inyectar `fetchFn` en
`extFetchJson`:

```js
const fakeFetch = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => ({}) });
await extFetchJson('/api/ext/sync/info', { fetchFn: fakeFetch });
```

DOM XSS-safe: para renderizar strings server-controlled siempre usar
`element.textContent = value`, NUNCA `innerHTML`.

---

## 8. Migracion a Manifest V3

Plan completo en [extension-mv3-migration.md](extension-mv3-migration.md).
Resumen:

- Fase A: mantener MV2.
- Fase B: refactor de `background.js` para que sea SW-safe (no APIs
  bloqueantes, no Worker en Chromium SW, persistir todo estado en
  `storage.local`).
- Fase C: build dual (manifest MV2 + MV3 desde la misma fuente).
- Fase D: drop MV2.

---

## 9. Checklist al abrir un PR sobre `extension/`

- [ ] Tests Vitest verdes (`npx vitest run`).
- [ ] Si toca crypto o `COLLECTION_INDEX`, guardrail
      `tests/collection-scope.test.js` verde y `docs/encryption.md`
      actualizado.
- [ ] Si agrega un handler nuevo, hay test en
      `extension/tests/sync-engine.test.js` o equivalente.
- [ ] Si toca UI, no introduce `innerHTML` con strings server-controlled.
- [ ] Si toca CSP, `manifest.json` y meta tags de `options/popup/setup`
      siguen alineados.
- [ ] Si toca storage shape, hay migracion en `migrateStorage()` y test.
