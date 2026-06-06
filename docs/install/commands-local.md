# 本地安装与 Swoole 命令

本页用于方式一手动安装、本地 PHP 直跑，以及本地安装失败后的 `install:auto` 补救流程。完整流程见 [manual.md](./manual.md) 和 [cli-install.md](./cli-install.md)。

## 安装后端依赖

本地开发、调试、跑测试：

```bash
composer --working-dir backend install
```

本地模拟生产部署，或服务器手动部署：

```bash
composer --working-dir backend install --no-dev --optimize-autoloader
```

说明：

- 开发环境默认不要加 `--no-dev`，否则会缺少测试和开发工具依赖。
- `--optimize-autoloader` 适合生产运行；日常开发新增或移动类后，可能需要重新执行 `composer dump-autoload`。
- Docker 开发容器首次启动会自动执行普通 `composer install`；Docker 生产镜像构建时已自动使用生产依赖和优化自动加载。

## `install:auto` 会做什么

执行：

```bash
(cd backend && php think install:auto)
```

命令会复用安装向导的同一套 `InstallService` 主流程，不维护第二套安装逻辑。它会按顺序执行：

1. 读取安装配置。
2. 如果 `backend/runtime/install/install.lock` 已存在，直接跳过并返回成功。
3. 校验数据库连接，目标库不存在时尝试创建，目标库必须为空。
4. 校验 Redis 连接，目标 Redis DB 必须为空。
5. 写入 `backend/.env`，并把配置应用到当前安装进程。
6. 导入 `backend/install/data/schema/*.sql` 表结构。
7. 创建默认管理员账号 `admin` / `admin123`。
8. 默认跳过演示数据和演示静态资源；加 `--demo` 时会导入 `backend/install/data/demo/*.sql`，并拷贝 `backend/install/static/demo` 到 `backend/public/static/demo`。
9. 同步路由权限、系统设置菜单、地区数据和站点域名。
10. 检查默认静态资源。
11. 写入 `backend/runtime/install/install.lock`。

`install:auto` 只安装后端数据，不构建 Admin 或 UniApp 前端。需要访问 `/admin/` 或 `/client/` 时，还要按 [admin-build.md](./admin-build.md)、[uniapp-build.md](./uniapp-build.md) 构建前端产物。

运行 `install:auto` 不需要先启动 Swoole。它是 PHP CLI 命令，会直接连接 MySQL 和 Redis 并写入 `backend/.env`。如果 Swoole 已经在运行，安装完成后需要重启 Swoole，让新配置和安装锁生效。

## 配置来源和优先级

`install:auto` 会自动读取配置，不需要手动 `source .env`。

优先级从高到低：

1. 当前进程 env，例如你手动 `export DB_HOST=...`。
2. 项目根目录 `.env`。
3. `backend/.env`，用于生产、仅后端容器或历史环境兜底。
4. 主机和端口默认值：本地非容器场景下，`DB_HOST` / `REDIS_HOST` 默认 `127.0.0.1`，`DB_PORT` 默认 `3306`，`REDIS_PORT` 默认 `6379`。

本地直跑时，如果项目根 `.env` 里写的是 Docker 服务名 `mysql` / `redis`，命令会自动把它们按本机访问方式转成 `127.0.0.1`。

CLI 安装至少需要这些输入：

```text
DB_USER
DB_NAME
SITE_URL
```

`DB_HOST`、`DB_PORT`、`REDIS_HOST`、`REDIS_PORT` 有本地默认值；`DB_PASS`、`REDIS_PASSWORD` 可以为空，按你的本地服务实际配置决定。

## 如果根目录 `.env` 没有配置

直接执行：

```bash
(cd backend && php think install:auto)
```

会出现下面几种情况：

- 如果当前进程 env 也没有安装变量，且 `backend/.env` 不存在：命令只会使用 `127.0.0.1:3306` 和 `127.0.0.1:6379` 作为连接默认值，然后因为缺少 `DB_USER`、`DB_NAME`、`SITE_URL` 停止安装；不会导入 SQL，也不会写安装锁。
- 如果 `backend/.env` 已存在：命令会把它作为兜底配置，可能连接到旧环境。重新安装前建议先确认 `backend/.env` 内容，或用 `sh deploy/docker/cleanup-dev.sh --basic` 清理安装运行态。
- 如果你已经通过 shell 导出了 `DB_USER`、`DB_NAME`、`SITE_URL` 等变量：当前进程 env 会优先于 `.env` 文件生效。

未发现任何安装 env 文件时，输出会类似：

```text
[install:auto] 开始自动安装…
[install:auto] env 来源：未发现安装 env 文件，将仅使用主机和端口默认值
[install:auto] 缺少必要 env 变量：DB_USER, DB_NAME, SITE_URL
[install:auto] 请检查项目根 .env 或 backend/.env 是否包含安装所需配置
```

建议本地命令行安装前先准备项目根 `.env`：

```bash
cp deploy/docker/.example.env .env
```

然后至少确认：

```bash
grep -E '^(DB_HOST|DB_PORT|DB_NAME|DB_USER|DB_PASS|REDIS_HOST|REDIS_PORT|REDIS_CACHE_DB|SITE_URL)=' .env
```

本地直跑常见配置示例：

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=mallbase
DB_USER=mallbase
DB_PASS=your_password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_CACHE_DB=0
REDIS_PASSWORD=

SITE_URL=http://localhost:8080
```

## 临时导出变量用于检查

下面步骤只用于复用 `.env` 里的变量执行 MySQL / Redis 检查，不是 `install:auto` 必需步骤。

```bash
set -a
. ./.env
set +a
```

## 检查数据库为空

数据库账号需要拥有目标库完整权限；如果目标库不存在，安装流程会在数据库校验阶段尝试创建。

```bash
mysql -h "${DB_HOST}" -P "${DB_PORT:-3306}" -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"
```

输出应为 `0`。

## 检查 Redis DB 为空

```bash
redis-cli -h "${REDIS_HOST}" -p "${REDIS_PORT:-6379}" -n "${REDIS_CACHE_DB:-0}" DBSIZE
```

输出应为 `0`。

## 执行命令行安装

```bash
(cd backend && php think install:auto)
```

如果需要同时安装演示/测试数据：

```bash
(cd backend && php think install:auto --demo)
```

`--demo` 会导入演示商品、分类、品牌、规格、评论、用户分组、用户标签、充值套餐等数据，并复制演示图片到 `backend/public/static/demo`。它仍然要求目标数据库和 Redis DB 为空；如果已经安装过，需要先清理安装锁、数据库和 Redis 后再重新安装。

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
[install:auto] 首次登录后请修改默认管理员密码。
[install:auto] 安装完成后请重启 Swoole，让新配置和安装锁生效。
```

使用 `--demo` 时，开始摘要会显示 `Demo=yes`，成功摘要会显示 `演示数据：已安装`，进度中会看到“导入演示数据”和“拷贝演示静态资源”步骤。

如果输出 `install.lock 已存在，跳过`，说明当前环境已经安装过。需要重新测试首装时，请同时确认空库、空 Redis 和安装锁。

## 验证安装锁

```bash
test -f backend/runtime/install/install.lock && cat backend/runtime/install/install.lock
```

## 统计数据库表

```bash
mysql -h "${DB_HOST}" -P "${DB_PORT:-3306}" -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"
```

## 启动本地 Swoole

```bash
(cd backend && php think swoole)
```

## 重启本地 Swoole

```bash
lsof -ti :8080 | xargs kill
(cd backend && php think swoole)
```

## 检查后端 HTTP

```bash
curl -I http://127.0.0.1:8080/
```
