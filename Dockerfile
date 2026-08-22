# Pinned panel version. Wings on the node must be kept in step with this:
# panel v1.15.x pairs with wings v1.13.x (older panels issue websocket JWTs
# without the "scope" claim wings 1.13+ requires, breaking the server console).
ARG PANEL_VERSION=v1.15.1
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

# Pinned Blueprint release, verified against its published SHA256.
# When bumping BLUEPRINT_VERSION, update BLUEPRINT_SHA256 from the release notes.
ARG BLUEPRINT_VERSION=beta-2026-08
ARG BLUEPRINT_SHA256=38bcee33b19abcbb3460578236ead74668ec39a7861200bbc6902a9152ac118d
RUN wget "https://github.com/BlueprintFramework/framework/releases/download/${BLUEPRINT_VERSION}/release.zip" -O blueprint.zip && \
    echo "${BLUEPRINT_SHA256}  blueprint.zip" | sha256sum -c - && \
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
# Ensure supervisord is launched (matches upstream CMD exactly)
CMD ["supervisord", "-n", "-c", "/etc/supervisord.conf"]
