#!/bin/sh
set -e

mkdir -p /app/runtime /app/public/uploads
chmod -R 777 /app/runtime

# 仅在非 Docker 模式（无 DB_HOST 环境变量）且无 .env 时，复制默认配置
# Docker 模式下环境变量由 docker-compose 传入，.env 文件会覆盖这些值，因此不能自动复制
if [ ! -f /app/.env ] && [ -z "${DB_HOST:-}" ]; then
    if [ -f /app/.example.env ]; then
        echo ">>> No .env found, copying .example.env -> .env"
        cp /app/.example.env /app/.env
    fi
fi

exec "$@"
