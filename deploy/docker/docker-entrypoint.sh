#!/bin/sh
set -e

mkdir -p /app/runtime /app/public/uploads
chmod -R 777 /app/runtime

BACKEND_ENV="/app/.env"
BACKEND_TPL="/app/.example.env"
ROOT_ENV="/workspace/.env"
PLACEHOLDER="please-change-or-leave-for-random"
ROOT_TO_BACKEND_KEYS="SWOOLE_HTTP_PORT SWOOLE_WORKER_NUM DB_HOST DB_PORT DB_NAME DB_USER DB_PASS REDIS_HOST REDIS_PORT REDIS_CACHE_DB REDIS_PASSWORD CACHE_DRIVER JWT_SECRET JWT_EXPIRE JWT_REFRESH_EXPIRE SITE_URL"
RUNTIME_TO_BACKEND_KEYS="APP_DEBUG CRON_ENABLE QUEUE_CONNECTION QUEUE_REDIS_QUEUE QUEUE_REDIS_HOST QUEUE_REDIS_PORT QUEUE_REDIS_PASSWORD QUEUE_REDIS_SELECT QUEUE_REDIS_TIMEOUT QUEUE_REDIS_PERSISTENT DEFAULT_LANG DB_TYPE DB_HOST DB_NAME DB_USER DB_PASS DB_PORT DB_CHARSET DB_PREFIX CACHE_DRIVER CACHE_PREFIX CACHE_EXPIRE CACHE_TAG_PREFIX REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_TIMEOUT REDIS_PERSISTENT REDIS_CACHE_DB JWT_SECRET JWT_EXPIRE JWT_REFRESH_EXPIRE SWOOLE_HTTP_HOST SWOOLE_HTTP_PORT SWOOLE_WORKER_NUM SWOOLE_MAX_REQUEST SWOOLE_RELOAD_ASYNC SWOOLE_MAX_WAIT_TIME SWOOLE_HEARTBEAT_IDLE_TIME SWOOLE_HEARTBEAT_CHECK_INTERVAL SWOOLE_POOL_MAX_WAIT_TIME SWOOLE_DB_POOL_MAX_ACTIVE SWOOLE_CACHE_POOL_MAX_ACTIVE SWOOLE_REDIS_POOL_MAX_ACTIVE SITE_URL"

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

get_env_value() {
    key=$1
    eval "printf '%s' \"\${$key:-}\""
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

apply_root_env_to_backend() {
    for key in $ROOT_TO_BACKEND_KEYS; do
        root_val=$(get_value "$ROOT_ENV" "$key")
        [ -n "$root_val" ] || continue
        set_value "$BACKEND_ENV" "$key" "$root_val"
    done
}

apply_runtime_env_to_backend() {
    for key in $RUNTIME_TO_BACKEND_KEYS; do
        env_val=$(get_env_value "$key")
        [ -n "$env_val" ] || continue
        set_value "$BACKEND_ENV" "$key" "$env_val"
    done
}

randomize_jwt_secret_if_needed() {
    jwt_secret=$(get_value "$BACKEND_ENV" "JWT_SECRET")
    if [ -z "$jwt_secret" ] || [ "$jwt_secret" = "$PLACEHOLDER" ]; then
        set_value "$BACKEND_ENV" "JWT_SECRET" "$(rand64)"
    fi
}

derive_backend_env() {
    cp "$BACKEND_TPL" "$BACKEND_ENV"

    if [ -f "$ROOT_ENV" ]; then
        apply_root_env_to_backend
    fi

    apply_runtime_env_to_backend
    randomize_jwt_secret_if_needed
}

# backend/.env 是 ThinkPHP / Swoole 运行时派生文件。
# Docker 全套模式通常由 ensure-env 预先派生；仅后端模式跳过 ensure-env 时，
# 这里会根据项目根 .env 或容器环境变量派生，避免用户手工维护第二份配置。
if [ ! -f /app/.env ] && [ -f /app/.example.env ]; then
    if [ -f "$ROOT_ENV" ]; then
        echo ">>> 未找到 /app/.env，正在根据项目根 .env 派生"
        derive_backend_env
    else
        echo ">>> 未找到 /app/.env，正在根据容器环境变量派生"
        derive_backend_env
    fi
fi

if [ -f /app/.env ]; then
    set -a
    . /app/.env
    set +a
fi

# 开发模式下 /app 通常来自宿主机 bind mount，会覆盖镜像层里已经装好的 vendor。
# 因此首次启动前若宿主机 backend/vendor 不存在，这里自动补一次 composer install，
# 让方式二和方式三都能直接起服务。
if [ ! -f /app/vendor/autoload.php ] && [ -f /app/composer.json ]; then
    echo ">>> 未找到 /app/vendor/autoload.php，正在执行 composer install"
    composer install --working-dir /app --no-interaction
fi

exec "$@"
