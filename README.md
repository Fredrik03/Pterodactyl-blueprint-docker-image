# Pterodactyl Panel with Blueprint

Custom Docker image that extends the official Pterodactyl panel with [Blueprint Framework](https://blueprint.zip) pre-installed.

## Overview

- **Base Image**: `ghcr.io/pterodactyl/panel:latest`
- **Custom Image**: `ghcr.io/fredrik03/pteropanel-blueprint:latest`
- **Blueprint**: Pre-installed, ready to use

## Building the Image



## Unraid Setup

### 1. Update Container Image

Edit your Pterodactyl panel container:
- Change **Repository** to: `ghcr.io/fredrik03/pteropanel-blueprint:latest`

### 2. Add Extensions Volume

Add this path mapping:

| Host Path | Container Path |
|-----------|----------------|
| `/mnt/user/appdata/pteropanel/extensions` | `/srv/pterodactyl/extensions` |

### 3. Keep Existing Settings

- Keep all existing environment variables
- Keep all existing volume mounts (`/app/var`, `/app/storage/logs`, etc.)

### 4. Apply and Start

## Using Blueprint

### First Time Setup (run once after container starts)

```bash
docker exec -it Pterodactyl-Panel bash
cd /app
bash blueprint.sh
```

### Installing Extensions

1. Download `.blueprint` files and copy to:
   ```
   /mnt/user/appdata/pteropanel/extensions/
   ```

2. Install:
   ```bash
   docker exec -it Pterodactyl-Panel bash
   cd /app
   blueprint -i /srv/pterodactyl/extensions/example.blueprint
   ```

### Blueprint Commands

```bash
blueprint -i <file>   # Install extension
blueprint -r <name>   # Remove extension  
blueprint -l          # List installed
blueprint -v          # Version
```

## After Container Recreate

If you recreate the container (not just restart), run these commands again:

```bash
docker exec -it Pterodactyl-Panel bash
cd /app
bash blueprint.sh

# Reinstall your extensions
blueprint -i /srv/pterodactyl/extensions/extension1.blueprint
blueprint -i /srv/pterodactyl/extensions/extension2.blueprint
```

Your `.blueprint` files are safe in the volume - you just need to reinstall them.

## Troubleshooting

**Panel won't start?**
- Check logs: `docker logs Pterodactyl-Panel`

**Blueprint command not found?**
- Run `bash blueprint.sh` first to initialize

**Extensions missing after update?**
- Reinstall from `/srv/pterodactyl/extensions/`

## License

- [Pterodactyl Panel](https://github.com/pterodactyl/panel)
- [Blueprint Framework](https://github.com/BlueprintFramework/framework)
