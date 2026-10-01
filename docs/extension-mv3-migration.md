# Manifest V3 Migration Plan — Midori Sync Extension

> Status: planned (2026-05-05). The extension currently ships as
> Manifest V2 for Firefox-based browsers (Midori and Firefox ESR).
> This document captures the matrix of changes required to ship a
> dual-target build (MV2 retained for Gecko ≤ 127, MV3 for Chrome /
> Edge / Firefox ≥ 128).

---

## Why MV3 (eventually)

- Chrome and Edge **only** accept MV3 in the Web Store.
- Firefox supports MV3 since v109 (event pages, declarative net
  request) and is moving the ESR baseline forward.
- Continuing on MV2 long-term locks Midori Sync out of Chromium
  ecosystems and makes the security review story harder.

The migration is **not** urgent for the current Gecko-only
distribution, but the surface area below should be fixed before
the next major release.

---

## Surface-area matrix

| Concern                  | MV2 (today)                                   | MV3 target                                                                                |
|--------------------------|-----------------------------------------------|-------------------------------------------------------------------------------------------|
| `manifest_version`       | `2`                                           | `3`                                                                                       |
| Background context       | Persistent `background.scripts` (page)        | `background.service_worker` (Chromium) / event page (Firefox)                             |
| Globals (`window`, DOM)  | Available                                     | Service worker has **no DOM**, no `window`, no synchronous storage                        |
| `browser_action`         | `browser_action`                              | `action`                                                                                  |
| Host permissions         | Mixed in `permissions`                        | Must be split into `host_permissions`                                                     |
| Web-accessible resources | Implicit via `web_accessible_resources` array | Object form `{ resources, matches }` required                                             |
| CSP                      | `content_security_policy: <string>`           | `content_security_policy: { extension_pages, sandbox }` object                            |
| Remote code              | Allowed if CSP permits                        | **Forbidden**: all script must be packaged                                                |
| Persistent state         | In-memory module globals                      | Must persist via `storage.local`/`session` (service worker can be killed any time)        |
| Alarms                   | OK                                            | OK (must be re-armed after SW restart; `chrome.alarms.create` survives)                   |
| Web Workers              | OK from background page                       | Service workers **cannot** spawn `Worker` (Chromium); Firefox does, but treat as fallback |
| `XMLHttpRequest`         | OK                                            | Removed from SW context — use `fetch`                                                     |
| `executeScript`          | `tabs.executeScript`                          | `scripting.executeScript`                                                                 |

---

## Concrete changes required in this codebase

### 1. Background

- File: [extension/background/background.js](../extension/background/background.js).
- Today it runs as a persistent page and keeps these in-memory globals:
  `authState`, `lastSyncTimes`, `encryptionKey`, `previousEncryptionKey`,
  `lockState`, `seedPhraseInMemory`, `rotationState`, `_idb`.
- Action: every read of these globals must tolerate a cold service
  worker. Strategy:
  - Persist the state already covered (`auth`, `lastSyncTimes`,
    `lockState`, `rotationState`) — already done.
  - **Stop holding `encryptionKey` as the source of truth.** Re-read
    it from `storage.local` (or the lock bundle) on each invocation.
  - Move IndexedDB initialization to a lazy helper — service workers
    can use IndexedDB but not keep it open across restarts.
- Replace `browser.runtime.onStartup.addListener(initializeState)`
  with a guard that runs `initializeState()` from the SW top-level
  on every wake-up.

### 2. Argon2id worker

- Service workers in Chromium **cannot** spawn `new Worker(...)`.
- The current crypto layer already degrades gracefully to a sync
  fallback when `Worker` is unavailable
  ([midori-sync-crypto.js](../extension/lib/midori-sync-crypto.js#L70-L90)),
  so the migration plan is:
  - On Firefox MV3 keep using the worker.
  - On Chromium MV3 fall back to the synchronous Argon2id path. This
    will block the SW for ~500 ms during seed setup; acceptable for a
    rare action.

### 3. Manifest

- Rename `browser_action` → `action`.
- Move all `http://...` / `https://...` entries from `permissions` to
  `host_permissions`.
- Replace the current single-string `content_security_policy`
  ([extension/manifest.json](../extension/manifest.json)) with the
  object form:

  ```json
  "content_security_policy": {
      "extension_pages": "default-src 'self'; script-src 'self'; ..."
  }
  ```

- Replace `background.scripts` with:

  ```json
  "background": { "service_worker": "background/service-worker.js", "type": "module" }
  ```

  and split the current concatenated background scripts into ES
  modules imported from `service-worker.js`.

### 4. Storage and session

- Sessions tied to the in-memory token cease to exist on SW restart.
  All current call sites already pull the token from
  `browser.storage.local.get('auth')` — keep this discipline.

### 5. Tests

- `extension/tests/` already mocks `browser.*` per test
  ([helpers/browser.js](../extension/tests/helpers/browser.js)). MV3
  primarily affects the runtime, not the unit-level contracts, so the
  existing test surface remains valid.
- Add a regression test that exercises the SW cold-path: clear
  in-memory state, simulate a restart, expect storage-driven
  re-initialization.

### 6. Build

- Introduce two manifests built from a shared source:
  - `manifest.v2.json` for Gecko ≤ 127.
  - `manifest.v3.json` for Chromium and Gecko ≥ 128.
- Add an npm script `build:ext:v2` / `build:ext:v3` that emits a
  zipped bundle for each target.

---

## Phasing

1. **Phase A (current)** — Stay on MV2. Lock down CSP (done).
2. **Phase B** — Refactor background to be SW-safe (no DOM, all state
   in storage, all crypto reads pull keys lazily).
3. **Phase C** — Add the dual-build pipeline and ship MV3 to Chromium
   stores while keeping the MV2 bundle for older Firefox.
4. **Phase D** — Drop MV2 once the MV2 user base is below the
   threshold defined by product (target: < 5%).

## Risks

- **Cold-start latency**: Argon2id on the SW thread may exceed
  Chromium's 30 s SW idle limit if combined with a slow network.
  Mitigation: keep KDF derivations in a single short call and never
  hold the SW open waiting for network responses.
- **Lock state**: Without persistence, an SW restart could surface
  a "locked" state to the user that isn't really a manual lock. The
  inactivity alarm must remain authoritative (already implemented via
  `browser.alarms`).
- **Worker fallback**: The synchronous Argon2id path has been kept
  green by the unit tests but has not been benchmarked on low-end
  hardware. Add a perf gate before flipping the default.
