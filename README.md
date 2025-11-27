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

<<<<<<< HEAD
### 2. Add Extensions Volume

Add this path mapping:

| Host Path | Container Path |
|-----------|----------------|
| `/mnt/user/appdata/pteropanel/extensions` | `/srv/pterodactyl/extensions` |

### 3. Keep Existing Settings

- Keep all existing environment variables
- Keep all existing volume mounts (`/app/var`, `/app/storage/logs`, etc.)

### 4. Apply and Start
=======
## Unraid Configuration

### Important Notes

⚠️ **Data Safety**: Changing the image only swaps the panel code. Your database and appdata volumes remain untouched, so all servers, users, and configuration are preserved.

### Migration Steps (Unraid Docker UI)

1. **Edit your existing Pterodactyl panel container** in Unraid

2. **Update the Repository**:
   - Change from: `ghcr.io/pterodactyl/panel`
   - Change to: `ghcr.io/fredrik03/pteropanel-blueprint:latest`

3. **Keep all existing environment variables** (don't change anything)

4. **Keep all existing volume mounts** (don't remove any)

5. **Add these NEW volume mounts**:

| Name | Host Path | Container Path |
|------|-----------|----------------|
| BlueprintState | `/mnt/user/appdata/pteropanel/blueprint` | `/app/.blueprint` |
| BlueprintExtensions | `/mnt/user/appdata/pteropanel/extensions` | `/srv/pterodactyl/extensions` |

6. **Apply and restart**

### Complete Volume Mounts

| Name | Host Path (Unraid) | Container Path | Purpose |
|------|-------------------|----------------|---------|
| PteroVar | `/mnt/user/appdata/pteropanel/var` | `/app/var/` | Panel data |
| PteroNginx | `/mnt/user/appdata/pteropanel/nginx` | `/etc/nginx/http.d/` | Nginx config |
| PteroCerts | `/mnt/user/appdata/pteropanel/certs` | `/etc/letsencrypt/` | SSL certs |
| PteroLogs | `/mnt/user/appdata/pteropanel/logs` | `/app/storage/logs` | Log files |
| **BlueprintState** | `/mnt/user/appdata/pteropanel/blueprint` | `/app/.blueprint` | Blueprint state (REQUIRED) |
| **BlueprintExtensions** | `/mnt/user/appdata/pteropanel/extensions` | `/srv/pterodactyl/extensions` | Extension files |

### Why These Volumes Matter

- **BlueprintState** (`/app/.blueprint`): Stores initialization flag, installed extensions data, settings. **Without this, Blueprint re-initializes on every container recreate.**
- **BlueprintExtensions** (`/srv/pterodactyl/extensions`): Where you put `.blueprint` files to install.
>>>>>>> 51ce9bb52c48e5cd0b3f11db8da98ba8dabf8c6c

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
