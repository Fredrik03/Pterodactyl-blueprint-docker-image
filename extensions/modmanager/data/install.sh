#!/bin/bash
# Blueprint copies this extension's migration into database/migrations but, when it
# detects Docker, leaves running it to the container's next boot. Run it now so the
# Mods tab works immediately instead of 500ing until a restart. Safe to re-run:
# the migration checks whether the table already exists.
cd "$PTERODACTYL_DIRECTORY" || exit 0
php artisan migrate --force --path=database/migrations/2026_10_09_120000_create_modmanager_installs_table.php >/dev/null 2>&1 \
  && echo "[modmanager] database table ready" \
  || echo "[modmanager] could not run the migration now; it will run on the next panel start"
exit 0
