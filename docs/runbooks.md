# Midori Sync — Runbooks y SLOs

> Procedimientos operativos para incidentes, mantenimiento programado y
> rotaciones criticas. Audiencia: operadores del backend Midori Sync.
> Para arquitectura ver [architecture.md](architecture.md); para
> seguridad ver [security.md](security.md).

---

## 1. SLOs

### 1.1 Disponibilidad

| Servicio                         | SLO    | Ventana    | Notas                                |
|----------------------------------|--------|------------|--------------------------------------|
| `/api/v1/sync/info` (read)       | 99.9%  | 30 dias    | Critico para funcionamiento basico.  |
| `/api/v1/collections/*` (read)   | 99.9%  | 30 dias    | Critico.                             |
| `/api/v1/collections/*` (write)  | 99.5%  | 30 dias    | Tolera mas degradacion bajo carga.   |
| `/api/ext/auth/*` y `/api/ext/pair/*` | 99.5% | 30 dias | Pairing/OAuth de la extension.       |
| Dashboard web                    | 99.0%  | 30 dias    | Acceso UI, no bloquea sync.          |

### 1.2 Latencia (p95)

| Operacion                    | SLO p95   | Notas                              |
|------------------------------|-----------|------------------------------------|
| GET `/sync/info`             | < 150 ms  | Solo metadatos.                    |
| GET `/collections/{name}`    | < 400 ms  | Hasta 1000 records con ETag hit.   |
| POST `/collections/{name}`   | < 600 ms  | Batch UPSERT hasta 500 records.    |
| OAuth poll                   | < 250 ms  | Lookup en Redis.                   |

### 1.3 Durabilidad

- DB PostgreSQL: backups diarios, retencion 30 dias, restore probado
  trimestralmente.
- Records con `deleted = true` (tombstones) retenidos 90 dias antes de
  purga definitiva.

### 1.4 Error budget

- Disponibilidad 99.9% sobre 30 dias = ~43 minutos de downtime tolerado.
- Si en una semana se consume >50% del budget mensual, congelar
  releases hasta resolver causas raiz.

---

## 2. Runbook: rotacion de master key (cliente)

> Esta rotacion es iniciada por el usuario desde `options/` de la
> extension. Operacion sin downtime, no requiere accion del operador
> backend salvo monitoreo.

### 2.1 Pre-flight

1. Usuario verifica que tiene la seed phrase actual (M_old) accesible.
2. Extension genera seed nueva (24 palabras BIP39) y deriva M_new
   en Web Worker.
3. UI muestra preview con la seed nueva y pide confirmacion explicita.

### 2.2 Ejecucion

1. Persiste `rotationState = { newMnemonic, newKeyB64, completed: [], currentCollection }`
   en `storage.local`.
2. Mantiene `previousEncryptionKey = M_old` mientras dure la rotacion.
3. Por cada coleccion en orden estable:
   - Full scan de records remotos via GET `/collections/{name}`.
   - Descifra con M_old, re-cifra con M_new (mismo `collectionIndex`).
   - PUT batch con ciphertext nuevo.
   - Marca coleccion en `completed[]`.
4. Al terminar todas:
   - Reemplaza `encryptionKey` con M_new.
   - Borra `previousEncryptionKey` y `rotationState`.

### 2.3 Resume tras crash

`getRotationStatus` lee `rotationState`. Si existe, options muestra
"Resume rotation". El handler reanuda desde `currentCollection` sin
reprompts.

### 2.4 Fallback de descifrado

Durante estados mixtos, `decryptBsoPayload` intenta primero con la
clave activa y, si falla AEAD, reintenta con `previousEncryptionKey`.

### 2.5 Monitoreo backend

- Picos de PUTs sobre todas las colecciones de un usuario son
  esperables.
- Si rate limit `sync:w:*` golpea durante rotacion, considerar elevar
  `SYNC_RATE_LIMIT_WRITE` para el usuario afectado.

---

## 3. Runbook: revocacion masiva de tokens (incidente de seguridad)

### 3.1 Trigger

- Filtracion confirmada de bearer tokens.
- Compromiso de DB.
- Solicitud explicita del usuario via dashboard.

### 3.2 Pasos

1. Operador ingresa al dashboard como admin (o tinker).
2. Para un usuario:
   ```bash
   php artisan tinker
   >>> app(\App\Services\SyncAuthService::class)->revokeAllForUser($userId);
   ```
3. Para todos los usuarios (incidente global):
   ```bash
   php artisan tinker
   >>> \App\Models\SyncSession::query()->update(['revoked_at' => now()]);
   ```
4. Forzar cleanup inmediato:
   ```bash
   php artisan sync:cleanup-expired
   ```
5. Notificar a usuarios via canal externo (email).
6. Las extensiones detectan `token_expired` y disparan re-pairing.

### 3.3 Post-mortem

- Documentar en `docs/adr/` si la causa raiz requiere cambio de
  contrato.
