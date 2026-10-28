#!/bin/sh
set -eu

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY debe estar configurado (--env-file) antes de arrancar el stack." >&2
    exit 1
fi

php artisan package:discover --ansi
php artisan migrate --force
php artisan storage:link || test -L public/storage
php artisan config:cache
php artisan event:cache
php artisan view:cache

chown -R nginx:nginx bootstrap/cache storage
