# 项目中的两份 `.env` 文件

MallBase 在文件结构上仍然有两份 `.env`，但在 **方式二：Docker 开发（仅后端）**、**方式三：Docker 开发（全套）** 和 **本地命令行安装 `install:auto`** 下，推荐记住一句话：

- **项目根目录 `.env` 是唯一主配置源**
- **`backend/.env` 是派生 / 运行时文件，由 ensure-env 或安装流程写入**

也就是说，Docker 开发模式和本地命令行安装下，用户优先维护根 `.env`；`backend/.env` 不再作为第二份手工配置入口。

> 所有命令的"执行目录"都写在注释里，例如 `# 在项目根目录执行` 或 `# 在 backend 目录执行`。不要复制时忽略它们。

## 一、为什么要两份

| 这份 `.env` | 谁在读 | 什么时候读 | 模板 |
|-------------|--------|-------------|------|
| **项目根目录 `.env`** | docker compose | 解析 `docker-compose*.yml` 做 `${VAR}` 变量插值时 | `deploy/docker/.example.env` |
| **`backend/.env`** | ThinkPHP 运行时 | Swoole 服务启动、每次 `env()` 调用 | `backend/.example.env` |

docker compose 的变量插值**只认项目根目录的 `.env`**，无法改路径。而 ThinkPHP 的 `env()` 函数**只认 `backend/.env`**。两者机制不同，所以文件仍然分开；但在 Docker 开发模式里，我们约定由 `ensure-env` 或后端容器入口脚本负责把根 `.env` 派生为 `backend/.env`，避免用户手工维护两份导致不一致。

本地命令行安装 `install:auto` 不通过 Docker；命令会读取项目根 `.env` 作为安装输入，并在安装完成后把生效配置写入 `backend/.env`。

## 二、字段对照表

### 根目录 `.env`（Docker 开发全套模式唯一主配置源）

模板位置：`deploy/docker/.example.env`

| 字段 | 用途 | 备注 |
|------|------|------|
| `SWOOLE_HTTP_PORT` | 宿主 ↔ 容器双向映射（compose 写 `${VAR}:${VAR}`） | 必须与 `backend/.env` 的同名字段一致 |
| `MYSQL_PORT` | **仅**宿主侧暴露端口（compose 写 `${VAR}:3306`） | 容器内端口固定 3306，backend 通过服务名 `mysql:3306` 连接 |
| `REDIS_HOST_PORT` | **仅**宿主侧暴露端口（compose 写 `${VAR}:6379`） | 容器内端口固定 6379，backend 通过服务名 `redis:6379` 连接 |
| `MYSQL_ROOT_PASSWORD` | MySQL root 密码（首次初始化 + healthcheck） | 仅**首次**容器初始化有效，之后改值无法覆盖已创建的用户 |
| `DB_HOST` | ThinkPHP / `install:auto` 连接数据库的主机 | Docker 全套模式默认 `mysql`；本地命令行安装通常改为 `127.0.0.1` |
| `DB_PORT` | ThinkPHP / `install:auto` 连接数据库的端口 | Docker 全套模式容器内固定 `3306`；本地命令行安装按实际端口填写 |
| `DB_NAME` | 首次初始化创建的业务库名（`MYSQL_DATABASE`） | |
| `DB_USER` | 首次初始化创建的业务用户（`MYSQL_USER`） | 必须与 `backend/.env` 同名字段一致 |
| `DB_PASS` | 首次初始化创建的业务用户密码（`MYSQL_PASSWORD`） | 也会同步到 `backend/.env` |
| `REDIS_HOST` | ThinkPHP / `install:auto` 连接 Redis 的主机 | Docker 全套模式默认 `redis`；本地命令行安装通常改为 `127.0.0.1` |
| `REDIS_PORT` | ThinkPHP / `install:auto` 连接 Redis 的端口 | Docker 全套模式容器内固定 `6379`；本地命令行安装按实际端口填写 |
| `REDIS_CACHE_DB` | Redis 缓存 DB | CLI 安装要求该 DB 为空 |
| `REDIS_PASSWORD` | Redis 密码 | 无密码时留空 |
| `CACHE_DRIVER` | 缓存驱动 | 默认 `redis` |
| `SITE_URL` | 安装页默认站点域名、CLI 安装输入、安装完成后的静态副本 | 安装完成后权威值仍以 `mb_setting.site_url` 为准 |

