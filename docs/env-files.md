# 项目中的两份 `.env` 文件

MallBase 在文件结构上仍然有两份 `.env`，但在 **方式三：Docker 开发（全套）** 下，推荐记住一句话：

- **项目根目录 `.env` 是唯一主配置源**
- **`backend/.env` 是派生文件，由 ensure-env 自动生成**

也就是说，Docker 开发全套模式下，用户只需要维护根 `.env`；`backend/.env` 不再作为第二份手工配置入口。

> 所有命令的"执行目录"都写在注释里，例如 `# 在项目根目录执行` 或 `# 在 backend 目录执行`。不要复制时忽略它们。

## 一、为什么要两份

| 这份 `.env` | 谁在读 | 什么时候读 | 模板 |
|-------------|--------|-------------|------|
| **项目根目录 `.env`** | docker compose | 解析 `docker-compose*.yml` 做 `${VAR}` 变量插值时 | `deploy/docker/.example.env` |
| **`backend/.env`** | ThinkPHP 运行时 | Swoole 服务启动、每次 `env()` 调用 | `backend/.example.env` |

docker compose 的变量插值**只认项目根目录的 `.env`**，无法改路径。而 ThinkPHP 的 `env()` 函数**只认 `backend/.env`**。两者机制不同，所以文件仍然分开；但在 Docker 开发全套模式里，我们约定由 `ensure-env` 负责把根 `.env` 派生为 `backend/.env`，避免用户手工维护两份导致不一致。

## 二、字段对照表

### 根目录 `.env`（Docker 开发全套模式唯一主配置源）

模板位置：`deploy/docker/.example.env`

| 字段 | 用途 | 备注 |
|------|------|------|
| `SWOOLE_HTTP_PORT` | 宿主 ↔ 容器双向映射（compose 写 `${VAR}:${VAR}`） | 必须与 `backend/.env` 的同名字段一致 |
| `MYSQL_PORT` | **仅**宿主侧暴露端口（compose 写 `${VAR}:3306`） | 容器内端口固定 3306，backend 通过服务名 `mysql:3306` 连接 |
| `REDIS_PORT` | **仅**宿主侧暴露端口（compose 写 `${VAR}:6379`） | 容器内端口固定 6379，backend 通过服务名 `redis:6379` 连接 |
| `MYSQL_ROOT_PASSWORD` | MySQL root 密码（首次初始化 + healthcheck） | 仅**首次**容器初始化有效，之后改值无法覆盖已创建的用户 |
| `DB_NAME` | 首次初始化创建的业务库名（`MYSQL_DATABASE`） | |
| `DB_USER` | 首次初始化创建的业务用户（`MYSQL_USER`） | 必须与 `backend/.env` 同名字段一致 |
| `DB_PASS` | 首次初始化创建的业务用户密码（`MYSQL_PASSWORD`） | 也会同步到 `backend/.env` |
| `ADMIN_USER` | 零向导首次安装的超管用户名 | 仅首次 `install:auto` 生效 |
| `ADMIN_PASS` | 零向导首次安装的超管初始密码 | 仅首次 `install:auto` 生效 |
| `INSTALL_DEMO` | 是否自动导入 demo 数据 | `1` 导入，`0` 不导入 |

### `backend/.env`（~40+ 字段）

模板位置：`backend/.example.env`

覆盖 ThinkPHP 所有运行时配置：

- 应用：`APP_DEBUG` / `DEFAULT_LANG` / `CRON_ENABLE`
- 数据库：`DB_TYPE` / `DB_HOST` / `DB_PORT`（固定 3306，容器内端口）/ `DB_NAME` / `DB_USER` / `DB_PASS` / `DB_CHARSET` / `DB_PREFIX`
- Redis：`REDIS_HOST` / `REDIS_PORT`（固定 6379，容器内端口）/ `REDIS_PASSWORD` / `REDIS_TIMEOUT` / `REDIS_PERSISTENT` / `REDIS_CACHE_DB`
- 缓存：`CACHE_*`
- JWT：`JWT_SECRET` / `JWT_EXPIRE` / `JWT_REFRESH_EXPIRE`
- Swoole：`SWOOLE_HTTP_PORT` / `SWOOLE_*`
- 跨域 / 日志：`CORS_*` / `LOG_*`
- 零向导安装：`ADMIN_USER` / `ADMIN_PASS` / `INSTALL_DEMO`

**⚠️ 容易混淆的点**：

- `backend/.env` 里的 `DB_PORT` / `REDIS_PORT` 都是**容器内端口**（3306 / 6379 固定不变），与根 `.env` 里的宿主暴露端口语义不同
- Docker 开发全套模式下，`backend/.env` 文件头会明确写出“请改根 `.env`，不要改 `backend/.env`”

