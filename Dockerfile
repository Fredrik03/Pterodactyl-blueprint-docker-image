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
    coreutils \
    netcat-openbsd

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

# Create Blueprint directory structure (prevents startup errors)
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

# Create extensions directory (mount your .blueprint files here)
RUN mkdir -p /srv/pterodactyl/extensions

# Copy Blueprint auto-initializer and entrypoint wrapper
COPY scripts/blueprint-auto.sh /usr/local/bin/blueprint-auto.sh
COPY scripts/entrypoint-wrapper.sh /usr/local/bin/ptero-entrypoint-wrapper.sh
RUN chmod +x /usr/local/bin/blueprint-auto.sh \
    /usr/local/bin/ptero-entrypoint-wrapper.sh && \
    mkdir -p /var/log/supervisord

# Configure supervisord to run Blueprint auto-initializer once the panel boots
RUN printf '\n[program:blueprint-auto]\n' >> /etc/supervisord.conf && \
    printf 'command=/usr/local/bin/blueprint-auto.sh\n' >> /etc/supervisord.conf && \
    printf 'autostart=true\nautorestart=false\nstartsecs=0\npriority=5\n' >> /etc/supervisord.conf && \
    printf 'stdout_logfile=/var/log/supervisord/blueprint-auto.log\n' >> /etc/supervisord.conf && \
    printf 'stderr_logfile=/var/log/supervisord/blueprint-auto.log\n' >> /etc/supervisord.conf

# Normalize DB_HOST/DB_PORT before upstream entrypoint runs
ENTRYPOINT ["/usr/local/bin/ptero-entrypoint-wrapper.sh"]

# Ensure supervisord is launched (same as upstream image)
CMD ["/usr/bin/supervisord", "--configuration=/etc/supervisord.conf"]
