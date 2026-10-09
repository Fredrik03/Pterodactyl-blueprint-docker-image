#!/usr/bin/env bash
# Throwaway local panel for trying the bundled extensions. NOT for production.
#
#   extensions/dev-stack.sh up        start MariaDB + Redis + the panel image, seed an admin and a Forge server
#   extensions/dev-stack.sh install   package extensions/modmanager and install it with the Blueprint CLI
#   extensions/dev-stack.sh down      remove the containers and network
#   extensions/dev-stack.sh logs      follow the panel log
#
# Panel:   http://localhost:18080
# Login:   admin@example.com / Password123!   (test account, local only)
# Server:  "forge-test" (Forge Minecraft egg, no Wings node behind it, so installs stop at the Wings call)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
IMAGE="${IMAGE:-ghcr.io/fredrik03/pteropanel-blueprint:latest}"
PORT="${PORT:-18080}"
NET=mm-test
DB=mm-db
REDIS=mm-redis
PANEL=mm-panel
ADMIN_EMAIL=admin@example.com
ADMIN_USER=admin
ADMIN_PASS='Password123!'

log() { printf '\033[1;34m[dev-stack]\033[0m %s\n' "$*"; }

up() {
  docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
  if ! docker ps --format '{{.Names}}' | grep -qx "$DB"; then
    log "starting MariaDB"
    docker run -d --name "$DB" --network "$NET" \
      -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=panel -e MARIADB_USER=ptero -e MARIADB_PASSWORD=ptero \
      mariadb:11 >/dev/null
  fi
  if ! docker ps --format '{{.Names}}' | grep -qx "$REDIS"; then
    log "starting Redis"
    docker run -d --name "$REDIS" --network "$NET" redis:7-alpine >/dev/null
  fi
  if ! docker ps --format '{{.Names}}' | grep -qx "$PANEL"; then
    log "starting panel ($IMAGE) on port $PORT"
    docker run -d --name "$PANEL" --network "$NET" -p "$PORT:80" \
      -e APP_URL="http://localhost:$PORT" -e APP_ENV=production -e APP_ENVIRONMENT_ONLY=false -e APP_TIMEZONE=UTC \
      -e APP_SERVICE_AUTHOR=test@example.com \
      -e DB_HOST="$DB" -e DB_PORT=3306 -e DB_DATABASE=panel -e DB_USERNAME=ptero -e DB_PASSWORD=ptero \
      -e CACHE_DRIVER=redis -e SESSION_DRIVER=redis -e QUEUE_DRIVER=redis -e QUEUE_CONNECTION=redis -e REDIS_HOST="$REDIS" \
      -e MAIL_DRIVER=log -e MAIL_MAILER=log -e TRUSTED_PROXIES='*' \
      "$IMAGE" >/dev/null
  fi

  log "waiting for the panel to boot"
  for _ in $(seq 1 120); do
    docker logs "$PANEL" 2>&1 | grep -q "Starting supervisord" && break
    sleep 5
  done

  # The image expects /app/var to be a mounted volume holding .env; create it here.
  if ! docker exec "$PANEL" test -s /app/var/.env; then
    log "writing /app/var/.env"
    docker exec -e APP_KEY="base64:$(openssl rand -base64 32)" "$PANEL" sh -c \
      'mkdir -p /app/var && printf "APP_KEY=%s\nHASHIDS_SALT=%s\n" "$APP_KEY" "$(head -c 24 /dev/urandom | base64 | tr -dc a-zA-Z0-9 | head -c 20)" > /app/var/.env && chown -R nginx:nginx /app/var'
  fi

  log "migrating and seeding"
  docker exec "$PANEL" sh -c 'cd /app && php artisan migrate --seed --force >/dev/null && php artisan db:seed --class=BlueprintSeeder --force >/dev/null && php artisan view:clear >/dev/null'

  log "seeding admin user, node and a Forge server"
  docker exec "$PANEL" sh -c "cd /app && php artisan tinker --execute='echo \\Pterodactyl\\Models\\User::where(\"email\",\"$ADMIN_EMAIL\")->exists() ? \"yes\" : \"no\";'" 2>/dev/null | grep -q yes \
    || docker exec "$PANEL" sh -c "cd /app && php artisan p:user:make --email=$ADMIN_EMAIL --username=$ADMIN_USER --name-first=Admin --name-last=User --password='$ADMIN_PASS' --admin=1 --no-interaction >/dev/null"
  docker exec "$PANEL" sh -c "cd /app && php artisan tinker --execute='echo \\Pterodactyl\\Models\\Node::count();'" 2>/dev/null | grep -qx 0 \
    && docker exec "$PANEL" sh -c "cd /app && php artisan p:location:make --short=test --long='Test location' --no-interaction >/dev/null && php artisan p:node:make --name=node --description=x --locationId=1 --fqdn=wings.invalid --public=1 --scheme=http --proxy=0 --maintenance=0 --maxMemory=8192 --overallocateMemory=0 --maxDisk=40960 --overallocateDisk=0 --uploadSize=100 --daemonListeningPort=8080 --daemonSFTPPort=2022 --daemonBase=/var/lib/pterodactyl/volumes --no-interaction >/dev/null" \
    || true

  docker exec -i "$PANEL" sh -c 'cat > /tmp/seed.php' <<'PHP'
<?php
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Models\User;
use Illuminate\Support\Str;

if (Server::query()->where('name', 'forge-test')->exists()) {
    echo "server exists\n";
    return;
}
$user = User::query()->where('email', 'admin@example.com')->firstOrFail();
$node = Node::query()->firstOrFail();
$alloc = Allocation::query()->firstOrCreate(['node_id' => $node->id, 'ip' => '127.0.0.1', 'port' => 25565]);
$egg = Egg::query()->where('name', 'like', '%Forge%')->firstOrFail();
$uuid = (string) Str::uuid();
$server = Server::query()->forceCreate([
    'uuid' => $uuid, 'uuidShort' => substr($uuid, 0, 8), 'node_id' => $node->id, 'name' => 'forge-test',
    'description' => '', 'owner_id' => $user->id, 'memory' => 2048, 'swap' => 0, 'disk' => 10240, 'io' => 500,
    'cpu' => 0, 'threads' => null, 'oom_disabled' => true, 'allocation_id' => $alloc->id, 'nest_id' => $egg->nest_id,
    'egg_id' => $egg->id, 'startup' => $egg->startup,
    'image' => array_values($egg->docker_images)[0] ?? 'ghcr.io/pterodactyl/yolks:java_17',
    'skip_scripts' => false, 'database_limit' => 0, 'allocation_limit' => 0, 'backup_limit' => 0,
    'status' => null, 'installed_at' => now(),
]);
foreach ($egg->variables as $variable) {
    ServerVariable::query()->create(['server_id' => $server->id, 'variable_id' => $variable->id, 'variable_value' => $variable->default_value ?? '']);
}
$alloc->update(['server_id' => $server->id]);
echo "server created: " . $server->uuidShort . "\n";
PHP
  docker exec "$PANEL" sh -c 'cd /app && php artisan tinker --execute="require \"/tmp/seed.php\";"' 2>/dev/null | tail -1

  SHORT=$(docker exec "$PANEL" sh -c 'cd /app && php artisan tinker --execute="echo \Pterodactyl\Models\Server::where(\"name\",\"forge-test\")->value(\"uuidShort\");"' 2>/dev/null | tail -1)
  log "ready: http://localhost:$PORT  (login $ADMIN_EMAIL / $ADMIN_PASS)"
  log "server page: http://localhost:$PORT/server/$SHORT   admin page: http://localhost:$PORT/admin/extensions/modmanager"
}

