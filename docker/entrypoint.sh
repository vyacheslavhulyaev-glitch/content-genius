#!/bin/sh
set -eu
umask 0007

cd /var/www/html
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ "${1:-}" = "apache2-foreground" ]; then
    : "${APP_KEY:?APP_KEY must be provided at runtime}"
    php artisan package:discover --no-interaction
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
fi

# Migrations run explicitly as a deployment step, never on an HTTP container restart.
exec docker-php-entrypoint "$@"
