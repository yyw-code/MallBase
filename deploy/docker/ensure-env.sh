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

WORKDIR="${WORKDIR:-/workdir}"
BACKEND_ENV="${WORKDIR}/backend/.env"
BACKEND_TPL="${WORKDIR}/backend/.example.env"
ROOT_ENV="${WORKDIR}/.env"
ROOT_TPL="${WORKDIR}/deploy/docker/.example.env"
PLACEHOLDER="please-change-or-leave-for-random"
BACKEND_HEADER_1="# 由 Docker 开发全套模式自动生成，请勿手动修改。"
BACKEND_HEADER_2="# 唯一主配置源：项目根目录 /.env"
ROOT_TO_BACKEND_KEYS="SWOOLE_HTTP_PORT SWOOLE_WORKER_NUM CRON_ENABLE SWOOLE_QUEUE_ENABLE DB_NAME DB_USER DB_PASS SITE_URL"
ROOT_INIT_FROM_BACKEND_KEYS="SWOOLE_HTTP_PORT SWOOLE_WORKER_NUM CRON_ENABLE SWOOLE_QUEUE_ENABLE DB_NAME DB_USER DB_PASS"

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

fill_missing_from_template "$ROOT_ENV" "$ROOT_TPL"
randomize_root_if_placeholder "DB_PASS" "$(rand24)"
randomize_root_if_placeholder "MYSQL_ROOT_PASSWORD" "$(rand24)"

echo ">>> [ensure-env] 开始根据根 .env 派生 backend/.env"
rebuild_backend_env

echo ">>> [ensure-env] 完成"
