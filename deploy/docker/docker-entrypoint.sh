#!/bin/sh
set -e

mkdir -p /app/runtime /app/public/uploads
chmod -R 777 /app/runtime

# backend/.env 是 ThinkPHP 运行时配置；
# Docker 开发全套模式通常由 ensure-env 预先派生，其他模式缺失时才从模板复制
if [ ! -f /app/.env ] && [ -f /app/.example.env ]; then
    echo ">>> 未找到 .env，正在从 .example.env 复制生成"
    cp /app/.example.env /app/.env
fi

if [ -f /app/.env ]; then
    set -a
    . /app/.env
    set +a
fi

exec "$@"
