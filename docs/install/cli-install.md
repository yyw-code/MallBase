# 本地命令行安装 `install:auto`

本文说明在**手动安装 / 本地安装**场景下，如何用 `php think install:auto` 执行安装。

这个命令适合以下情况：

- 本地访问 `/install` 安装页失败，需要绕过页面直接执行安装主流程
- 服务器或本机没有使用 Docker，全程由 PHP、MySQL、Redis 本地服务支撑
- 需要回归测试首装 SQL，但不想通过浏览器表单提交

如果你使用的是 Docker 开发全套模式，请优先看 [docker-fullstack.md](./docker-fullstack.md)。本文主流程不使用 Docker。

## 适用范围

- 适用：方式一手动安装、本地 PHP 直跑
- 入口命令：在 `backend/` 目录执行 `php think install:auto`
- 配置来源：项目根目录 `.env`，命令会自动读取；`backend/.env` 作为兜底
- 运行时文件：`backend/.env` 由安装流程写入，供 ThinkPHP / Swoole 后续读取
- 安装锁：源码完整目录下写入 `backend/runtime/install/install.lock`

`install:auto` 默认行为：

- 从当前进程 env 读取数据库、Redis、站点域名
- 管理员账号固定为 `admin`
- 管理员默认密码固定为 `admin123`
- 默认不导入演示数据；需要演示/测试数据时加 `--demo`
- 默认不开启 Cron 和 Swoole 内置队列 Worker
- 如果 `install.lock` 已存在，直接跳过并返回成功
- 不需要先启动 Swoole；如果 Swoole 已经在运行，安装完成后再重启

## 1. 确认当前目录

如果你是在独立副本里测试，先进入该副本的项目根目录。示例：

```bash
cd /Users/gosowong/code/OpenSource/demo/mall-base
pwd
```

确认当前目录下能看到 `backend/`、`deploy/`、`frontend/` 后再继续。

## 2. 检查本地依赖

```bash
php -v
php -m | grep -E "swoole|redis|pdo_mysql|gd|mbstring|zip|intl|bcmath"
composer -V
mysql --version
redis-cli ping
```

如果 Redis 返回 `PONG`，说明 Redis 基础连接正常。

如果缺少 PHP 扩展，先按 [manual.md](./manual.md) 的环境要求补齐。

## 3. 安装后端依赖

本地开发、回归测试或排查安装问题时，使用默认安装：

```bash
composer --working-dir backend install
```

本地按生产方式部署，或服务器手动部署时，使用生产参数：

```bash
composer --working-dir backend install --no-dev --optimize-autoloader
```

参数说明：

- `--no-dev`：不安装测试、调试、代码检查等开发依赖；需要在本地跑测试时不要加。
- `--optimize-autoloader`：生成优化后的自动加载映射，适合生产运行；开发时新增或移动类后，可能需要重新执行 `composer dump-autoload`。
- Docker 开发模式不用手动加这两个参数；Docker 生产镜像会在构建阶段自动完成生产依赖安装和自动加载优化。

## 4. 准备项目根 `.env`

如果项目根 `.env` 不存在：

```bash
cp deploy/docker/.example.env .env
```

编辑项目根 `.env`，至少确认这些字段。下面是本地直跑示例：

```env
SWOOLE_HTTP_PORT=8080

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=mallbase
DB_USER=mallbase
DB_PASS=your_password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_CACHE_DB=0
REDIS_PASSWORD=

CACHE_DRIVER=redis
SITE_URL=http://localhost:8080
```

说明：

- `SITE_URL` 对 CLI 安装是必填输入；Web 安装页可以从 request 推导，CLI 没有浏览器请求上下文。
- `DB_HOST`、`REDIS_HOST` 在本地安装时通常是 `127.0.0.1`；不要沿用 Docker 模板里的 `mysql`、`redis` 服务名。
- `DB_NAME` 可以不存在，安装流程会在数据库校验阶段尝试创建；`DB_USER` 必须拥有目标库完整权限，至少能建库、建表、写入和清理安装测试表。
- `backend/.env` 不作为手工配置入口；命令行安装完成后会由安装流程写入。

确认配置文件里已有安装变量：

```bash
grep -E '^(DB_HOST|DB_PORT|DB_NAME|DB_USER|REDIS_HOST|REDIS_PORT|REDIS_CACHE_DB|SITE_URL)=' .env
```

## 5. 准备空数据库和空 Redis DB

下面示例可先临时导出根 `.env`，仅用于复用变量检查数据库和 Redis；`install:auto` 命令本身不需要这一步：

```bash
set -a
. ./.env
set +a
```

如果数据库用户没有创建库权限，先用 root 创建空库并授权：

```bash
mysql -u root -p -e "
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
"
```

确认目标库为空：

```bash
mysql -h "${DB_HOST}" -P "${DB_PORT:-3306}" -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"
```

输出应为 `0`。

确认 Redis 当前 DB 为空：

```bash
redis-cli -h "${REDIS_HOST}" -p "${REDIS_PORT:-6379}" -n "${REDIS_CACHE_DB:-0}" DBSIZE
```

输出应为 `0`。如果不是空 DB，请切换 `REDIS_CACHE_DB`，或确认数据可删除后清理 Redis。

## 6. 清理旧安装锁

如果之前安装过，命令会因为安装锁直接跳过。重新测试前先确认：

```bash
test -f backend/runtime/install/install.lock && cat backend/runtime/install/install.lock
```

