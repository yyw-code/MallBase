#!/bin/sh
set -eu

ROOT_ENV=/workdir/.env

if [ ! -f "$ROOT_ENV" ]; then
    echo ">>> [check-db-auth] 未找到项目根目录 .env"
    echo ">>> [check-db-auth] 请先检查 ensure-env 是否已生成根 .env。"
    exit 1
fi

set -a
. "$ROOT_ENV"
set +a

required_vars="DB_NAME DB_USER DB_PASS"

for var in $required_vars; do
    eval "value=\${$var:-}"
    if [ -z "$value" ]; then
        echo ">>> [check-db-auth] 缺少必要环境变量：$var"
        echo ">>> [check-db-auth] 请先检查根目录 .env 是否已生成且字段完整。"
        exit 1
    fi
done

if mysql --protocol=TCP -hmysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -e "SELECT 1" >/dev/null 2>&1; then
    echo ">>> [check-db-auth] 业务库账号认证通过"
    exit 0
fi

echo ">>> [check-db-auth] 业务库账号认证失败：当前根 .env 中的 DB_USER/DB_PASS 无法登录 mysql。"
echo ">>> [check-db-auth] 这通常表示 data/mysql 是旧数据，而你后来修改过根 .env 的 DB_PASS。"
echo ">>> [check-db-auth] 如果要保留现有数据，请先确认根 .env 中的 DB_PASS 是目标新密码，然后执行："
echo ">>> [check-db-auth]   docker compose -f docker-compose.dev.yml --profile tools up rotate-db-password"
echo ">>> [check-db-auth] 如果不要现有数据，请按文档执行“完全清零重来”。"
exit 1
