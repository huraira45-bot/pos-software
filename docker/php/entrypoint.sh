#!/bin/sh
set -e

# storage/ and bootstrap/cache/ are bind-mounted from the host, so a build-time
# chown has no effect - anything root-run (docker compose exec app php artisan ...)
# leaves files owned by root, which php-fpm's www-data worker processes then can't
# write to (e.g. storage/logs/laravel.log silently failing to append). Re-assert
# ownership on every container start so this can't drift back.
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
