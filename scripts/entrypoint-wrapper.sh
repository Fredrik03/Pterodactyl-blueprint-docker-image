#!/bin/ash
set -e

# Normalize DB_HOST/DB_PORT so Unraid inputs like "192.168.0.115:3306"
# continue to work with the upstream entrypoint script.
DB_HOST_RAW="${DB_HOST:-database}"
DB_PORT_RAW="${DB_PORT:-}"

if echo "$DB_HOST_RAW" | grep -q ":"; then
    DB_HOST_SANE="${DB_HOST_RAW%:*}"
    DB_HOST_PORT="${DB_HOST_RAW##*:}"

    if [ -z "$DB_PORT_RAW" ] || [ "$DB_PORT_RAW" = "3306" ]; then
        export DB_PORT="$DB_HOST_PORT"
    else
        export DB_PORT="$DB_PORT_RAW"
    fi
    export DB_HOST="$DB_HOST_SANE"
else
    export DB_HOST="$DB_HOST_RAW"
    export DB_PORT="${DB_PORT_RAW:-3306}"
fi

echo "[Wrapper] Starting upstream entrypoint with: $@"
exec /bin/ash /app/.github/docker/entrypoint.sh "$@"