如果为了测试副本把 `SWOOLE_HTTP_PORT` 改成了非默认值，例如 `18080`，请同步把 `SITE_URL` 改成对应访问地址，例如 `http://localhost:18080`。CLI 安装没有浏览器 request 上下文，`SITE_URL` 不应继续保留为旧端口。

### `backend/.env`（~40+ 字段）

模板位置：`backend/.example.env`

覆盖 ThinkPHP 所有运行时配置：

- 应用：`APP_DEBUG` / `DEFAULT_LANG` / `CRON_ENABLE`
- 队列：`QUEUE_CONNECTION` / `QUEUE_REDIS_*` / `SWOOLE_QUEUE_ENABLE`
- 数据库：`DB_TYPE` / `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` / `DB_CHARSET` / `DB_PREFIX`
- Redis：`REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` / `REDIS_TIMEOUT` / `REDIS_PERSISTENT` / `REDIS_CACHE_DB`
- 缓存：`CACHE_*`
- JWT：`JWT_SECRET` / `JWT_EXPIRE` / `JWT_REFRESH_EXPIRE`
- Swoole：`SWOOLE_HTTP_PORT` / `SWOOLE_*`
- 站点域名静态副本：`SITE_URL`

订单定时任务使用 Swoole Cron 投递队列 Job：

- Web 安装向导的高级选项会写入 `CRON_ENABLE` / `SWOOLE_QUEUE_ENABLE`，默认关闭，避免安装前任务写入 Redis
- 单机部署：安装时可勾选定时任务和 Swoole 内置队列 Worker；安装完成后需重启 Swoole 生效
- K8s / 多副本部署：Web Deployment 保持 `CRON_ENABLE=false`；Scheduler Deployment 副本数固定 `1` 且设置 `CRON_ENABLE=true`；Queue Worker Deployment 运行 `php think queue:work redis --queue=default --tries=3`，可按压力水平扩容
- 未生成 `backend/runtime/install/install.lock` 前，即使 env 中误设为 `true`，Cron 和 Swoole 内置队列 Worker 也不会启动。

**⚠️ 容易混淆的点**：

- `MYSQL_PORT` / `REDIS_HOST_PORT` 是宿主机连接容器用的映射端口，只给 Docker Compose 端口映射使用。
- `DB_PORT` / `REDIS_PORT` 是应用连接数据库和 Redis 的端口，会同步到 `backend/.env`；Docker 全套模式通常保持 `3306` / `6379`。
- 例如同一台机器跑多套实例时，可以写 `MYSQL_PORT=13306`、`REDIS_HOST_PORT=16379`，但 `DB_PORT=3306`、`REDIS_PORT=6379` 通常不要改。
- Docker 开发全套模式下，`backend/.env` 文件头会明确写出“请改根 `.env`，不要改 `backend/.env`”

## 三、两种初始化方式

### 方式 A：零配置（自动生成配置）

适合第一次试用，接受随机生成的密码和默认端口（8080 / 3306 / 6379）。

```bash
# 在项目根目录执行（如 /Users/you/code/mall-base）
cd /path/to/mall-base
```
```bash
# 在项目根目录执行
docker compose -f docker-compose.dev.yml up -d
```

**会发生什么：**
- `ensure-env` init 容器启动并运行 `deploy/docker/ensure-env.sh`
- 如果项目根目录 `.env` 不存在：从 `deploy/docker/.example.env` 复制生成
- 若根 `.env` 中的 `DB_PASS` / `MYSQL_ROOT_PASSWORD` 仍是占位符，则自动随机化一次
- 之后根据根 `.env` 重新派生 `backend/.env`
- `backend/.env` 会写入中文头注释，明确它是自动生成文件
- `backend`、`mysql`、`redis` 启动后，用户访问 `/install` 并确认执行安装流程

**查看自动生成 / 当前生效的关键值：**
```bash
# 在项目根目录执行：Docker 主配置
grep -E '^(DB_PASS|MYSQL_ROOT_PASSWORD|SITE_URL)=' .env
```
```bash
# 在项目根目录执行：派生后的 TP 运行时配置
grep -E '^(DB_PASS|JWT_SECRET|SITE_URL)=' backend/.env
```

