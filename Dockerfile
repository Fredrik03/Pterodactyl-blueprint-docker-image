ARG PANEL_VERSION=latest
FROM --platform=$TARGETOS/$TARGETARCH ghcr.io/pterodactyl/panel:${PANEL_VERSION}

WORKDIR /app

# Install packages required for Blueprint and helpers
RUN apk update && apk add --no-cache \
    unzip \
    zip \
    curl \
    git \
    bash \
    wget \
    nodejs \
    npm \
    coreutils \
    build-base \
    musl-dev \
    libgcc \
    openssl \
    openssl-dev \
    linux-headers \
    ncurses \
    rsync \
    inotify-tools \
    sed \
    musl-locales \
    netcat-openbsd && \
    rm -rf /var/cache/apk/*

ENV LANG=C.UTF-8
ENV LC_ALL=C.UTF-8
RUN printf 'export LANG=C.UTF-8\nexport LC_ALL=C.UTF-8\n' > /etc/profile.d/locale.sh

# Download and install latest Blueprint release
RUN RELEASE_URL=$(curl -s https://api.github.com/repos/BlueprintFramework/framework/releases/latest \
    | grep "browser_download_url" \
    | cut -d '"' -f 4 \
    | head -n 1) && \
    wget "$RELEASE_URL" -O blueprint.zip && \
    unzip -o blueprint.zip -d /app && \
    touch /.dockerenv && \
    rm blueprint.zip

# Install panel JS dependencies & update browserslist
RUN for i in 1 2 3; do \
        npm install -g yarn && \
        yarn --network-timeout 120000 && \
        npx update-browserslist-db@latest && \
        break || (echo "Attempt $i failed! Retrying..." && sleep 10); \
    done

ENV TERM=xterm

# Helpers (.blueprintrc + runtime scripts)
COPY .helpers /helpers
RUN mv /helpers/.blueprintrc /app/.blueprintrc && \
    chmod +x /helpers/*.sh

# Run Blueprint installer during build
RUN chmod +x blueprint.sh && \
    bash blueprint.sh

# Directory for Blueprint extension volume
RUN mkdir -p /srv/pterodactyl/extensions

# Copy entrypoint wrapper (normalizes DB_HOST/DB_PORT)
COPY scripts/entrypoint-wrapper.sh /usr/local/bin/ptero-entrypoint-wrapper.sh
RUN chmod +x /usr/local/bin/ptero-entrypoint-wrapper.sh

# Register helper processes with supervisord
RUN printf '\n[program:database-seeder]\n' >> /etc/supervisord.conf && \
    printf 'command=/helpers/seeder.sh\n' >> /etc/supervisord.conf && \
    printf 'user=nginx\nautostart=true\nautorestart=false\nstartsecs=0\n' >> /etc/supervisord.conf && \
    printf '\n[program:listener]\n' >> /etc/supervisord.conf && \
    printf 'command=/helpers/listen.sh\n' >> /etc/supervisord.conf && \
    printf 'user=root\nautostart=true\nautorestart=true\n' >> /etc/supervisord.conf && \
    printf '\n[program:fix-bind-mount-perms]\n' >> /etc/supervisord.conf && \
    printf 'command=/helpers/permissions.sh\n' >> /etc/supervisord.conf && \
    printf 'user=root\nautostart=true\nautorestart=false\nstartsecs=0\npriority=1\n' >> /etc/supervisord.conf

ENTRYPOINT ["/usr/local/bin/ptero-entrypoint-wrapper.sh"]
CMD ["/usr/bin/supervisord", "--configuration=/etc/supervisord.conf"]
