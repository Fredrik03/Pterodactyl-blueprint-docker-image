#!/bin/bash

# Blueprint initialization wrapper for Pterodactyl Panel
# This runs before the main panel entrypoint

BLUEPRINT_INITIALIZED="/app/.blueprint/.initialized"

# Check if Blueprint needs initialization
if [ ! -f "$BLUEPRINT_INITIALIZED" ]; then
    echo "[Blueprint] First run detected, initializing Blueprint..."
    
    cd /app
    
    # Run Blueprint setup script
    if [ -f "/app/blueprint.sh" ]; then
        bash /app/blueprint.sh 2>&1 || echo "[Blueprint] Setup completed with warnings (this is normal on first run)"
    fi
    
    # Mark as initialized
    touch "$BLUEPRINT_INITIALIZED"
    
    echo "[Blueprint] Initialization complete!"
else
    echo "[Blueprint] Already initialized, skipping setup."
fi

# Call the original entrypoint
exec /entrypoint.sh "$@"

