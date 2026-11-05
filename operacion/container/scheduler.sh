#!/bin/sh
set -eu
trap 'exit 0' TERM INT
while :; do
    php artisan schedule:run --no-interaction
    php artisan cchia:ops heartbeat --json >/dev/null
    sleep 60 &
    wait "$!"
done
