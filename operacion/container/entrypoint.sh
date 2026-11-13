#!/bin/sh
set -eu
umask 0027
mkdir -p /app/storage/app/private /app/storage/framework/cache/data /app/storage/framework/views /app/storage/framework/sessions /app/storage/logs /app/bootstrap/cache
if [ "$(id -u)" = 0 ]; then
    chown -R www-data:www-data /app/storage /app/bootstrap/cache
    if [ "${1:-}" != php-fpm ]; then
        exec gosu www-data docker-php-entrypoint "$@"
    fi
fi
exec docker-php-entrypoint "$@"