## 三、两种初始化方式

### 方式 A：零配置（完全自动）

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

**查看自动生成 / 当前生效的关键值：**
```bash
# 在项目根目录执行：Docker 主配置
grep -E '^(DB_PASS|MYSQL_ROOT_PASSWORD|ADMIN_USER|ADMIN_PASS|INSTALL_DEMO)=' .env
```
```bash
# 在项目根目录执行：派生后的 TP 运行时配置
grep -E '^(DB_PASS|JWT_SECRET|ADMIN_USER|ADMIN_PASS|INSTALL_DEMO)=' backend/.env
```

### 方式 B：手动自定义（指定端口 / 密码）

适合要改宿主暴露端口、用自己的密码、或在生产前审计配置值。

```bash
# 在项目根目录执行
cp deploy/docker/.example.env .env
```
```bash
# 在项目根目录执行：编辑端口、密码、数据库名、零向导超管参数
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

## 四、字段对齐清单（凭据三件套）

下面这些字段在 Docker 开发全套模式下，都会由根 `.env` 自动同步到 `backend/.env`：

- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `SWOOLE_HTTP_PORT`
- `ADMIN_USER`
- `ADMIN_PASS`
- `INSTALL_DEMO`

`MYSQL_ROOT_PASSWORD` 只存在于根 `.env`，不会写进 `backend/.env`。

## 五、常见错误

### ❌ `install-auto` 或安装阶段报 `Access denied for user`
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
**原因**：已经安装过，`deploy/install/install.lock` 存在，但前端旧版本无错误处理。
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
# 在项目根目录执行：推荐，一键清理 Docker 开发全套模式产生的本地状态
sh deploy/docker/cleanup-dev.sh
```

如果你想手动分步执行，也可以继续用下面这些命令：

```bash
# 在项目根目录执行：停容器并删卷（⚠️ 会清空所有数据库数据）
docker compose -f docker-compose.dev.yml down -v
```
```bash
# 在项目根目录执行：清理本地目录
rm -rf data/
rm -rf deploy/install/install.lock
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
A：生产用 `docker-compose.yml`（单容器，镜像构建时 `COPY backend/.`，`/app/.env` 打进镜像）。发布前准备好生产版 `backend/.env`，无需项目根目录 `.env`。

**Q：`deploy/docker/.example.env` 和 `backend/.example.env` 有啥区别？**
A：
- `deploy/docker/.example.env`：**Docker 变量模板**，现在包含端口、数据库和零向导安装参数
- `backend/.example.env`：**ThinkPHP 运行时模板**，40+ 字段，是应用业务配置
- 两份模板对应两份 `.env`，职责不重叠

## 八、零向导环境变量（方式三专用）

方式三启动时由 `install-auto` 容器执行 `php think install:auto` 自动建库/建表/建超管，以下三个可选变量**覆盖默认值**，只在**首次安装**（`deploy/install/install.lock` 不存在时）生效，之后忽略：

| 变量 | 默认值 | 作用 |
|------|--------|------|
| `ADMIN_USER` | `admin` | 超管用户名 |
| `ADMIN_PASS` | `admin123` | 超管初始密码；登录后仍会被强制改密（`password_changed_at=NULL`） |
| `INSTALL_DEMO` | `0` | `1` 时自动导入 `deploy/install/data/demo/` 下的演示数据（示例商品/订单/会员） |

**三个变量的配置位置**：Docker 开发全套模式下，请直接写到**项目根目录 `.env`**。`ensure-env` 会自动把它们同步到 `backend/.env`，再由 `install-auto` 读取。最简做法：

```bash
# 在项目根目录执行：首次启动前追加到根 .env
cat >> .env <<'EOF'
ADMIN_USER=gosowong
ADMIN_PASS=MyStr0ngPass!
INSTALL_DEMO=1
EOF
```
```bash
# 在项目根目录执行
docker compose -f docker-compose.dev.yml --profile build up -d
```

> 想在已装环境更换这 3 个变量：这些变量仅首次安装有效。已装环境改账号走 `后台 → 管理员管理`，换密码走 `后台 → 个人资料 → 修改密码`，补导演示数据则手动执行 `docker exec -it mallbase-dev php think install:auto` 是没用的（`install.lock` 会让它直接跳过），需要先删 lock + 清库，走"完全清零重来"。

> 方式一 / 方式二 / 方式四 不走零向导——它们的 DB/Redis 连接必须用户手填，走浏览器 `/install` 向导，与这 3 个变量无关。
