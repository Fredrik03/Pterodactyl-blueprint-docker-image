# Pterodactyl Panel with Blueprint

Custom Docker image that extends the official Pterodactyl panel with [Blueprint Framework](https://blueprint.zip) pre-installed and auto-initialized.

## Overview

- **Base Image**: `ghcr.io/pterodactyl/panel:latest`
- **Custom Image**: `ghcr.io/fredrik03/pteropanel-blueprint:latest`
- **Blueprint**: Downloaded during build and initialized automatically on first start

## Build & Push (optional if you rely on GH Actions)

```bash
# Login to GHCR
export CR_PAT=YOUR_GITHUB_TOKEN
echo $CR_PAT | docker login ghcr.io -u fredrik03 --password-stdin

# Build + push
docker build -t ghcr.io/fredrik03/pteropanel-blueprint:latest .
docker push ghcr.io/fredrik03/pteropanel-blueprint:latest
```

## Unraid Setup

1. Edit your existing Pterodactyl panel container
2. Change **Repository** to `ghcr.io/fredrik03/pteropanel-blueprint:latest`
3. Keep every existing environment variable and mount (`/app/var`, `/etc/nginx/http.d`, `/app/storage/logs`, etc.)
4. **Add one new mount** for Blueprint extension packages:

| Host Path | Container Path | Purpose |
|-----------|----------------|---------|
| `/mnt/user/appdata/pteropanel/extensions` | `/srv/pterodactyl/extensions` | Store downloaded `.blueprint` files |

5. Apply & restart the container

## Automatic Blueprint Initialization

- A supervisord job (`blueprint-auto`) waits for your database and runs `bash blueprint.sh`
- The completion flag is stored at `/app/var/.blueprint_initialized` (already on your existing volume)
- On future restarts, the job detects the flag and exits immediately—no manual work needed

### Monitoring

```bash
docker exec -it Pterodactyl-Panel tail -f /var/log/supervisord/blueprint-auto.log
```

### Force Re-run (if you ever need to reinitialize)

```bash
docker exec -it Pterodactyl-Panel rm /app/var/.blueprint_initialized
docker restart Pterodactyl-Panel
```

## Installing Extensions

1. Copy `.blueprint` files to:
   ```
   /mnt/user/appdata/pteropanel/extensions/
   ```
2. Install inside the container:
   ```bash
   docker exec -it Pterodactyl-Panel bash
   cd /app
   blueprint -i /srv/pterodactyl/extensions/example.blueprint
   ```

### Common Commands

```bash
blueprint -i <file>   # Install extension
blueprint -r <name>   # Remove extension
blueprint -l          # List installed extensions
blueprint -v          # Show Blueprint version
```

## Troubleshooting

- **Panel won’t start**: `docker logs Pterodactyl-Panel`
- **Check Blueprint init**: `docker exec -it Pterodactyl-Panel tail -n 100 /var/log/supervisord/blueprint-auto.log`
- **Re-run Blueprint**: delete `/app/var/.blueprint_initialized` and restart

## License

- [Pterodactyl Panel](https://github.com/pterodactyl/panel)
- [Blueprint Framework](https://github.com/BlueprintFramework/framework)
