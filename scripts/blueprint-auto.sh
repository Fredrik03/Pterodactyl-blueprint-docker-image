#!/bin/bash
set -euo pipefail

LOG_TAG="[BlueprintAuto]"
INIT_FLAG="/app/var/.blueprint_initialized"
LOG_FILE="/var/log/supervisord/blueprint-auto.log"

log() {
    echo "${LOG_TAG} $1"
}

touch "$LOG_FILE"

if [ -f "$INIT_FLAG" ]; then
    log "Initialization flag found at ${INIT_FLAG}, skipping."
    exit 0
fi

DB_HOST_RAW="${DB_HOST:-database}"
DB_PORT_RAW="${DB_PORT:-}"

# Allow DB_HOST to be in host:port format (common on Unraid templates)
if [[ "$DB_HOST_RAW" == *:* ]]; then
    DB_HOST="${DB_HOST_RAW%:*}"
    HOST_PORT="${DB_HOST_RAW##*:}"
    if [ -z "$DB_PORT_RAW" ] || [ "$DB_PORT_RAW" = "3306" ]; then
        DB_PORT="$HOST_PORT"
    else
        DB_PORT="$DB_PORT_RAW"
    fi
else
    DB_HOST="$DB_HOST_RAW"
    DB_PORT="${DB_PORT_RAW:-3306}"
fi

DB_ATTEMPTS="${BLUEPRINT_DB_ATTEMPTS:-60}"

log "Waiting for database ${DB_HOST}:${DB_PORT} (max ${DB_ATTEMPTS} attempts)..."
COUNTER=0
until nc -z "${DB_HOST}" "${DB_PORT}" >/dev/null 2>&1 || [ "${COUNTER}" -ge "${DB_ATTEMPTS}" ]; do
    COUNTER=$((COUNTER + 1))
    sleep 2
done

if [ "${COUNTER}" -ge "${DB_ATTEMPTS}" ]; then
    log "Database not reachable after waiting, continuing anyway..."
else
    log "Database is reachable, continuing with Blueprint setup."
fi

cd /app
log "Running blueprint.sh ..."
if bash blueprint.sh >> "${LOG_FILE}" 2>&1; then
    log "Blueprint initialization completed."
else
    log "Blueprint initialization finished with warnings, check ${LOG_FILE}."
fi

touch "${INIT_FLAG}"
chown nginx:nginx "${INIT_FLAG}" >/dev/null 2>&1 || true

log "Initialization flag written to ${INIT_FLAG}"