- Actualizar `CHANGELOG.md` con advisory.

---

## 4. Runbook: backup y restore PostgreSQL

### 4.1 Backup diario

```bash
# Asume contenedor docker compose service "postgres"
docker compose exec postgres pg_dump -U midori_sync midori_sync \
  | gzip > backups/midori-sync-$(date +%F).sql.gz
```

Retencion: 30 dias rolling.

### 4.2 Restore

```bash
gunzip -c backups/midori-sync-2026-05-06.sql.gz \
  | docker compose exec -T postgres psql -U midori_sync midori_sync
```

Tras restore:

```bash
php artisan migrate                    # idempotente
php artisan sync:recalculate-usage     # recompone quotas
```

### 4.3 Test de restore

Trimestral. Restore en entorno staging y correr `composer test
--testsuite=Feature`. Documentar resultado.

---

## 5. Runbook: cleanup de datos huerfanos

### 5.1 Tokens expirados

- Comando programado: `sync:cleanup-expired` (horario).
- Manual:
  ```bash
  php artisan sync:cleanup-expired
  ```

### 5.2 Records tombstone vencidos

- Tombstones >90 dias se purgan via tarea programada (TODO: agregar
  comando si no existe).

### 5.3 Recalculo de uso

```bash
php artisan sync:recalculate-usage
```

Usar tras restore, migracion grande o si las cifras de cuota divergen.

---

## 6. Runbook: monitoreo de salud

### 6.1 Endpoints / canales

- `GET /up` (Laravel default) — 200 OK indica app viva.
- Logs estructurados en `storage/logs/laravel.log` (TODO: pasar a
  stdout en produccion para que Docker / journald los capture).
- Redis: `redis-cli ping`.
- PostgreSQL: `pg_isready`.

### 6.2 Alertas recomendadas

| Alerta                                         | Umbral                  | Severidad |
|------------------------------------------------|-------------------------|-----------|
| Tasa de 5xx en `/api/*`                        | >1% sobre 5 min         | High      |
| Latencia p95 `/collections/*` write            | >1s sobre 10 min        | High      |
| Rate limit hits >X% del trafico                | >5% sobre 15 min        | Medium    |
| Token validation failures spike                | x10 sobre baseline 24h  | High      |
| Disk free PostgreSQL                           | <15%                    | High      |
| Redis memoria                                  | >80% maxmemory          | Medium    |
| Backups diarios fallando                       | 1 fallo                 | Critical  |

### 6.3 On-call

- Rotacion semanal documentada en el sistema interno del operador.
- Runbook primero, escalar a maintainer si excede 1h sin progreso.

---

## 7. Runbook: deploy

Ver [deployment.md](deployment.md) para detalles de imagen Docker y
configuracion.

### 7.1 Pre-deploy checklist

- [ ] CI verde en `main`.
- [ ] `composer audit` y `npm audit` sin criticos.
- [ ] `CHANGELOG.md` actualizado.
- [ ] Migraciones revisadas (no destructivas o con plan documentado).

### 7.2 Deploy

```bash
docker compose pull
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

### 7.3 Rollback

- Volver al tag anterior del image registry.
- Si hubo migracion no reversible, restaurar backup + replay del WAL
  hasta antes del deploy.

---

## 8. Runbook: incidente de cuota

### 8.1 Sintoma

Usuario reporta 403 con `quota_exceeded` pese a haber borrado datos.

### 8.2 Diagnostico

```bash
php artisan tinker
>>> \App\Models\User::find($id)->records()->where('deleted', false)->sum('size_bytes')
>>> \App\Models\User::find($id)->storage_used
```

Si divergen, recalcular:

```bash
php artisan sync:recalculate-usage --user=$id
```

### 8.3 Mitigacion temporal

Elevar quota del usuario via dashboard o comando, documentar en
ticket.

---

## 9. Runbook: rotacion de credenciales (operador)

### 9.1 `APP_KEY` Laravel

NO rotar sin plan: invalida sesiones cifradas y cookies. Si necesario:

1. Sacar trafico via maintenance mode (`php artisan down`).
2. `php artisan key:generate`.
3. Invalidar sesiones (`SyncSession`s no afectadas, son tokens
   independientes).
4. `php artisan up`.

### 9.2 Credenciales DB / Redis

1. Crear nuevo usuario con permisos identicos.
2. Update `.env`.
3. Rolling restart de app.
4. Drop usuario antiguo.

### 9.3 Authentik client secret

1. Rotar en Authentik.
2. Update `.env` (`AUTHENTIK_CLIENT_SECRET`).
3. Rolling restart. Logins existentes siguen validos via cookie de
   sesion; nuevos OAuth flows usan el secret nuevo.

---

## 10. Indice de comandos

```bash
# Salud
php artisan about
php artisan migrate:status

# Cleanup
php artisan sync:cleanup-expired
php artisan sync:recalculate-usage

# Tests
composer test
npm test

# Tinker (admin ad-hoc)
php artisan tinker
```