### 方式 B：手动自定义（指定端口 / 密码）

适合要改宿主暴露端口、用自己的密码、或在生产前审计配置值。

```bash
# 在项目根目录执行
cp deploy/docker/.example.env .env
```
```bash
# 在项目根目录执行：编辑端口、密码、数据库名与站点域名兜底
# 用你习惯的编辑器打开 .env 修改
```
```bash
# 在项目根目录执行
docker compose -f docker-compose.dev.yml up -d
```

**⚠️ 安全红线**：手动 `cp` 模板后，如果你保留了根 `.env` 里的敏感占位符，`ensure-env` 会在首次运行时自动随机化 `DB_PASS` / `MYSQL_ROOT_PASSWORD`；但 `JWT_SECRET` 仍来自派生的 `backend/.env`，首次派生时也会自动生成。不要把占位符原样带到长期运行环境。

**生成强随机值：**
```bash
# 在任意目录执行
openssl rand -hex 16
# 16 字节 = 32 位十六进制，适合 DB_PASS / MYSQL_ROOT_PASSWORD
```
```bash
# 在任意目录执行
openssl rand -hex 32
# 32 字节 = 64 位十六进制，适合 JWT_SECRET
```

## 四、字段对齐清单（共享字段）

下面这些字段在 Docker 开发全套模式下，都会由根 `.env` 自动同步到 `backend/.env`：

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `REDIS_HOST`
- `REDIS_PORT`
- `REDIS_CACHE_DB`
- `REDIS_PASSWORD`
- `CACHE_DRIVER`
- `SWOOLE_HTTP_PORT`
- `SITE_URL`

`MYSQL_PORT` / `REDIS_HOST_PORT` / `MYSQL_ROOT_PASSWORD` 只存在于根 `.env`，不会写进 `backend/.env`。`CRON_ENABLE` / `SWOOLE_QUEUE_ENABLE` 不再由根 `.env` 同步，统一由安装向导高级选项写入。

## 五、常见错误

### ❌ 安装向导或 `install:auto` 报 `Access denied for user`
**原因**：`data/mysql` 是旧数据，而根 `.env` 的 `DB_PASS` 后来被改过；MySQL 容器里的真实业务账号密码并不会自动跟着变。
**解决方案**：
1. 先检查当前根 `.env` 里的目标密码
   ```bash
   # 在项目根目录执行
   grep '^DB_PASS=' .env
   ```
2. 如果要**保留现有数据**：执行显式轮换命令
   ```bash
   # 在项目根目录执行
   docker compose -f docker-compose.dev.yml --profile tools up rotate-db-password
   ```
3. 如果**不要现有数据**：按下文“完全清零重来”执行

### ❌ `/install` 页面报 500
**原因**：已经安装过，`backend/runtime/install/install.lock` 存在，但前端旧版本无错误处理。
**解决**：页面应自动显示"系统已安装"卡片（新版本已修复）。强制刷新（Cmd+Shift+R / Ctrl+F5）清缓存。

### ❌ 改了端口后 `curl http://localhost:9999/` 连不上
**原因**：只改了一份 `.env`，或未重建容器。
**解决**：
1. 两份同时改：
   ```bash
   # 在项目根目录执行
   grep '^SWOOLE_HTTP_PORT=' .env backend/.env
   ```
2. 重建容器（compose 变量插值只在 parse 时发生，无法热加载）：
   ```bash
   # 在项目根目录执行
   docker compose -f docker-compose.dev.yml down
   ```
   ```bash
   # 在项目根目录执行
   docker compose -f docker-compose.dev.yml up -d
   ```

## 六、完全清零重来

如果配置状态混乱，想回到全新状态：

```bash
# 在项目根目录执行：推荐，清理 Docker 开发全套状态和本地数据
sh deploy/docker/cleanup-dev.sh --docker
```

如果你想手动分步执行，也可以继续用下面这些命令：

