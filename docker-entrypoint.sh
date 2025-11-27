#!/bin/bash

# Blueprint initialization script for Pterodactyl Panel
# Run this ONCE after the container first starts:
#   docker exec -it Pterodactyl-Panel /blueprint-init.sh

BLUEPRINT_INITIALIZED="/app/.blueprint/.initialized"

echo "=================================="
echo "  Blueprint Initialization Script"
echo "=================================="

# Check if already initialized
if [ -f "$BLUEPRINT_INITIALIZED" ]; then
    echo "[Blueprint] Already initialized!"
    echo "[Blueprint] To re-initialize, run: rm $BLUEPRINT_INITIALIZED"
    exit 0
fi

cd /app

# Create required directories
echo "[Blueprint] Creating directories..."
mkdir -p /app/.blueprint/extensions/blueprint/private/debug
mkdir -p /app/.blueprint/extensions/blueprint/private/db
mkdir -p /app/.blueprint/extensions/blueprint/public
mkdir -p /app/.blueprint/data
touch /app/.blueprint/extensions/blueprint/private/debug/logs.txt
touch /app/.blueprint/extensions/blueprint/private/db/database
touch /app/.blueprint/extensions/blueprint/private/db/installed_extensions
touch /app/.blueprint/extensions/blueprint/public/index.html
echo '<?php return [];' > /app/.blueprint/extensions/blueprint/private/extensionfs.php
echo '{}' > /app/.blueprint/data/settings.json

# Fix ownership
chown -R nginx:nginx /app/.blueprint

# Run Blueprint setup
echo "[Blueprint] Running blueprint.sh..."
if [ -f "/app/blueprint.sh" ]; then
    bash /app/blueprint.sh
fi

# Mark as initialized
touch "$BLUEPRINT_INITIALIZED"
chown nginx:nginx "$BLUEPRINT_INITIALIZED"

echo ""
echo "[Blueprint] Initialization complete!"
echo "[Blueprint] You can now use: blueprint -i /srv/pterodactyl/extensions/your-extension.blueprint"
