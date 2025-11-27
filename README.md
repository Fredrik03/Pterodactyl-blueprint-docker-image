# Pterodactyl Panel with Blueprint

This repository contains a custom Docker image that extends the official Pterodactyl panel image with [Blueprint Framework](https://github.com/BlueprintFramework/framework) pre-installed and **automatically configured**.

## Overview

- **Base Image**: `ghcr.io/pterodactyl/panel:latest`
- **Custom Image**: `ghcr.io/fredrik03/pteropanel-blueprint:latest`
- **Blueprint**: Automatically downloaded, installed, and initialized on first start
- **Fully Persistent**: All Blueprint state and extensions survive container updates

## How It Works

1. **First Start**: Blueprint automatically initializes (waits for database, runs setup)
2. **Subsequent Starts**: Skips initialization (already done)
3. **Image Updates**: Just pull new image and restart - everything persists via volumes

No manual steps required!

## Building and Pushing the Image

### Prerequisites

- Docker installed and running
- GitHub Personal Access Token (PAT) with `write:packages` permission

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

## Using Blueprint

### Installing Extensions

1. **Download** a `.blueprint` extension file

2. **Copy to Unraid**:
   ```
   /mnt/user/appdata/pteropanel/extensions/
   ```

3. **Install** (inside container):
   ```bash
   docker exec -it Pterodactyl-Panel blueprint -i /srv/pterodactyl/extensions/example.blueprint
   ```

### Blueprint Commands

```bash
# Enter container
docker exec -it Pterodactyl-Panel bash

# List installed extensions
blueprint -l

# Install extension
blueprint -i /srv/pterodactyl/extensions/example.blueprint

# Remove extension
blueprint -r extension-name

# Help
blueprint -h
```

### Updating the Panel Image

1. **Rebuild** (run GitHub Actions or build locally)
2. **Pull** new image in Unraid (Force Update)
3. **Done** - everything persists, no manual steps needed

## Troubleshooting

### Check Initialization Logs

```bash
docker exec -it Pterodactyl-Panel cat /app/storage/logs/blueprint-init.log
```

### Re-run Initialization

If needed, you can force re-initialization:

```bash
docker exec -it Pterodactyl-Panel rm /app/.blueprint/.initialized
docker restart Pterodactyl-Panel
```

### Check Blueprint Version

```bash
docker exec -it Pterodactyl-Panel blueprint -v
```

## License

- [Pterodactyl Panel](https://github.com/pterodactyl/panel)
- [Blueprint Framework](https://github.com/BlueprintFramework/framework)
