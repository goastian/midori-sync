#!/bin/sh
set -eu

cd /var/www/html

mkdir -p \
    bootstrap/cache \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs

if [ "$(id -u)" = "0" ]; then
    chown -R nginx:nginx bootstrap/cache storage
fi

if [ ! -e public/storage ]; then
    ln -s ../storage/app/public public/storage
fi

# En despliegues de un solo Pod (podman run / quadlet) se inicializa la
# aplicación antes de que nginx y PHP-FPM acepten tráfico.
if [ "${1:-}" = "/usr/bin/supervisord" ]; then
    /usr/local/bin/midori-init
fi

exec "$@"
