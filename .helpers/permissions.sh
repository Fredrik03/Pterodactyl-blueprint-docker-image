#!/bin/bash

paths=(
    "/app/var"
    "/etc/nginx/http.d"
    "/app/storage/logs"
    "/var/log/nginx"
    "/srv/pterodactyl/extensions"
)

for path in "${paths[@]}"; do
    if [ -d "$path" ]; then
        owner=$(stat -c "%U" "$path")
        if [ "$owner" != "nginx" ]; then
            chown -R nginx: "$path"
        fi
    fi
done

