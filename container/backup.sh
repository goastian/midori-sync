#!/usr/bin/env bash
# Midori Sync — backup diario: la base "midori_sync" en arca (Postgres
# compartido) siempre, storage/app/{public,private} solo si tiene archivos
# reales de usuario (hoy no es el caso: todo vive en PostgreSQL).
# storage/logs y storage/framework quedan siempre fuera: son logs/caché
# regenerable, no datos restaurables de usuario.
#
# Corre en el host como el usuario rootless de los Quadlet (nunca root).
set -Eeuo pipefail
umask 077

log() { printf '[backup] %s\n' "$*"; }
warn() { printf '[backup][warn] %s\n' "$*" >&2; }
die() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

DB_CONTAINER="arca"
DB_IMAGE="docker.io/postgres:18-alpine"
DB_NAME="midori_sync"
STORAGE_PATH="${HOME}/volume/midori-sync/midori-storage"
BACKUP_DIR="${HOME}/backups/midori-sync"
RETENTION_DAYS=30

install -d -m 700 "${BACKUP_DIR}"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
failed=0

log_line() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >>"${BACKUP_DIR}/backup.log"; }
file_size() { stat -c%s "$1" 2>/dev/null || wc -c <"$1"; }

## ============== ##
##    Database    ##
## ============== ##
db_partial="${BACKUP_DIR}/db-${timestamp}.dump.part"
db_final="${BACKUP_DIR}/db-${timestamp}.dump"

if podman exec -u postgres "${DB_CONTAINER}" pg_dump -Fc -d "${DB_NAME}" >"${db_partial}" 2>/dev/null \
    && [ -s "${db_partial}" ]; then

    # Verificación de integridad con un contenedor postgres efímero (misma
    # imagen), sin tocar la base de producción.
    if podman run --rm -v "${BACKUP_DIR}:/backup:ro,Z" "${DB_IMAGE}" \
        pg_restore --list "/backup/$(basename "${db_partial}")" >/dev/null 2>&1; then
        mv "${db_partial}" "${db_final}"
        chmod 600 "${db_final}"
        checksum="$(sha256sum "${db_final}" | cut -d' ' -f1)"
        log_line "db backup=$(basename "${db_final}") size=$(file_size "${db_final}")B sha256=${checksum}"
        log "Dump aceptado: $(basename "${db_final}")"
    else
        warn "El dump no superó pg_restore --list."
        rm -f "${db_partial}"
        log_line "db backup FAILED (integrity check)"
        failed=1
    fi
else
    warn "pg_dump falló; revisa que ${DB_CONTAINER} esté arriba."
    rm -f "${db_partial}"
    log_line "db backup FAILED (pg_dump)"
    failed=1
fi

## ========================================= ##
##    storage/ (condicional, sin logs/caché)  ##
## ========================================= ##
has_user_files=0
if find "${STORAGE_PATH}/app/public" "${STORAGE_PATH}/app/private" \
    -mindepth 1 -type f ! -name '.gitignore' ! -name '.gitkeep' 2>/dev/null | grep -q .; then
    has_user_files=1
fi

if [ "${has_user_files}" = "1" ]; then
    storage_partial="${BACKUP_DIR}/storage-${timestamp}.tar.gz.part"
    storage_final="${BACKUP_DIR}/storage-${timestamp}.tar.gz"

    if tar --sort=name -C "${STORAGE_PATH}/app" -cf - public private 2>/dev/null | gzip >"${storage_partial}" \
        && gzip -t "${storage_partial}" 2>/dev/null && tar -tzf "${storage_partial}" >/dev/null 2>&1; then
        mv "${storage_partial}" "${storage_final}"
        chmod 600 "${storage_final}"
        checksum="$(sha256sum "${storage_final}" | cut -d' ' -f1)"
        log_line "storage backup=$(basename "${storage_final}") size=$(file_size "${storage_final}")B sha256=${checksum}"
        log "Archivo de storage/ aceptado: $(basename "${storage_final}")"
    else
        warn "El archivo de storage/ no superó la verificación de integridad."
        rm -f "${storage_partial}"
        log_line "storage backup FAILED (integrity check)"
        failed=1
    fi
else
    log "Sin archivos de usuario en storage/; solo se respalda la base de datos."
    log_line "storage backup skipped (no user files)"
fi

## ============== ##
##    Rotación    ##
## ============== ##
if [ "${failed}" = "0" ]; then
    find "${BACKUP_DIR}" -maxdepth 1 -type f \
        \( -name 'db-*.dump' -o -name 'storage-*.tar.gz' \) \
        -mtime "+${RETENTION_DAYS}" -print -delete 2>/dev/null | while IFS= read -r removed; do
        log_line "rotated $(basename "${removed}")"
    done
else
    warn "Backup de hoy con fallos; no se rota ningún backup anterior."
    die "Backup incompleto; revisa ${BACKUP_DIR}/backup.log"
fi

log "Backup completado en ${BACKUP_DIR}."
