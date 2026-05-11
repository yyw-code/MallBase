#!/bin/sh
# ============================================================
# prepare-data-dirs.sh —— Docker 开发全套模式数据目录权限预检
# ============================================================
# 由 docker-compose.dev.yml 的 prepare-data-dirs 服务调用。
#
# 本脚本负责：
#   1. 创建宿主机挂载目录 data/mysql 与 data/redis
#   2. 将目录权限修复为 MySQL / Redis 容器默认可写用户
#   3. 在容器启动前尽早发现宿主机目录无法写入的问题
# ============================================================
set -eu

WORKDIR="${WORKDIR:-/workdir}"
DATA_UID="${DATA_UID:-999}"
DATA_GID="${DATA_GID:-999}"

prepare_dir() {
    name=$1
    path="${WORKDIR}/${name}"

    echo ">>> [prepare-data-dirs] 准备 ${name}"
    mkdir -p "$path"

    echo ">>> [prepare-data-dirs] 修复 ${name} 权限为 ${DATA_UID}:${DATA_GID}"
    chown -R "${DATA_UID}:${DATA_GID}" "$path"

    if [ ! -d "$path" ]; then
        echo ">>> [prepare-data-dirs] 致命错误：${name} 不是有效目录"
        exit 1
    fi

    if [ ! -r "$path" ] || [ ! -w "$path" ]; then
        echo ">>> [prepare-data-dirs] 致命错误：${name} 不可读写，请检查宿主机挂载权限"
        exit 1
    fi

    marker="${path}/.mallbase-write-test"
    if ! touch "$marker" 2>/dev/null; then
        echo ">>> [prepare-data-dirs] 致命错误：无法写入 ${name}，请检查宿主机挂载权限"
        exit 1
    fi
    rm -f "$marker"
}

prepare_dir "data/mysql"
prepare_dir "data/redis"

echo ">>> [prepare-data-dirs] 完成"