```bash
# 在项目根目录执行：停容器并删卷（⚠️ 会清空所有数据库数据）
docker compose -f docker-compose.dev.yml down -v
```
```bash
# 在项目根目录执行：清理本地目录
rm -rf data/
rm -rf backend/runtime/install/install.lock
rm -f backend/.env
rm -f .env
rm -rf backend/public/admin
rm -rf frontend/admin/apps/web-antd/dist
```
```bash
# 在项目根目录执行：重新启动，ensure-env 会从模板生成两份 .env
docker compose -f docker-compose.dev.yml up -d
```

## 七、FAQ

**Q：我改了 `backend/.env` 的端口，没改根 `.env`，会怎样？**
A：容器内 Swoole 监听新端口，但宿主端口映射走旧端口，`curl` 连不上。**两份必须一起改**。

**Q：`ensure-env` 会覆盖我已设置的值吗？**
A：对根 `.env`，不会覆盖你已设置的非占位符值；对 `backend/.env`，会按根 `.env` 重新派生共享字段，因为它本来就是派生文件。

**Q：为什么不做成一份 `.env`？**
A：docker compose 的变量插值**只读项目根目录 `.env`**，而 ThinkPHP 的 `env()` 又约定读取 `backend/.env`。两边不能直接合并，所以保留两份文件；但在 Docker 开发全套模式里，用户只需要维护根 `.env`，`backend/.env` 交给 `ensure-env` 自动派生。

**Q：修改了端口后如何生效？**
A：
```bash
# 在项目根目录执行
docker compose -f docker-compose.dev.yml down
```
```bash
# 在项目根目录执行
docker compose -f docker-compose.dev.yml up -d
```
docker compose 变量插值只在 parse yml 时发生，不能热加载。

**Q：生产环境怎么办？**
A：生产用 `docker-compose.yml`（单后端容器），只维护项目根目录 `.env`。根 `.env` 必须存在，Compose 会用它做变量插值，并通过 `env_file: .env` 注入后端容器；容器入口脚本再派生 `/app/.env`。数据库、Redis 和站点域名可以在 Web 安装向导里填写，但安装完成后建议把最终生效值同步回根 `.env`，避免容器重新创建后配置回退。不要把 `backend/.env` 当成生产手工配置入口。

**Q：`deploy/docker/.example.env` 和 `backend/.example.env` 有啥区别？**
A：
- `deploy/docker/.example.env`：**Docker 变量模板**，现在包含端口、数据库和少量运行时兜底字段
- `backend/.example.env`：**ThinkPHP 运行时模板**，40+ 字段，是应用业务配置
- 两份模板对应两份 `.env`，职责不重叠

## 八、安装与站点域名静态副本

以下字段仍保留在模板里，但定位已经收敛为”安装期输入或安装完成后的静态副本”，不是第二套主配置源：

| 变量 | 默认值 | 作用 |
|------|--------|------|
| `SITE_URL` | 空 / `http://localhost:8080`（根 `.env` 模板） | 安装页默认站点域名、CLI 无人值守安装输入；安装完成后写回此字段作为静态副本，便于运维直接查看 |

> 当前 demo 商品规格数据已统一为现行结构：`name + add_pic + values[{value, pic}]`。已安装的旧 demo 数据不做自动兼容；若本地环境仍保留旧结构，请清库后重新安装 demo 数据。

> CORS 策略不通过环境变量配置。`backend/app/middleware/CorsMiddleware.php` 默认反射请求 `Origin` 头并允许 Credentials；如需收紧成白名单，请直接改该中间件文件。

**这些字段的配置位置**：Docker 开发全套模式下，请直接写到**项目根目录 `.env`**。`ensure-env` 会自动把共享字段同步到 `backend/.env`。最简做法：

```bash
# 在项目根目录执行：首次启动前追加到根 .env
echo 'SITE_URL=https://mall.example.com' >> .env
```
```bash
# 在项目根目录执行
docker compose -f docker-compose.dev.yml up -d
```

```bash
# 浏览器打开安装向导
http://localhost:8080/install
```

> 这些字段不是数据库主配置源。安装完成后，站点域名仍以 `mb_setting.site_url` 为准；如果需要永久调整上传驱动、上传规则或业务设置，请优先走后台设置页面，而不是把根 `.env` 当成第二套业务配置入口。
