#!/bin/sh
set -e

mkdir -p /app/runtime /app/public/uploads
chmod -R 777 /app/runtime

# backend/.env 是唯一配置源；缺失则从 .example.env 复制
if [ ! -f /app/.env ] && [ -f /app/.example.env ]; then
    echo ">>> No .env found, copying .example.env -> .env"
    cp /app/.example.env /app/.env
fi

exec "$@"
