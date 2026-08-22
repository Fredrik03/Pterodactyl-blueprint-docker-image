# Pterodactyl Panel + Blueprint (Unraid-friendly)

Custom Docker image based on `ghcr.io/pterodactyl/panel` with [Blueprint Framework](https://blueprint.zip) pre-installed plus the same helper services used by the upstream [BlueprintFramework/docker](https://github.com/BlueprintFramework/docker) project. A listener keeps your `.blueprint` files in sync, permissions are fixed on boot, and the Blueprint seeder runs automatically after the panel connects to your database.

## Versions

| Component | Pinned version | Where |
|-----------|----------------|-------|
| Pterodactyl panel | `v1.15.1` | `ARG PANEL_VERSION` in the Dockerfile |
| Blueprint | `beta-2026-08` (SHA256-verified) | `ARG BLUEPRINT_VERSION` in the Dockerfile |
| Wings (on your node) | `v1.13.3` | your node's Wings install — **not** part of this image |

Versions are pinned on purpose. The panel and Wings must be upgraded together:
panels older than 1.12 issue websocket tokens without the `scope` claim that
Wings 1.13+ requires, which breaks the server console with
*"There was an error validating the credentials provided for the websocket"*.
Do not run `wings:latest` on the node — pin it to a version that matches the
panel baked into this image.

To upgrade later: bump `PANEL_VERSION` and `BLUEPRINT_VERSION`/`BLUEPRINT_SHA256`
in the Dockerfile (check the Blueprint release supports the new panel first),
rebuild via the GitHub workflow, then follow **Upgrading a live deployment** below.

## Highlights

- ✓ Uses the official Pterodactyl panel image (`ghcr.io/pterodactyl/panel`)
- ✓ Installs a pinned, checksum-verified Blueprint release at build time
- ✓ Automatically seeds Blueprint tables once MySQL is reachable
- ✓ Watches `/srv/pterodactyl/extensions` and mirrors `.blueprint` files into `/app`
- ✓ Keeps nginx-owned bind mounts writable (same technique as the Blueprint Docker repo)
- ✓ Works with Unraid templates that set `DB_HOST` to `host:port` (entrypoint wrapper normalizes it)

## Unraid Setup

1. Edit your existing panel container
2. Change **Repository** to `ghcr.io/fredrik03/pteropanel-blueprint:latest`
3. Leave every existing environment variable and mount alone (`/app/var`, `/app/storage/logs`, `/etc/nginx/http.d`, etc.)
4. Add the Blueprint volume:

| Host Path | Container Path | Purpose |
|-----------|----------------|---------|
| `/mnt/user/appdata/pteropanel/extensions` | `/srv/pterodactyl/extensions` | Store `.blueprint` packages |

5. Apply & restart

That’s it—the helper services handle everything else.

## What Happens on Boot

1. **Permissions fix** – `/helpers/permissions.sh` ensures nginx owns the bind mounts it needs.
2. **Extension sync** – `/helpers/listen.sh` rsyncs every `.blueprint` from `/srv/pterodactyl/extensions` into `/app`, then watches for changes with `inotifywait` so new uploads appear instantly inside the container.
3. **Database seeder** – `/helpers/seeder.sh` waits for `DB_HOST` to accept connections, then runs `php artisan db:seed --class=BlueprintSeeder --force` so Blueprint’s tables/data are always present.
4. **Panel services** – Supervisord launches nginx, php-fpm, queue workers, etc.

Because this mirrors the official Blueprint Docker flow, you never have to exec into the container to run `bash blueprint.sh` manually [BlueprintFramework/docker](https://github.com/BlueprintFramework/docker).

## Using Blueprint

Blueprint runs inside `/app`, so you interact with it through Docker:


# Enter the container
docker exec -it Pterodactyl-Panel bash

# Install an extension that you dropped into /srv/pterodactyl/extensions
cd /app
blueprint -i /srv/pterodactyl/extensions/example.blueprint

# Other handy commands
blueprint -l    # list installed
blueprint -r ID # remove
blueprint -v    # version
```

Tip: add an alias on your Unraid box to avoid typing the full command:

```bash
echo 'alias blueprint="docker exec -it Pterodactyl-Panel blueprint"' >> ~/.bashrc
```

## Upgrading a live deployment

The panel image runs `php artisan migrate --seed --force` automatically on boot,
so switching to an image with a newer panel migrates your database on first
start. Migrations are one-way — take a backup first.

1. **Back up the database**: `mysqldump -h <db-host> -u <user> -p panel > panel-backup.sql`
2. Pull the new image and recreate the container (Unraid: force update / apply).
3. Watch the first boot: `docker logs -f Pterodactyl-Panel` — you should see
   "Migrating and Seeding D.B" complete without errors.
4. Re-install your Blueprint extensions (`blueprint -i ...`). Check each
   extension's page for compatibility with the new panel version first —
   extensions built against an older panel may need updates.
5. Upgrade Wings on the node to the matching pinned version and restart it.
6. Hard-refresh the browser (cached frontend assets) and confirm the server
   console connects.

## Troubleshooting

- **Container exits immediately** → ensure you pulled the latest image (should stay “Up” because CMD now launches supervisord).
- **DB wait loops** → confirm `DB_HOST`/`DB_PORT` are correct; the wrapper already strips `:3306` formats, so if it still can’t connect, the DB isn’t reachable from the container.
- **Extensions not visible** → confirm they exist at `/mnt/user/appdata/pteropanel/extensions` and check the listener log:
  ```bash
  docker exec -it Pterodactyl-Panel tail -n 100 /var/log/supervisord.log
  ```
- **Need to rescan extensions** → restart the container; the listener re-rsyncs everything on boot.

## License

- [Pterodactyl Panel](https://github.com/pterodactyl/panel)
- [Blueprint Framework](https://github.com/BlueprintFramework/framework)
