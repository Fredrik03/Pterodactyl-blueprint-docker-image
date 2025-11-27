FROM ghcr.io/pterodactyl/panel:latest

# Install tools needed for Blueprint & assets
RUN apk add --no-cache \
    curl \
    unzip \
    zip \
    git \
    nodejs \
    npm \
    bash \
    ca-certificates \
    ncurses \
    coreutils

# Install Yarn globally
RUN npm install -g yarn

# Set working directory to panel root
WORKDIR /app

# Install panel JS dependencies
RUN yarn install --ignore-engines

# Download and install latest Blueprint release
RUN RELEASE_URL=$(curl -s https://api.github.com/repos/BlueprintFramework/framework/releases/latest \
    | grep "browser_download_url.*\.zip" \
    | head -n 1 \
    | cut -d '"' -f 4) && \
    curl -L -o /app/release.zip "$RELEASE_URL" && \
    unzip -o /app/release.zip -d /app && \
    rm /app/release.zip

# Create .blueprintrc with sensible defaults (nginx for this image)
RUN echo 'WEBUSER="nginx";' > /app/.blueprintrc && \
    echo 'OWNERSHIP="nginx:nginx";' >> /app/.blueprintrc && \
    echo 'USERSHELL="/bin/bash";' >> /app/.blueprintrc

# Ensure blueprint.sh is executable
RUN chmod +x /app/blueprint.sh

# Create Blueprint directory structure (prevents startup errors before init runs)
RUN mkdir -p /app/.blueprint/extensions/blueprint/private/db && \
    mkdir -p /app/.blueprint/extensions/blueprint/private/debug && \
    mkdir -p /app/.blueprint/extensions/blueprint/public && \
    mkdir -p /app/.blueprint/data && \
    echo '<?php return [];' > /app/.blueprint/extensions/blueprint/private/extensionfs.php && \
    touch /app/.blueprint/extensions/blueprint/private/db/installed_extensions && \
    touch /app/.blueprint/extensions/blueprint/private/db/database && \
    touch /app/.blueprint/extensions/blueprint/private/debug/logs.txt && \
    touch /app/.blueprint/extensions/blueprint/public/index.html && \
    echo '{}' > /app/.blueprint/data/settings.json && \
    chown -R nginx:nginx /app/.blueprint

# Create extensions directory (will be overridden by volume mount)
RUN mkdir -p /srv/pterodactyl/extensions

# Copy Blueprint auto-init script and supervisor config
COPY blueprint-init.sh /blueprint-init.sh
COPY supervisord-blueprint.conf /etc/supervisord.d/blueprint.conf
RUN chmod +x /blueprint-init.sh

# The init script will run automatically via supervisord on container start
# It checks if already initialized and skips if so
