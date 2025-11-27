#!/bin/bash
set -euo pipefail

trap 'echo "Interrupted"; exit 1' INT TERM

EXT_DIR="/srv/pterodactyl/extensions"

# Initial sync of blueprint packages into /app for blueprint CLI
rsync -av --exclude=".blueprint" --include="*.blueprint" --exclude="*" --delete "${EXT_DIR}/" "/app/"

# Watch for changes and mirror into /app
inotifywait -m -q \
  -e close_write,delete,moved_to,moved_from \
  --format '%e %w%f' \
  "${EXT_DIR}/" | while read -r event filepath; do
    case "$filepath" in
      *.blueprint)
        case "$event" in
          CLOSE_WRITE,CLOSE|MOVED_TO)
            if ! cp "$filepath" "/app/$(basename "$filepath")"; then
              echo "Error copying: $filepath" >&2
            else
              echo "Updated: $filepath"
            fi
            ;;
          DELETE|MOVED_FROM)
            if ! rm -f "/app/$(basename "$filepath")"; then
              echo "Error removing: $filepath" >&2
            else
              echo "Removed: $filepath"
            fi
            ;;
        esac
        ;;
    esac
  done

