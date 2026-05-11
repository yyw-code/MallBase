#!/bin/sh
set -eu

HEARTBEAT_INTERVAL=15

run_with_heartbeat() {
    label=$1
    shift

    echo ">>> [uniapp-build] ${label}"
    "$@" &
    pid=$!
    elapsed=0

    while kill -0 "$pid" 2>/dev/null; do
        sleep "$HEARTBEAT_INTERVAL"
        elapsed=$((elapsed + HEARTBEAT_INTERVAL))
        if kill -0 "$pid" 2>/dev/null; then
            echo ">>> [uniapp-build] ${label}进行中，已用时 ${elapsed}s，请稍候"
        fi
    done

    wait "$pid"
}

echo ">>> [uniapp-build] 1/3 检查 Node 与 npm"
node -v
npm -v

run_with_heartbeat "2/3 安装 UniApp 依赖" npm ci
run_with_heartbeat "3/3 构建 H5 产物" npm run build:h5

if [ ! -d /app/dist/build/h5 ] || [ ! -f /app/dist/build/h5/index.html ]; then
    echo "UniApp H5 构建产物不存在：/app/dist/build/h5"
    exit 1
fi

echo ">>> [uniapp-build] 同步 H5 产物到 backend/public/client"
mkdir -p /dist
rm -rf /dist/*
cp -r /app/dist/build/h5/. /dist/

if [ -d /app/static ]; then
    echo ">>> [uniapp-build] 同步 UniApp static 静态资源"
    mkdir -p /dist/static
    cp -r /app/static/. /dist/static/
fi

echo "[uniapp-build] done"
