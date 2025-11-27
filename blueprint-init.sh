#!/bin/bash

# Blueprint auto-initialization script
# This runs automatically via supervisord on container start

BLUEPRINT_INITIALIZED="/app/.blueprint/.initialized"
LOGFILE="/app/storage/logs/blueprint-init.log"

log() {
    echo "[Blueprint] $1" | tee -a "$LOGFILE"
}

# Wait for database to be ready
wait_for_db() {
    log "Waiting for database..."
    for i in {1..30}; do
        if php /app/artisan tinker --execute="DB::connection()->getPdo();" 2>/dev/null; then
            log "Database is ready!"
            return 0
        fi
        sleep 2
    done
    log "Database not ready after 60 seconds, continuing anyway..."
    return 1
}

# Main initialization
if [ -f "$BLUEPRINT_INITIALIZED" ]; then
    log "Already initialized, skipping."
    exit 0
fi

log "First run detected, initializing Blueprint..."

cd /app

# Wait for database
wait_for_db

# Create required directories
mkdir -p /app/.blueprint/extensions/blueprint/private/debug
mkdir -p /app/.blueprint/extensions/blueprint/private/db
mkdir -p /app/.blueprint/extensions/blueprint/public
mkdir -p /app/.blueprint/data
touch /app/.blueprint/extensions/blueprint/private/debug/logs.txt
touch /app/.blueprint/extensions/blueprint/private/db/database
touch /app/.blueprint/extensions/blueprint/private/db/installed_extensions
touch /app/.blueprint/extensions/blueprint/public/index.html
echo '<?php return [];' > /app/.blueprint/extensions/blueprint/private/extensionfs.php
echo '{}' > /app/.blueprint/data/settings.json 2>/dev/null || true

# Fix ownership
chown -R nginx:nginx /app/.blueprint 2>/dev/null || true

# Run Blueprint setup
log "Running blueprint.sh..."
bash /app/blueprint.sh 2>&1 | tee -a "$LOGFILE" || true

# Mark as initialized
touch "$BLUEPRINT_INITIALIZED"
chown nginx:nginx "$BLUEPRINT_INITIALIZED" 2>/dev/null || true

log "Initialization complete!"

