#!/bin/sh
set -eu

cd /var/www/html

# Colas: mismo orden que "composer run dev" (ver composer.json). Varios
# consumidores son seguros; lo importante es que al menos uno esté siempre
# arriba para que ninguna cola se quede atascada.
exec php artisan queue:work \
    --queue=default,fetch,snapshots \
    --sleep=3 \
    --tries=3 \
    --timeout=90 \
    --max-time=3600
