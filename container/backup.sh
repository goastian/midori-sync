#!/usr/bin/env bash
# Midori Sync — backup diario: la base "midori_sync" en arca (Postgres
# compartido) siempre, storage/app/{public,private} solo si tiene archivos
# reales de usuario (hoy no es el caso: todo vive en PostgreSQL).
# storage/logs y storage/framework quedan siempre fuera: son logs/caché
# regenerable, no datos restaurables de usuario.
#
# Los artefactos aceptados se cifran en reposo (AES-256-CBC, clave propia
# separada de APP_KEY y del propio directorio de backups) antes de dejarlos
# en disco; solo queda el ".enc".
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
BACKUP_KEY_FILE="${XDG_CONFIG_HOME:-${HOME}/.config}/midori-sync/backup.key"
RETENTION_DAYS=30

install -d -m 700 "${BACKUP_DIR}"
install -d -m 700 "$(dirname "${BACKUP_KEY_FILE}")"

# Clave de cifrado de los backups: separada de APP_KEY y del propio
# directorio de backups. Si alguien copia o exfiltra solo $BACKUP_DIR, los
# ".enc" no sirven de nada sin esta clave. Se genera una sola vez.
if [ ! -s "${BACKUP_KEY_FILE}" ]; then
    openssl rand -base64 32 >"${BACKUP_KEY_FILE}"
    chmod 600 "${BACKUP_KEY_FILE}"
    log "Clave de cifrado de backups generada en ${BACKUP_KEY_FILE} (guárdala; sin ella los .enc no se pueden restaurar)."
fi

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
failed=0

log_line() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >>"${BACKUP_DIR}/backup.log"; }
file_size() { stat -c%s "$1" 2>/dev/null || wc -c <"$1"; }

# Cifra "$1" con BACKUP_KEY_FILE (AES-256-CBC) y borra el plano. Imprime la
# ruta del ".enc" resultante por stdout; devuelve distinto de cero si el
# cifrado falla o produce un archivo vacío (no deja basura a medias).
encrypt_artifact() {
    local plain="$1" enc="$1.enc"
    # AES-256-CBC, no GCM: "openssl enc" no soporta cifrados AEAD (falla
    # con "AEAD ciphers not supported" incluso en OpenSSL 3.5). No hace
    # falta autenticación aquí: el plano ya se verificó íntegro antes de
    # llegar a esta función (pg_restore --list / tar -tzf); el cifrado es
    # solo para confidencialidad en reposo.
    if openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt \
        -pass "file:${BACKUP_KEY_FILE}" -in "${plain}" -out "${enc}" 2>/dev/null \
        && [ -s "${enc}" ]; then
        rm -f "${plain}"
        chmod 600 "${enc}"
        printf '%s' "${enc}"
        return 0
    fi
    rm -f "${enc}"
    return 1
}

## ============== ##
##    Database    ##
## ============== ##
db_partial="${BACKUP_DIR}/db-${timestamp}.dump.part"
db_plain="${BACKUP_DIR}/db-${timestamp}.dump"

if podman exec -u postgres "${DB_CONTAINER}" pg_dump -Fc -d "${DB_NAME}" >"${db_partial}" 2>/dev/null \
    && [ -s "${db_partial}" ]; then

    # Verificación de integridad con un contenedor postgres efímero (misma
    # imagen), sin tocar la base de producción.
    if podman run --rm -v "${BACKUP_DIR}:/backup:ro,Z" "${DB_IMAGE}" \
        pg_restore --list "/backup/$(basename "${db_partial}")" >/dev/null 2>&1; then
        mv "${db_partial}" "${db_plain}"
        if db_final="$(encrypt_artifact "${db_plain}")"; then
            checksum="$(sha256sum "${db_final}" | cut -d' ' -f1)"
            log_line "db backup=$(basename "${db_final}") size=$(file_size "${db_final}")B sha256=${checksum}"
            log "Dump aceptado y cifrado: $(basename "${db_final}")"
        else
            warn "No se pudo cifrar el dump."
            log_line "db backup FAILED (encryption)"
            failed=1
        fi
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
    storage_plain="${BACKUP_DIR}/storage-${timestamp}.tar.gz"

    if tar --sort=name -C "${STORAGE_PATH}/app" -cf - public private 2>/dev/null | gzip >"${storage_partial}" \
        && gzip -t "${storage_partial}" 2>/dev/null && tar -tzf "${storage_partial}" >/dev/null 2>&1; then
        mv "${storage_partial}" "${storage_plain}"
        if storage_final="$(encrypt_artifact "${storage_plain}")"; then
            checksum="$(sha256sum "${storage_final}" | cut -d' ' -f1)"
            log_line "storage backup=$(basename "${storage_final}") size=$(file_size "${storage_final}")B sha256=${checksum}"
            log "Archivo de storage/ aceptado y cifrado: $(basename "${storage_final}")"
        else
            warn "No se pudo cifrar el archivo de storage/."
            log_line "storage backup FAILED (encryption)"
            failed=1
        fi
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
        \( -name 'db-*.dump.enc' -o -name 'storage-*.tar.gz.enc' \) \
        -mtime "+${RETENTION_DAYS}" -print -delete 2>/dev/null | while IFS= read -r removed; do
        log_line "rotated $(basename "${removed}")"
    done
else
    warn "Backup de hoy con fallos; no se rota ningún backup anterior."
    die "Backup incompleto; revisa ${BACKUP_DIR}/backup.log"
fi

log "Backup completado en ${BACKUP_DIR}."
