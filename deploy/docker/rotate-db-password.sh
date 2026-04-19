#!/bin/sh
set -eu

ROOT_ENV=/workdir/.env

if [ ! -f "$ROOT_ENV" ]; then
    echo ">>> [rotate-db-password] 未找到项目根目录 .env"
    echo ">>> [rotate-db-password] 请先在项目根目录准备好 .env，再重试。"
    exit 1
fi

set -a
. "$ROOT_ENV"
set +a

required_vars="DB_USER DB_PASS MYSQL_ROOT_PASSWORD"

for var in $required_vars; do
    eval "value=\${$var:-}"
    if [ -z "$value" ]; then
        echo ">>> [rotate-db-password] 缺少必要环境变量：$var"
        echo ">>> [rotate-db-password] 请先检查根目录 .env。"
        exit 1
    fi
done

sql_escape() {
    printf '%s' "$1" | sed -e "s/\\\\/\\\\\\\\/g" -e "s/'/\\\\'/g"
}

escaped_user=$(sql_escape "$DB_USER")
escaped_pass=$(sql_escape "$DB_PASS")

echo ">>> [rotate-db-password] 先按根 .env 重新派生 backend/.env"
sh /workdir/deploy/docker/ensure-env.sh

echo ">>> [rotate-db-password] 开始把 MySQL 中的业务账号密码同步为根 .env 的 DB_PASS"
if mysql --protocol=TCP -hmysql -uroot -p"${MYSQL_ROOT_PASSWORD}" \
    -e "ALTER USER '${escaped_user}'@'%' IDENTIFIED BY '${escaped_pass}'; FLUSH PRIVILEGES;"; then
    echo ">>> [rotate-db-password] 完成：已把 ${DB_USER}@'%' 的密码同步为根 .env 的 DB_PASS"
    echo ">>> [rotate-db-password] 后续重新执行 docker compose up -d，backend 会直接使用新的 backend/.env 运行。"
    exit 0
fi

echo ">>> [rotate-db-password] 执行失败。"
echo ">>> [rotate-db-password] 请确认当前根 .env 里的 MYSQL_ROOT_PASSWORD 仍然是正在运行的 MySQL root 密码。"
echo ">>> [rotate-db-password] 本脚本只负责把业务账号 DB_PASS 同步到现有数据库，不会自动轮换 root 密码。"
exit 1
