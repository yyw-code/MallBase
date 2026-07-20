#!/bin/sh
set -eu

HEARTBEAT_INTERVAL=15

run_with_heartbeat() {
    label=$1
    shift

    echo ">>> [frontend-build] ${label}"
    "$@" &
    pid=$!
    elapsed=0

    while kill -0 "$pid" 2>/dev/null; do
        sleep "$HEARTBEAT_INTERVAL"
        elapsed=$((elapsed + HEARTBEAT_INTERVAL))
        if kill -0 "$pid" 2>/dev/null; then
            echo ">>> [frontend-build] ${label}进行中，已用时 ${elapsed}s，请稍候"
        fi
    done

    wait "$pid"
}

echo ">>> [frontend-build] 1/5 启用 corepack"
corepack enable

echo ">>> [frontend-build] 2/5 激活 pnpm"
corepack prepare pnpm@10.28.2 --activate

run_with_heartbeat "3/5 安装前端依赖" pnpm install --frozen-lockfile
run_with_heartbeat "4/5 构建后台前端资源" pnpm run build:antd

echo ">>> [frontend-build] 5/5 同步产物到 backend/public/admin"
rm -rf /dist/*
cp -r apps/web-antd/dist/. /dist/

echo "[frontend-build] done"
