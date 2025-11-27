#!/bin/bash

# Wrapper entrypoint that ensures Blueprint files exist before panel starts
# This is needed because mounting /app/.blueprint as a volume hides the files
# created during docker build

echo "[Blueprint] Checking Blueprint files..."

# Create required Blueprint files if they don't exist (empty volume mount)
if [ ! -f "/app/.blueprint/extensions/blueprint/private/extensionfs.php" ]; then
    echo "[Blueprint] Creating Blueprint directory structure..."
    mkdir -p /app/.blueprint/extensions/blueprint/private/db
    mkdir -p /app/.blueprint/extensions/blueprint/private/debug
    mkdir -p /app/.blueprint/extensions/blueprint/public
    mkdir -p /app/.blueprint/data
    
    echo '<?php return [];' > /app/.blueprint/extensions/blueprint/private/extensionfs.php
    touch /app/.blueprint/extensions/blueprint/private/db/installed_extensions
    touch /app/.blueprint/extensions/blueprint/private/db/database
    touch /app/.blueprint/extensions/blueprint/private/debug/logs.txt
    touch /app/.blueprint/extensions/blueprint/public/index.html
    echo '{}' > /app/.blueprint/data/settings.json
    
    chown -R nginx:nginx /app/.blueprint
    echo "[Blueprint] Directory structure created!"
else
    echo "[Blueprint] Files already exist, skipping."
fi

# Find and execute the original Pterodactyl entrypoint
# The official image uses /entrypoint.sh
if [ -f "/entrypoint.sh" ] && [ "$0" != "/entrypoint.sh" ]; then
    echo "[Blueprint] Executing original entrypoint..."
    exec /entrypoint.sh "$@"
fi

# If no specific entrypoint, just exec the command passed to us
if [ $# -gt 0 ]; then
    exec "$@"
fi

# Fallback: start supervisord directly
echo "[Blueprint] Starting supervisord..."
exec supervisord -n -c /etc/supervisord.conf
