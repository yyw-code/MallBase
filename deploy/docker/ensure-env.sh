#!/bin/sh
# ============================================================
# ensure-env.sh —— Docker 开发全套模式配置派生脚本
# ============================================================
# 由 docker-compose.dev.yml 的 ensure-env 服务调用。
#
# 设计约束：
#   - 项目根目录 .env 是 Docker 开发全套模式的唯一主配置源
#   - backend/.env 是 ThinkPHP / Swoole 运行时派生文件，不作为第二真相源
#   - 若用户已经定义了根 .env 中的值，则绝不重新随机化或覆盖
#
# 本脚本负责：
#   1. 根 .env 缺失时从 deploy/docker/.example.env 生成
#   2. 根 .env 已存在时仅补齐缺失字段；敏感字段只有在占位符状态下才随机化
#   3. 基于 backend/.example.env 重新派生 backend/.env，并用根 .env 覆盖共享字段
#   4. 为 backend/.env 写入中文头注释，明确“请改根 .env，不要改 backend/.env”
# ============================================================
set -eu
umask 077

WORKDIR="${WORKDIR:-/workdir}"
BACKEND_ENV="${WORKDIR}/backend/.env"
BACKEND_TPL="${WORKDIR}/backend/.example.env"
ROOT_ENV="${WORKDIR}/.env"
ROOT_TPL="${WORKDIR}/deploy/docker/.example.env"
PLACEHOLDER="please-change-or-leave-for-random"
BACKEND_HEADER_1="# 由 Docker 开发全套模式自动生成，请勿手动修改。"
BACKEND_HEADER_2="# 唯一主配置源：项目根目录 /.env"
ROOT_TO_BACKEND_KEYS="SWOOLE_HTTP_PORT SWOOLE_WORKER_NUM SWOOLE_MAX_CONN SWOOLE_BACKLOG SWOOLE_DB_POOL_MAX_ACTIVE SWOOLE_CACHE_POOL_MAX_ACTIVE SWOOLE_REDIS_POOL_MAX_ACTIVE DB_HOST DB_PORT DB_NAME DB_USER DB_PASS REDIS_HOST REDIS_PORT REDIS_CACHE_DB REDIS_PASSWORD CACHE_DRIVER JWT_SECRET JWT_EXPIRE JWT_REFRESH_EXPIRE SITE_URL"
ROOT_INIT_FROM_BACKEND_KEYS="SWOOLE_HTTP_PORT SWOOLE_WORKER_NUM SWOOLE_MAX_CONN SWOOLE_BACKLOG SWOOLE_DB_POOL_MAX_ACTIVE SWOOLE_CACHE_POOL_MAX_ACTIVE SWOOLE_REDIS_POOL_MAX_ACTIVE DB_HOST DB_PORT DB_NAME DB_USER DB_PASS REDIS_HOST REDIS_PORT REDIS_CACHE_DB REDIS_PASSWORD CACHE_DRIVER JWT_SECRET JWT_EXPIRE JWT_REFRESH_EXPIRE"

path_uid() {
    stat -c '%u' "$1" 2>/dev/null || stat -f '%u' "$1"
}

path_gid() {
    stat -c '%g' "$1" 2>/dev/null || stat -f '%g' "$1"
}

path_mode() {
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"
}

require_decimal_id() {
    name=$1
    value=$2
    case "$value" in
        ''|*[!0-9]*)
            echo ">>> [ensure-env] 致命错误：${name} 必须是非负整数"
            exit 1
            ;;
    esac
}

require_safe_env_path() {
    name=$1
    path=$2

    if [ -L "$path" ]; then
        echo ">>> [ensure-env] 致命错误：${name} 不允许是符号链接"
        exit 1
    fi
    if [ -e "$path" ] && [ ! -f "$path" ]; then
        echo ">>> [ensure-env] 致命错误：${name} 必须是普通文件"
        exit 1
    fi
}

handoff_env_file() {
    name=$1
    path=$2
    target_uid=$3
    target_gid=$4

    if [ ! -f "$path" ] || [ -L "$path" ]; then
        echo ">>> [ensure-env] 致命错误：${name} 必须是非符号链接文件"
        exit 1
    fi

    chown "${target_uid}:${target_gid}" "$path"
    chmod 0600 "$path"

    actual_uid=$(path_uid "$path")
    actual_gid=$(path_gid "$path")
    actual_mode=$(path_mode "$path")
    if [ "$actual_uid" != "$target_uid" ] || [ "$actual_gid" != "$target_gid" ]; then
        echo ">>> [ensure-env] 致命错误：${name} owner 校验失败，实际为 ${actual_uid}:${actual_gid}"
        exit 1
    fi
    if [ "$actual_mode" != "600" ]; then
        echo ">>> [ensure-env] 致命错误：${name} 权限校验失败，实际为 ${actual_mode}"
        exit 1
    fi
}

if [ ! -d "$WORKDIR" ]; then
    echo ">>> [ensure-env] 致命错误：项目根目录不存在：$WORKDIR"
    exit 1
fi

PROJECT_ROOT_UID=$(path_uid "$WORKDIR")
PROJECT_ROOT_GID=$(path_gid "$WORKDIR")
MALLBASE_DEV_UID="${MALLBASE_DEV_UID:-$PROJECT_ROOT_UID}"
MALLBASE_DEV_GID="${MALLBASE_DEV_GID:-$PROJECT_ROOT_GID}"

require_decimal_id MALLBASE_DEV_UID "$MALLBASE_DEV_UID"
require_decimal_id MALLBASE_DEV_GID "$MALLBASE_DEV_GID"
require_decimal_id PROJECT_ROOT_UID "$PROJECT_ROOT_UID"
require_decimal_id PROJECT_ROOT_GID "$PROJECT_ROOT_GID"
require_safe_env_path ".env" "$ROOT_ENV"
require_safe_env_path "backend/.env" "$BACKEND_ENV"

