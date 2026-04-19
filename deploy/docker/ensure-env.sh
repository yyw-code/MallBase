#!/bin/sh
# ============================================================
# ensure-env.sh —— docker compose 零配置启动兜底脚本
# ============================================================
# 由 docker-compose.dev.yml 的 ensure-env 服务调用（alpine 容器）。
#
# 两份 .env 的职责：
#   - backend/.env：ThinkPHP 运行时业务配置（模板：backend/.example.env）
#   - 根目录 .env：docker compose 做 ${VAR} 插值用（模板：deploy/docker/.example.env）
#
# 本脚本负责：
#   1. backend/.env 不存在 → 从 backend/.example.env 复制 + 随机化 DB_PASS / JWT_SECRET
#   2. backend/.env 存在但缺字段 → 从 backend/.example.env 补齐（不覆盖已有值）
#   3. 根目录 .env 不存在 → 从 deploy/docker/.example.env 复制 + 随机化敏感字段
#   4. 根目录 .env 存在但缺字段 → 从 deploy/docker/.example.env 补齐
#   5. 同步根 .env 的 DB_NAME/DB_USER/DB_PASS 到 backend/.env
#      （root 为主，确保 ThinkPHP 能连上 compose 起的 MySQL 容器）
# ============================================================
set -eu

BACKEND_ENV=/workdir/backend/.env
BACKEND_TPL=/workdir/backend/.example.env
ROOT_ENV=/workdir/.env
ROOT_TPL=/workdir/deploy/docker/.example.env

# 根 .env → backend/.env 的同步字段（确保 ThinkPHP 与 compose 读到同一组凭据）
SYNC_KEYS="DB_NAME DB_USER DB_PASS"

rand24() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24; }
rand64() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c 64; }

if [ ! -f "$BACKEND_TPL" ]; then
    echo ">>> [ensure-env] 致命错误：找不到 $BACKEND_TPL"
    exit 1
fi

if [ ! -f "$ROOT_TPL" ]; then
    echo ">>> [ensure-env] 致命错误：找不到 $ROOT_TPL"
    exit 1
fi

# ---------- 1. 生成 backend/.env ----------
if [ ! -f "$BACKEND_ENV" ]; then
    echo ">>> [ensure-env] backend/.env 不存在，从 backend/.example.env 复制 + 随机化敏感字段"
    cp "$BACKEND_TPL" "$BACKEND_ENV"
    DB_PASS_VAL=$(rand24)
    JWT_VAL=$(rand64)
    sed -i "s|^DB_PASS=.*|DB_PASS=${DB_PASS_VAL}|" "$BACKEND_ENV"
    sed -i "s|^JWT_SECRET=.*|JWT_SECRET=${JWT_VAL}|" "$BACKEND_ENV"
else
    echo ">>> [ensure-env] backend/.env 已存在，跳过随机化"
fi

# ---------- 2. 补齐 backend/.env 缺失字段 ----------
while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
        ''|\#*) continue ;;
    esac
    key=$(printf '%s' "$line" | awk -F'=' '{print $1}' | tr -d ' ')
    [ -z "$key" ] && continue
    if ! grep -q "^${key}=" "$BACKEND_ENV"; then
        echo ">>> [ensure-env] 补齐 backend/.env 缺失字段：$key"
        printf '%s\n' "$line" >> "$BACKEND_ENV"
    fi
done < "$BACKEND_TPL"

# ---------- 3. 生成根 .env ----------
if [ ! -f "$ROOT_ENV" ]; then
    echo ">>> [ensure-env] 根目录 .env 不存在，从 deploy/docker/.example.env 复制 + 随机化敏感字段"
    cp "$ROOT_TPL" "$ROOT_ENV"
    # DB_PASS 复用 backend/.env 里的值（若已为随机值），确保两份一致
    DB_PASS_VAL=$(grep "^DB_PASS=" "$BACKEND_ENV" | awk -F'=' '{print $2}' || true)
    if [ -z "$DB_PASS_VAL" ] || [ "$DB_PASS_VAL" = "please-change-or-leave-for-random" ]; then
        DB_PASS_VAL=$(rand24)
    fi
    MYSQL_ROOT_VAL=$(rand24)
    esc_db_pass=$(printf '%s' "$DB_PASS_VAL" | sed -e 's/[\/&|]/\\&/g')
    sed -i "s|^DB_PASS=.*|DB_PASS=${esc_db_pass}|" "$ROOT_ENV"
    sed -i "s|^MYSQL_ROOT_PASSWORD=.*|MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_VAL}|" "$ROOT_ENV"
fi

# ---------- 4. 补齐根 .env 缺失字段 ----------
while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
        ''|\#*) continue ;;
    esac
    key=$(printf '%s' "$line" | awk -F'=' '{print $1}' | tr -d ' ')
    [ -z "$key" ] && continue
    if ! grep -q "^${key}=" "$ROOT_ENV"; then
        echo ">>> [ensure-env] 补齐根 .env 缺失字段：$key"
        printf '%s\n' "$line" >> "$ROOT_ENV"
    fi
done < "$ROOT_TPL"

# ---------- 5. 同步根 .env → backend/.env（root 为主） ----------
for key in $SYNC_KEYS; do
    root_val=$(grep "^${key}=" "$ROOT_ENV" | head -n1 | sed "s|^${key}=||" || true)
    [ -z "$root_val" ] && continue
    esc_val=$(printf '%s' "$root_val" | sed -e 's/[\/&|]/\\&/g')
    if grep -q "^${key}=" "$BACKEND_ENV"; then
        sed -i "s|^${key}=.*|${key}=${esc_val}|" "$BACKEND_ENV"
    else
        printf '%s=%s\n' "$key" "$root_val" >> "$BACKEND_ENV"
    fi
done

echo ">>> [ensure-env] 完成"
