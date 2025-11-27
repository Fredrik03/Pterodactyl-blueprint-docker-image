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

# Rename original entrypoint and copy our wrapper
RUN mv /entrypoint.sh /original-entrypoint.sh
COPY entrypoint.sh /entrypoint.sh
COPY blueprint-init.sh /blueprint-init.sh
RUN chmod +x /entrypoint.sh /original-entrypoint.sh /blueprint-init.sh

# Add Blueprint init to supervisord.conf (runs full setup after panel starts)
RUN echo "" >> /etc/supervisord.conf && \
    echo "[program:blueprint-init]" >> /etc/supervisord.conf && \
    echo "command=/blueprint-init.sh" >> /etc/supervisord.conf && \
    echo "autostart=true" >> /etc/supervisord.conf && \
    echo "autorestart=false" >> /etc/supervisord.conf && \
    echo "startsecs=0" >> /etc/supervisord.conf && \
    echo "startretries=1" >> /etc/supervisord.conf && \
    echo "stdout_logfile=/var/log/supervisord/blueprint-init.log" >> /etc/supervisord.conf && \
    echo "stderr_logfile=/var/log/supervisord/blueprint-init.log" >> /etc/supervisord.conf && \
    echo "priority=1" >> /etc/supervisord.conf

# Use original ENTRYPOINT/CMD - our entrypoint.sh replaces the original
# and calls /original-entrypoint.sh after creating Blueprint files