需要重新安装时删除安装锁：

```bash
rm -f backend/runtime/install/install.lock
```

注意：只删除安装锁不会清空数据库和 Redis。目标数据库、Redis DB 仍必须为空，否则安装前置检查会失败。

## 7. 执行命令行安装

```bash
(cd backend && php think install:auto)
```

如需同时导入演示/测试数据：

```bash
(cd backend && php think install:auto --demo)
```

`--demo` 会导入 `backend/install/data/demo/*.sql`，包含演示商品、分类、品牌、规格、评论、用户分组、用户标签、充值套餐等数据，并把 `backend/install/static/demo` 拷贝到 `backend/public/static/demo`。它仍然要求目标数据库和 Redis DB 为空。

成功时会看到类似输出：

```text
[install:auto] 开始自动安装…
[install:auto] env 来源：项目根 .env
[install:auto] DB=mallbase@127.0.0.1:3306/mallbase Redis=127.0.0.1:6379 Admin=admin Demo=no
[install:auto] [01/14] 进行中 校验并准备数据库：正在校验并准备数据库…
[install:auto] [01/14] 完成 校验并准备数据库：连接成功，目标数据库为空，可以继续安装
[install:auto] [02/14] 进行中 校验 Redis 连接：正在校验 Redis 连接…
[install:auto] [02/14] 完成 校验 Redis 连接：连接成功，Redis DB 0 为空，可以继续安装
...
[install:auto] [14/14] 完成 写入安装锁：安装锁写入完成
[install:auto] 安装完成
[install:auto] 基本信息：
[install:auto] - 管理员账号：admin
[install:auto] - 管理员密码：admin123
[install:auto] - 演示数据：未安装
[install:auto] - 站点地址：http://127.0.0.1:8080
[install:auto] - 管理后台：http://127.0.0.1:8080/admin/
[install:auto] - 客户端入口：http://127.0.0.1:8080/client/
[install:auto] - 数据库：mallbase@127.0.0.1:3306/mallbase
[install:auto] - Redis：127.0.0.1:6379 DB 0
[install:auto] 安装完成后请尽快修改默认管理员密码。
[install:auto] 安装完成后请重启 Swoole，让新配置和安装锁生效。
```

使用 `--demo` 时，开始摘要会显示 `Demo=yes`，成功摘要会显示 `演示数据：已安装`，进度中会看到“导入演示数据”和“拷贝演示静态资源”步骤。

如果输出 `install.lock 已存在，跳过`，说明当前环境已经安装过。需要重新测试首装时，请回到第 5、6 步确认空库、空 Redis 和安装锁。

## 8. 重启本地 Swoole

安装流程会写入 `backend/.env`，并生成运行态标记。若 Swoole 已经在运行，安装完成后必须重启：

```bash
lsof -ti :8080 | xargs kill
(cd backend && php think swoole)
```

如果还没有启动 Swoole，直接执行：

```bash
(cd backend && php think swoole)
```

## 9. 验证安装结果

检查安装锁：

```bash
cat backend/runtime/install/install.lock
```

检查数据库表数量：

```bash
mysql -h "${DB_HOST}" -P "${DB_PORT:-3306}" -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"
```

对照当前首装 SQL 中声明的建表数量：

```bash
grep -R "CREATE TABLE" backend/install/data/schema/*.sql | wc -l
```

检查核心表：

```bash
mysql -h "${DB_HOST}" -P "${DB_PORT:-3306}" -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -e \
  "SHOW TABLES LIKE 'mb_admin'; SHOW TABLES LIKE 'mb_setting'; SHOW TABLES LIKE 'mb_goods';"
```

检查 HTTP：

```bash
curl -I http://127.0.0.1:8080/
```

如果你已经构建后台前端，再检查后台入口：

```bash
curl -I http://127.0.0.1:8080/admin/
```

## 10. 登录信息

命令行安装使用内置管理员信息：

- 用户名：`admin`
- 密码：`admin123`

登录后可在个人资料页修改默认管理员密码。

## 常见问题

### `缺少必要 env 变量`

确认项目根 `.env` 存在，并包含：

```text
DB_HOST
DB_USER
DB_NAME
REDIS_HOST
SITE_URL
```

然后在项目根目录重新执行：

```bash
(cd backend && php think install:auto)
```

### `站点域名（site_url）未指定`

在项目根 `.env` 中设置：

```env
SITE_URL=http://localhost:8080
```

修改后重新执行 `install:auto`；如果 Swoole 已经在运行，安装完成后还需要重启。

### 数据库已有表

`install:auto` 不会覆盖已有业务表。请选择一个空数据库，或确认可以删除后清空旧表。

### Redis DB 已有 key

切换 `REDIS_CACHE_DB` 到空 DB，或确认可删除后清理 Redis：

```bash
redis-cli -h 127.0.0.1 -p 6379 -n 0 FLUSHDB
```

### 后台页面打不开

`install:auto` 只安装后端数据，不构建后台前端。需要访问 `/admin/` 时，请先按 [admin-build.md](./admin-build.md) 构建后台前端。

## 相关文档

- [manual.md](./manual.md)：手动安装完整流程
- [commands-local.md](./commands-local.md)：本地安装与 Swoole 命令
- [admin-build.md](./admin-build.md)：后台前端构建
- [troubleshooting.md](./troubleshooting.md)：安装故障排查
