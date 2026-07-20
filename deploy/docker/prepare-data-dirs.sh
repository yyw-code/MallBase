#!/bin/sh
# ============================================================
# prepare-data-dirs.sh —— Docker 开发全套模式挂载目录权限预检
# ============================================================
# 由 docker-compose.dev.yml 的 prepare-data-dirs 服务调用。
#
# 本脚本负责：
#   1. 创建宿主机挂载目录 data/mysql 与 data/redis
#   2. 创建 upgrade 下 PHP / Agent 共享的运行目录
#   3. 创建 backend 下开发容器需要的运行配置、运行态与演示素材目录
#   4. 将目录权限修复为对应容器用户可写
#   5. 在容器启动前尽早发现宿主机目录无法写入的问题
# ============================================================
set -eu

WORKDIR="${WORKDIR:-/workdir}"
DATA_UID="${DATA_UID:-999}"
DATA_GID="${DATA_GID:-999}"
MALLBASE_DEV_UID="${MALLBASE_DEV_UID:-10000}"
MALLBASE_DEV_GID="${MALLBASE_DEV_GID:-10000}"

require_decimal_id() {
    name=$1
    value=$2
    case "$value" in
        ''|*[!0-9]*)
            echo ">>> [prepare-data-dirs] 致命错误：${name} 必须是非负整数"
            exit 1
            ;;
    esac
}

require_decimal_id DATA_UID "$DATA_UID"
require_decimal_id DATA_GID "$DATA_GID"
require_decimal_id MALLBASE_DEV_UID "$MALLBASE_DEV_UID"
require_decimal_id MALLBASE_DEV_GID "$MALLBASE_DEV_GID"

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

prepare_upgrade_dir() {
    name=$1
    path="${WORKDIR}/${name}"

    echo ">>> [prepare-data-dirs] 准备 ${name}"
    mkdir -p "$path"

    echo ">>> [prepare-data-dirs] 修复 ${name} 权限为 ${MALLBASE_DEV_UID}:${MALLBASE_DEV_GID} / 2770"
    # 只修正共享目录本身，不递归改动已有任务、配置或备份文件。
    chown "${MALLBASE_DEV_UID}:${MALLBASE_DEV_GID}" "$path"
    chmod 2770 "$path"

    if [ ! -d "$path" ]; then
        echo ">>> [prepare-data-dirs] 致命错误：${name} 不是有效目录"
        exit 1
    fi
}

prepare_backend_dir() {
    name=$1
    path="${WORKDIR}/${name}"

    echo ">>> [prepare-data-dirs] 准备 ${name}"
    if [ -L "$path" ]; then
        echo ">>> [prepare-data-dirs] 致命错误：${name} 不允许是符号链接"
        exit 1
    fi

    mkdir -p "$path"

    if [ -L "$path" ] || [ ! -d "$path" ]; then
        echo ">>> [prepare-data-dirs] 致命错误：${name} 必须是非符号链接目录"
        exit 1
    fi

    echo ">>> [prepare-data-dirs] 交接 ${name} 权限为 ${MALLBASE_DEV_UID}:${MALLBASE_DEV_GID} / 2770"
    # 只交接目标目录本身，不递归改动 backend 源码、运行文件或用户上传文件。
    chown "${MALLBASE_DEV_UID}:${MALLBASE_DEV_GID}" "$path"
    chmod 2770 "$path"

    actual_uid=$(stat -c '%u' "$path" 2>/dev/null || stat -f '%u' "$path")
    actual_gid=$(stat -c '%g' "$path" 2>/dev/null || stat -f '%g' "$path")
    if [ "$actual_uid" != "$MALLBASE_DEV_UID" ] || [ "$actual_gid" != "$MALLBASE_DEV_GID" ]; then
        echo ">>> [prepare-data-dirs] 致命错误：${name} owner 校验失败，实际为 ${actual_uid}:${actual_gid}"
        exit 1
    fi

    actual_mode=$(stat -c '%a' "$path" 2>/dev/null || stat -f '%Lp' "$path")
    if [ "$actual_mode" != "2770" ] && { [ "$(uname -s)" != "Darwin" ] || [ "$actual_mode" != "770" ]; }; then
        echo ">>> [prepare-data-dirs] 致命错误：${name} 权限校验失败，实际为 ${actual_mode}"
        exit 1
    fi
}

prepare_dir "data/mysql"
prepare_dir "data/redis"
prepare_upgrade_dir "upgrade/config"
prepare_upgrade_dir "upgrade/run"
prepare_upgrade_dir "upgrade/run/requests"
prepare_upgrade_dir "upgrade/jobs"
prepare_upgrade_dir "upgrade/backups"
prepare_backend_dir "backend/runtime"
prepare_backend_dir "backend/.mallbase-env"
prepare_backend_dir "backend/public/uploads"
prepare_backend_dir "backend/public/static/demo"
prepare_backend_dir "backend/vendor"

echo ">>> [prepare-data-dirs] 完成"
