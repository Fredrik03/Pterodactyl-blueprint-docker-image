#!/bin/ash

# Blueprint wrapper entrypoint
# Creates Blueprint files BEFORE the original Pterodactyl entrypoint runs
# This is needed because volume mounts hide files created during docker build

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
    
    chown -R nginx:nginx /app/.blueprint 2>/dev/null || true
    echo "[Blueprint] Directory structure created!"
else
    echo "[Blueprint] Files already exist."
fi

# Execute the original Pterodactyl entrypoint
echo "[Blueprint] Starting Pterodactyl Panel..."
exec /original-entrypoint.sh "$@"