rand24() { LC_ALL=C od -An -N12 -tx1 /dev/urandom | tr -d ' \n'; }
rand64() { LC_ALL=C od -An -N32 -tx1 /dev/urandom | tr -d ' \n'; }

has_key() {
    file=$1
    key=$2
    grep -q "^${key}=" "$file"
}

get_value() {
    file=$1
    key=$2
    if [ ! -f "$file" ]; then
        return 0
    fi
    grep "^${key}=" "$file" | head -n1 | sed "s|^${key}=||" || true
}

escape_sed() {
    printf '%s' "$1" | sed -e 's/[\/&|]/\\&/g'
}

set_value() {
    file=$1
    key=$2
    value=$3
    escaped=$(escape_sed "$value")
    if has_key "$file" "$key"; then
        tmp_file=$(mktemp)
        sed "s|^${key}=.*|${key}=${escaped}|" "$file" > "$tmp_file"
        mv "$tmp_file" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

fill_missing_from_template() {
    target=$1
    template=$2

    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            ''|\#*) continue ;;
        esac
        key=$(printf '%s' "$line" | awk -F'=' '{print $1}' | tr -d ' ')
        [ -z "$key" ] && continue
        if ! has_key "$target" "$key"; then
            printf '>>> [ensure-env] 补齐 %s 缺失字段：%s\n' "$target" "$key"
            printf '%s\n' "$line" >> "$target"
        fi
    done < "$template"
}

sync_root_from_existing_backend() {
    [ -f "$BACKEND_ENV" ] || return 0

    for key in $ROOT_INIT_FROM_BACKEND_KEYS; do
        backend_val=$(get_value "$BACKEND_ENV" "$key")
        [ -n "$backend_val" ] || continue
        if [ "$backend_val" = "$PLACEHOLDER" ]; then
            continue
        fi
        set_value "$ROOT_ENV" "$key" "$backend_val"
    done
}

randomize_root_if_placeholder() {
    current=$(get_value "$ROOT_ENV" "$1")
    if [ -z "$current" ] || [ "$current" = "$PLACEHOLDER" ]; then
        set_value "$ROOT_ENV" "$1" "$2"
    fi
}

migrate_legacy_redis_port() {
    if has_key "$ROOT_ENV" "REDIS_HOST_PORT"; then
        return 0
    fi

    legacy_port=$(get_value "$ROOT_ENV" "REDIS_PORT")
    [ -n "$legacy_port" ] || return 0

    set_value "$ROOT_ENV" "REDIS_HOST_PORT" "$legacy_port"
    set_value "$ROOT_ENV" "REDIS_PORT" "6379"
    echo ">>> [ensure-env] 检测到旧 REDIS_PORT 写法，已迁移为 REDIS_HOST_PORT=${legacy_port}，REDIS_PORT=6379"
}

rebuild_backend_env() {
    tmp_env=$(mktemp)
    cp "$BACKEND_TPL" "$tmp_env"

    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            ''|\#*) continue ;;
        esac

        key=$(printf '%s' "$line" | awk -F'=' '{print $1}' | tr -d ' ')
        tpl_val=$(printf '%s' "$line" | sed "s|^${key}=||")
        current_val=$(get_value "$BACKEND_ENV" "$key")
        value=$tpl_val

        if [ -n "$current_val" ]; then
            value=$current_val
        fi

        for sync_key in $ROOT_TO_BACKEND_KEYS; do
            if [ "$key" = "$sync_key" ]; then
                root_val=$(get_value "$ROOT_ENV" "$key")
                if [ -n "$root_val" ]; then
                    value=$root_val
                fi
                break
            fi
        done

        if [ "$key" = "JWT_SECRET" ] && { [ -z "$value" ] || [ "$value" = "$PLACEHOLDER" ]; }; then
            value=$(rand64)
        fi

        set_value "$tmp_env" "$key" "$value"
    done < "$BACKEND_TPL"

    {
        printf '%s\n' "$BACKEND_HEADER_1"
        printf '%s\n' "$BACKEND_HEADER_2"
        printf '\n'
        cat "$tmp_env"
    } > "$BACKEND_ENV"
    chmod 0600 "$BACKEND_ENV"

    rm -f "$tmp_env"
}

if [ ! -f "$BACKEND_TPL" ]; then
    echo ">>> [ensure-env] 致命错误：找不到 $BACKEND_TPL"
    exit 1
fi

if [ ! -f "$ROOT_TPL" ]; then
    echo ">>> [ensure-env] 致命错误：找不到 $ROOT_TPL"
    exit 1
fi

if [ ! -f "$ROOT_ENV" ]; then
    echo ">>> [ensure-env] 根目录 .env 不存在，从 deploy/docker/.example.env 生成"
    cp "$ROOT_TPL" "$ROOT_ENV"
    sync_root_from_existing_backend
fi

migrate_legacy_redis_port
fill_missing_from_template "$ROOT_ENV" "$ROOT_TPL"
randomize_root_if_placeholder "DB_PASS" "$(rand24)"
randomize_root_if_placeholder "MYSQL_ROOT_PASSWORD" "$(rand24)"
randomize_root_if_placeholder "JWT_SECRET" "$(rand64)"

echo ">>> [ensure-env] 开始根据根 .env 派生 backend/.env"
rebuild_backend_env
handoff_env_file "backend/.env" "$BACKEND_ENV" "$MALLBASE_DEV_UID" "$MALLBASE_DEV_GID"
handoff_env_file ".env" "$ROOT_ENV" "$PROJECT_ROOT_UID" "$PROJECT_ROOT_GID"

echo ">>> [ensure-env] 完成"
