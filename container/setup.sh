#!/usr/bin/env bash
# Midori Sync — genera las unidades Quadlet de un Pod propio ("midori-sync"):
# la app (nginx + php-fpm + supervisor) y Redis dedicado. La base de datos
# vive en "arca" (Postgres compartido ya desplegado en el server); este
# script solo crea ahí el rol/base "midori"/"midori_sync".
#
# La red *-psql hacia arca NO la gestiona este script: deja el ancla
# "# Network" en el .pod para que ~/services/shared/shared.sh la complete
# (red + IP asignadas allí), y preserva esa línea si se vuelve a ejecutar.
#
#   ~/services/shared/shared.sh   (conectar el Pod a arca)
#   systemctl --user daemon-reload
#   systemctl --user start midori-sync.service
set -Eeuo pipefail

log() { printf '[setup] %s\n' "$*"; }
die() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

rand_hex() { openssl rand -hex 32; }
rand_app_key() { printf 'base64:%s' "$(openssl rand -base64 32)"; }

SERVICE_NAME="midori-sync"
IMAGE_NAME="localhost/${SERVICE_NAME}:latest"
REDIS_IMAGE="docker.io/redis:alpine"

DB_CONTAINER="arca"
ARCA_SERVICE="arca.service"
DB_NAME="midori_sync"
DB_USER="midori"

CONTAINER_DIR="$(readlink -f "$(dirname "$0")")"
PROJECT_DIR="$(readlink -f "${CONTAINER_DIR}/..")"

QUADLET_PATH="${XDG_CONFIG_HOME:-${HOME}/.config}/containers/systemd/${SERVICE_NAME}"
VOLUMES_PATH="${HOME}/volume/${SERVICE_NAME}"
ENV_FILE="${CONTAINER_DIR}/midori-sync.env"

PUBLISH_ADDR="127.0.0.1"
PUBLISH_PORT="2004"
CONTAINER_PORT="8080"

STORAGE_VOLUME="midori-storage"
REDIS_VOLUME="midori-redis-data"

install -d -m 700 "${QUADLET_PATH}"

## ================= ##
##    Volúmenes      ##
## ================= ##

# midori-storage lo escriben en runtime los procesos que corren como
# "nginx" (uid/gid 100/101): se crea con "podman unshare" para que el
# propietario sea correcto dentro del namespace de usuario rootless. Solo
# la primera vez: el contenedor pasa a ser dueño después, y reintentar el
# chmod/chown en reejecuciones fallaría con "Operation not permitted".
[ -d "${VOLUMES_PATH}/${STORAGE_VOLUME}" ] ||
    podman unshare install -d -m 700 -o 100 -g 101 "${VOLUMES_PATH}/${STORAGE_VOLUME}"
cat >"${QUADLET_PATH}/${STORAGE_VOLUME}.volume" <<EOF
[Volume]
VolumeName=${STORAGE_VOLUME}
Type=none
Device=${VOLUMES_PATH}/${STORAGE_VOLUME}
Options=bind,rw
Copy=false
EOF

# Mismo motivo: el entrypoint de redis corre como root, se queda como
# dueño de /data tras bajar privilegios a su usuario interno.
[ -d "${VOLUMES_PATH}/${REDIS_VOLUME}" ] ||
    install -d -m 700 "${VOLUMES_PATH}/${REDIS_VOLUME}"
cat >"${QUADLET_PATH}/${REDIS_VOLUME}.volume" <<EOF
[Volume]
VolumeName=${REDIS_VOLUME}
Type=none
Device=${VOLUMES_PATH}/${REDIS_VOLUME}
Options=bind,rw
Copy=false
EOF

## ============= ##
##    Network    ##
## ============= ##
cat >"${QUADLET_PATH}/${SERVICE_NAME}.network" <<EOF
[Network]
NetworkName=${SERVICE_NAME}-net
Driver=bridge
EOF

## ========= ##
##    Pod    ##
## ========= ##
# Conserva las conexiones de red ya insertadas por shared.sh al regenerar
# el .pod; si no, volver a ejecutar setup.sh dejaría al Pod sin acceso a
# arca.
net_lines=()
old_pod="${QUADLET_PATH}/${SERVICE_NAME}.pod"
if [ -f "${old_pod}" ]; then
    in_pod=0
    while IFS= read -r l; do
        case "${l}" in
        '['*']') [ "${l}" = "[Pod]" ] && in_pod=1 || in_pod=0 ;;
        "Network=${SERVICE_NAME}.network") ;; # ya la escribe este script, no duplicar
        Network=*) [ "${in_pod}" = "1" ] && net_lines+=("${l}") ;;
        esac
    done <"${old_pod}"
fi

cat >"${QUADLET_PATH}/${SERVICE_NAME}.pod" <<EOF
[Unit]
Wants=${ARCA_SERVICE}
After=${ARCA_SERVICE}

