#!/bin/sh
set -eu

ROOT_ENV=/workdir/.env

if [ -f "$ROOT_ENV" ]; then
    set -a
    . "$ROOT_ENV"
    set +a
fi

# 根 .env 以 DB_* 为主配置名；这里桥接成 MySQL 官方镜像初始化阶段识别的变量名。
# 这样首次启动时，root 密码、业务库名、业务账号和业务库密码都会来自同一份根 .env。
: "${MYSQL_DATABASE:=${DB_NAME:-}}"
: "${MYSQL_USER:=${DB_USER:-}}"
: "${MYSQL_PASSWORD:=${DB_PASS:-}}"
export MYSQL_DATABASE MYSQL_USER MYSQL_PASSWORD

exec docker-entrypoint.sh "$@"