install() {
  local id="${1:-modmanager}"
  "$HERE/package.sh" "$id"
  docker cp "$HERE/$id.blueprint" "$PANEL:/app/$id.blueprint"
  log "running blueprint -i $id (includes a frontend build, about a minute)"
  docker exec "$PANEL" sh -c "cd /app && blueprint -i $id" 2>&1 | sed 's/\x1b\[[0-9;]*[A-Za-z]//g' | grep -E "SUCCESS|FATAL|WARNING|\[$id\]" || true
  # Blueprint prints SUCCESS even when webpack failed; the only reliable sign is the bundle on disk.
  if docker exec "$PANEL" sh -c 'ls /app/public/assets/bundle.*.js >/dev/null 2>&1'; then
    log "frontend bundle present"
  else
    log "FRONTEND BUILD FAILED: no bundle in /app/public/assets. Showing webpack errors:"
    docker exec "$PANEL" sh -c 'cd /app && NODE_OPTIONS=--openssl-legacy-provider yarn run build:production 2>&1 | grep -B1 -A4 "ERROR in" | head -40' || true
    exit 1
  fi
}

down() {
  docker rm -f "$PANEL" "$DB" "$REDIS" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  log "removed"
}

case "${1:-help}" in
  up) up ;;
  install) install "${2:-modmanager}" ;;
  down) down ;;
  logs) docker logs -f "$PANEL" ;;
  *) sed -n '2,12p' "$0" ;;
esac