[Pod]
PodName=${SERVICE_NAME}
ServiceName=${SERVICE_NAME}
Network=${SERVICE_NAME}.network
# Network
ExitPolicy=stop
PublishPort=${PUBLISH_ADDR}:${PUBLISH_PORT}:${CONTAINER_PORT}
PodmanArgs=--cpus=2 --memory=2g

[Service]
Restart=always
TimeoutStartSec=900

[Install]
WantedBy=default.target
EOF

if ((${#net_lines[@]} > 0)); then
    net_tmp="$(mktemp)"
    printf '%s\n' "${net_lines[@]}" >"${net_tmp}"
    anchor="$(grep -n '^# Network$' "${QUADLET_PATH}/${SERVICE_NAME}.pod" | tail -n1 | cut -d: -f1)"
    sed -i "${anchor}r ${net_tmp}" "${QUADLET_PATH}/${SERVICE_NAME}.pod"
    rm -f "${net_tmp}"
    log "Conservadas ${#net_lines[@]} conexión(es) de red: ${net_lines[*]}"
fi

## ============================= ##
##    Entorno de runtime (app)   ##
## ============================= ##
[ -f "${PROJECT_DIR}/.env" ] || die "Falta ${PROJECT_DIR}/.env (copia .env.example primero)."

# Reutiliza contraseña/clave previas si existen, para que re-ejecutar el
# script no rote credenciales ya en uso.
DB_PASSWORD="$(grep '^DB_PASSWORD=' "${ENV_FILE}" 2>/dev/null | cut -d= -f2- || true)"
[ -n "${DB_PASSWORD}" ] || DB_PASSWORD="$(rand_hex)"
APP_KEY="$(grep '^APP_KEY=' "${ENV_FILE}" 2>/dev/null | cut -d= -f2- || true)"
[ -n "${APP_KEY}" ] || APP_KEY="$(rand_app_key)"

{
    grep -vE '^(APP_ENV|APP_DEBUG|APP_KEY|LOG_CHANNEL|DB_|CACHE_|QUEUE_|SESSION_|REDIS_)' "${PROJECT_DIR}/.env" 2>/dev/null || true
    printf '%s\n' \
        "APP_ENV=production" \
        "APP_DEBUG=false" \
        "APP_KEY=${APP_KEY}" \
        "LOG_CHANNEL=stderr" \
        "DB_CONNECTION=pgsql" \
        "DB_HOST=${DB_CONTAINER}" \
        "DB_PORT=5432" \
        "DB_DATABASE=${DB_NAME}" \
        "DB_USERNAME=${DB_USER}" \
        "DB_PASSWORD=${DB_PASSWORD}" \
        "REDIS_HOST=127.0.0.1" \
        "REDIS_PORT=6379" \
        "CACHE_STORE=redis" \
        "SESSION_DRIVER=redis" \
        "SESSION_SECURE_COOKIE=true" \
        "SESSION_ENCRYPT=true" \
        "QUEUE_CONNECTION=redis"
} >"${ENV_FILE}"

# EnvironmentFile= de Podman no expande ${VAR} como sí lo hace phpdotenv
# (p. ej. AUTHENTIK_REDIRECT_URI=${APP_URL}/auth/callback,
# MAIL_FROM_NAME="${APP_NAME}"); se resuelven aquí de forma literal contra
# los valores ya presentes en el propio archivo generado.
while IFS='=' read -r key value; do
    value="${value#\"}"
    value="${value%\"}"
    [ -n "${value}" ] || continue
    escaped="$(printf '%s' "${value}" | sed 's/[&\\|]/\\&/g')"
    sed -i "s|\${${key}}|${escaped}|g" "${ENV_FILE}"
done < <(grep -E '^[A-Za-z_][A-Za-z0-9_]*=' "${ENV_FILE}")

chmod 600 "${ENV_FILE}"
log "Entorno generado en ${ENV_FILE} (0600)."

## ========================= ##
##    Base de datos (arca)   ##
## ========================= ##
podman container exists "${DB_CONTAINER}" ||
    die "El contenedor ${DB_CONTAINER} no es visible; arráncalo antes de ejecutar setup.sh."

podman exec -i -u postgres "${DB_CONTAINER}" psql --no-psqlrc -v ON_ERROR_STOP=1 \
    -c "CREATE ROLE \"${DB_USER}\" LOGIN" >/dev/null 2>&1 || true

db_password_esc="${DB_PASSWORD//\'/\'\'}"
podman exec -i -u postgres "${DB_CONTAINER}" psql --no-psqlrc -v ON_ERROR_STOP=1 \
    -c "ALTER ROLE \"${DB_USER}\" WITH LOGIN PASSWORD '${db_password_esc}'" >/dev/null ||
    die "No se pudo fijar la contraseña del rol en ${DB_CONTAINER}."

podman exec -i -u postgres "${DB_CONTAINER}" psql --no-psqlrc -v ON_ERROR_STOP=1 \
    -c "CREATE DATABASE \"${DB_NAME}\" OWNER \"${DB_USER}\"" >/dev/null 2>&1 || true

log "Rol '${DB_USER}' y base '${DB_NAME}' asegurados en ${DB_CONTAINER}."

## ================= ##
##    Imagen         ##
## ================= ##
log "Construyendo ${IMAGE_NAME}..."
podman build --pull=newer -t "${IMAGE_NAME}" \
    -f "${CONTAINER_DIR}/containerfile" \
    --ignorefile "${CONTAINER_DIR}/.containerignore" \
    "${PROJECT_DIR}"

## ===================== ##
##    Container: Redis   ##
## ===================== ##
cat >"${QUADLET_PATH}/${SERVICE_NAME}-redis.container" <<EOF
[Container]
Pod=${SERVICE_NAME}.pod
ContainerName=${SERVICE_NAME}-redis
Image=${REDIS_IMAGE}
Exec=redis-server --appendonly yes
Volume=${REDIS_VOLUME}.volume:/data:Z
AutoUpdate=registry
Pull=newer

CgroupsMode=no-conmon
Memory=256m
PidsLimit=128
PodmanArgs=--cpus=0.5

HealthCmd=redis-cli ping
HealthInterval=10s
HealthRetries=5
HealthStartPeriod=15s

[Service]
Restart=on-failure
RestartSec=5s
TimeoutStartSec=900
EOF

## ======================================= ##
##    Container: App (endurecida, Pod)     ##
## ======================================= ##
cat >"${QUADLET_PATH}/${SERVICE_NAME}-app.container" <<EOF
[Unit]
Wants=${SERVICE_NAME}-redis.service ${ARCA_SERVICE}
After=${SERVICE_NAME}-redis.service ${ARCA_SERVICE}

[Container]
Pod=${SERVICE_NAME}.pod
ContainerName=${SERVICE_NAME}
Image=${IMAGE_NAME}
EnvironmentFile=${ENV_FILE}
AutoUpdate=local
Pull=newer

Volume=${STORAGE_VOLUME}.volume:/var/www/html/storage:Z

ReadOnly=true
Tmpfs=/tmp:rw,nosuid,nodev,size=64m
Tmpfs=/run:rw,nosuid,nodev,mode=0755,size=16m
Tmpfs=/var/lib/php/sessions:rw,noexec,nosuid,size=8m
Tmpfs=/var/lib/nginx:rw,nosuid,nodev,size=8m
Tmpfs=/var/log/nginx:rw,nosuid,nodev,size=4m
Tmpfs=/var/www/html/bootstrap/cache:rw,nosuid,nodev,size=32m

DropCapability=all
AddCapability=CHOWN,DAC_OVERRIDE,FOWNER,FSETID,SETGID,SETUID
NoNewPrivileges=true

CgroupsMode=no-conmon
Memory=1536m
PidsLimit=512
PodmanArgs=--cpus=1.5

HealthCmd=curl -fsS http://127.0.0.1:${CONTAINER_PORT}/up
HealthInterval=30s
HealthRetries=5
HealthStartPeriod=60s
Notify=healthy

[Service]
Restart=always
RestartSec=5s
TimeoutStartSec=900

[Install]
WantedBy=default.target
EOF

## ================================= ##
##    Backups periódicos (timer)     ##
## ================================= ##
SYSTEMD_USER_PATH="${XDG_CONFIG_HOME:-${HOME}/.config}/systemd/user"
install -d -m 700 "${SYSTEMD_USER_PATH}"
sed "s|__BACKUP_SCRIPT__|${CONTAINER_DIR}/backup.sh|g" \
    "${CONTAINER_DIR}/systemd/${SERVICE_NAME}-backup.service" \
    >"${SYSTEMD_USER_PATH}/${SERVICE_NAME}-backup.service"
install -m 644 "${CONTAINER_DIR}/systemd/${SERVICE_NAME}-backup.timer" \
    "${SYSTEMD_USER_PATH}/${SERVICE_NAME}-backup.timer"
systemctl --user daemon-reload
systemctl --user enable --now "${SERVICE_NAME}-backup.timer"

log "Quadlet generado en ${QUADLET_PATH}"
printf '\nFalta conectar el Pod a arca (ejecutar como el mismo usuario):\n'
printf '    ~/services/shared/shared.sh\n'
printf '    (RDBMS: arca.container · cliente: %s.pod · red: una *-psql existente o nueva)\n\n' "${SERVICE_NAME}"
printf '    systemctl --user daemon-reload\n'
printf '    systemctl --user start %s.service\n' "${SERVICE_NAME}"
printf '    podman exec %s php artisan migrate:status\n' "${SERVICE_NAME}"
