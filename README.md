# Pterodactyl Panel with Blueprint

This repository contains a custom Docker image that extends the official Pterodactyl panel image with [Blueprint Framework](https://github.com/BlueprintFramework/framework) pre-installed.

## Overview

- **Base Image**: `ghcr.io/pterodactyl/panel:latest`
- **Custom Image**: `ghcr.io/fredrik03/pteropanel-blueprint:latest`
- **Blueprint**: Automatically downloaded and installed from the latest GitHub release
- **Extensions**: Stored persistently in `/srv/pterodactyl/extensions` (mounted volume)

## Building and Pushing the Image

### Prerequisites

- Docker installed and running
- GitHub Personal Access Token (PAT) with `write:packages` permission
- Access to `ghcr.io` registry

### Build Steps

1. **Log in to GitHub Container Registry**:

   ```bash
   export CR_PAT=YOUR_GHCR_TOKEN_HERE
   echo $CR_PAT | docker login ghcr.io -u fredrik03 --password-stdin
   ```

2. **Build the image**:

   ```bash
   docker build -t ghcr.io/fredrik03/pteropanel-blueprint:latest .
   ```

3. **Push to registry**:

   ```bash
   docker push ghcr.io/fredrik03/pteropanel-blueprint:latest
   ```

## Unraid Configuration

### Important Notes

⚠️ **Data Safety**: Changing the image to `ghcr.io/fredrik03/pteropanel-blueprint:latest` only swaps the panel code. Your database and appdata volumes remain untouched, so all servers, users, and configuration are preserved.

### Migration Steps (Unraid Docker UI)

1. **Edit your existing Pterodactyl panel container** in Unraid

2. **Update the Repository**:
   - Change from: `ghcr.io/pterodactyl/panel`
   - Change to: `ghcr.io/fredrik03/pteropanel-blueprint:latest`

3. **Keep all existing environment variables**:
   - Do NOT change: `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST`, `APP_URL`, etc.
   - All existing environment variables should remain exactly as they are

4. **Keep all existing volume mounts**:
   - `/app/var/` (panel data)
   - `/etc/nginx/http.d/` (nginx config)
   - `/etc/letsencrypt/` (SSL certs)
   - `/app/storage/logs` (log files)
   - Do NOT remove or modify these mounts

5. **Add new Blueprint extensions volume**:
   - **Name**: `PteroBlueprintExtensions`
   - **Host path**: `/mnt/user/appdata/pteropanel/extensions`
   - **Container path**: `/srv/pterodactyl/extensions`
   - **Access mode**: Read/Write

6. **Apply changes and restart the container**

### Volume Mounts Summary

| Name | Host Path (Unraid) | Container Path | Purpose |
|------|-------------------|----------------|---------|
| PteroVar | `/mnt/user/appdata/pteropanel/var` | `/app/var/` | Panel data |
| PteroNginx | `/mnt/user/appdata/pteropanel/nginx` | `/etc/nginx/http.d/` | Nginx config |
| PteroCerts | `/mnt/user/appdata/pteropanel/certs` | `/etc/letsencrypt/` | SSL certs |
| PteroLogs | `/mnt/user/appdata/pteropanel/logs` | `/app/storage/logs` | Log files |
| **PteroBlueprintExtensions** | `/mnt/user/appdata/pteropanel/extensions` | `/srv/pterodactyl/extensions/` | Blueprint extensions |

## Docker Compose (Alternative)

If you prefer using Docker Compose instead of Unraid's Docker UI, a `docker-compose.yml` is included in this repository. It follows the **official Pterodactyl docker-compose format** with two modifications:

1. Uses `ghcr.io/fredrik03/pteropanel-blueprint:latest` instead of the official image
2. Adds the Blueprint extensions volume mount

### Quick Start with Docker Compose

1. **Edit `docker-compose.yml`** and update:
   - `MYSQL_PASSWORD` and `MYSQL_ROOT_PASSWORD` (in the `x-common` section)
   - `APP_URL` to your panel's URL
   - `APP_TIMEZONE` to your timezone
   - Volume paths if different from `/srv/pterodactyl/`

2. **Create the required directories**:
   ```bash
   mkdir -p /srv/pterodactyl/{var,nginx,certs,logs,extensions,database}
   ```

3. **Start the stack**:
   ```bash
   docker-compose up -d
   ```

### Using with Existing Database/Redis

If you already have MariaDB and Redis running (which you likely do on Unraid), remove the `database` and `cache` services from `docker-compose.yml` and update the environment:

```yaml
environment:
  # Point to your existing services
  DB_HOST: "YOUR_EXISTING_DB_IP"      # e.g., 192.168.1.100
  REDIS_HOST: "YOUR_EXISTING_REDIS_IP"
  DB_PASSWORD: "your_existing_password"
```

Also remove the `links` section since you won't be using the compose-managed database/cache.

### Volume Persistence

- **Blueprint Framework**: Baked into the image, so it survives container restarts and recreates
- **Blueprint Extensions**: Stored in `/srv/pterodactyl/extensions` (mapped to `/mnt/user/appdata/pteropanel/extensions` on host), so `.blueprint` files persist across image updates

## Using Blueprint

### Initial Setup

1. **Access the container**:

   ```bash
   docker exec -it <container-name> bash
   ```

2. **Navigate to panel directory**:

   ```bash
   cd /app
   ```

3. **Run Blueprint CLI** (initial setup):

   ```bash
   bash blueprint.sh
   ```

### Installing Extensions

1. **Copy `.blueprint` files** to your host path:
   ```
   /mnt/user/appdata/pteropanel/extensions/
   ```

2. **Inside the container**, install an extension:

   ```bash
   cd /app
   blueprint -i /srv/pterodactyl/extensions/example.blueprint
   ```

### Updating the Panel Image

When you update to a new version of this image:

1. Pull the new image: `ghcr.io/fredrik03/pteropanel-blueprint:latest`
2. Restart/recreate the container
3. Your `.blueprint` extension files remain in the volume at `/srv/pterodactyl/extensions`
4. Re-run any necessary Blueprint commands if needed after the update

## Directory Structure

```
/app                          # Panel code (from base image)
/app/.blueprintrc            # Blueprint configuration
/app/blueprint.sh            # Blueprint CLI script
/srv/pterodactyl/extensions  # Blueprint extensions (volume mount)
```

## Troubleshooting

- If Blueprint commands fail, ensure the extensions directory has proper permissions
- Check that the web user (www-data or nginx) has access to `/app` and `/srv/pterodactyl/extensions`
- Review container logs if Blueprint installation issues occur

## License

This repository extends the Pterodactyl panel image. Please refer to:
- [Pterodactyl Panel License](https://github.com/pterodactyl/panel)
- [Blueprint Framework License](https://github.com/BlueprintFramework/framework)

