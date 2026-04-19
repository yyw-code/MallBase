# 项目中的两份 `.env` 文件

MallBase 在项目根目录和 `backend/` 各有一份 `.env`，**两份独立、各管各**，不要合并也不要互相替代。

> 所有命令的"执行目录"都写在注释里，例如 `# 在项目根目录执行` 或 `# 在 backend 目录执行`。不要复制时忽略它们。

## 一、为什么要两份

| 这份 `.env` | 谁在读 | 什么时候读 | 模板 |
|-------------|--------|-------------|------|
| **项目根目录 `.env`** | docker compose | 解析 `docker-compose*.yml` 做 `${VAR}` 变量插值时 | `deploy/docker/.example.env` |
| **`backend/.env`** | ThinkPHP 运行时 | Swoole 服务启动、每次 `env()` 调用 | `backend/.example.env` |

docker compose 的变量插值**只认项目根目录的 `.env`**，无法改路径。而 ThinkPHP 的 `env()` 函数**只认 `backend/.env`**。两者机制不同、时机不同、字段也基本不重叠，所以分开维护。

## 二、字段对照表

### 根目录 `.env`（~7 个字段）

模板位置：`deploy/docker/.example.env`

| 字段 | 用途 | 备注 |
|------|------|------|
| `SWOOLE_HTTP_PORT` | 宿主 ↔ 容器双向映射（compose 写 `${VAR}:${VAR}`） | 必须与 `backend/.env` 的同名字段一致 |
| `MYSQL_PORT` | **仅**宿主侧暴露端口（compose 写 `${VAR}:3306`） | 容器内端口固定 3306，backend 通过服务名 `mysql:3306` 连接 |
| `REDIS_PORT` | **仅**宿主侧暴露端口（compose 写 `${VAR}:6379`） | 容器内端口固定 6379，backend 通过服务名 `redis:6379` 连接 |
| `MYSQL_ROOT_PASSWORD` | MySQL root 密码（首次初始化 + healthcheck） | 仅**首次**容器初始化有效，之后改值无法覆盖已创建的用户 |
| `DB_NAME` | 首次初始化创建的业务库名（`MYSQL_DATABASE`） | |
| `DB_USER` | 首次初始化创建的业务用户（`MYSQL_USER`） | 必须与 `backend/.env` 同名字段一致 |
| `DB_PASS` | 首次初始化创建的业务用户密码（`MYSQL_PASSWORD`） | 必须与 `backend/.env` 同名字段一致 |

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

**⚠️ 容易混淆的点**：`backend/.env` 里的 `DB_PORT` / `REDIS_PORT` 都是**容器内端口**（3306 / 6379 固定不变），**与根 `.env` 里同名字段语义不同**。想改宿主暴露端口，**只改根 `.env`**。

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
- 如果 `backend/.env` 不存在：从 `backend/.example.env` 复制，并随机化 `DB_PASS` / `JWT_SECRET`
- 如果项目根目录 `.env` 不存在：从 `deploy/docker/.example.env` 复制，并随机化 `MYSQL_ROOT_PASSWORD`；`DB_PASS` 复用 `backend/.env` 的值
- 最后以根 `.env` 为主，同步 `DB_NAME` / `DB_USER` / `DB_PASS` 回写到 `backend/.env`
- 如果两份都存在：只补齐缺失字段，不覆盖已有值

**查看随机生成的密码：**
```bash
# 在项目根目录执行：DB_PASS / JWT_SECRET 在 backend/.env
grep -E '^(DB_PASS|JWT_SECRET)=' backend/.env
```
```bash
# 在项目根目录执行：MYSQL_ROOT_PASSWORD 只在根 .env
grep '^MYSQL_ROOT_PASSWORD=' .env
```

### 方式 B：手动自定义（指定端口 / 密码）

适合要改宿主暴露端口、用自己的密码、或在生产前审计配置值。

```bash
# 在项目根目录执行
cp deploy/docker/.example.env .env
```
```bash
# 在项目根目录执行：编辑端口、密码、数据库名
# 用你习惯的编辑器打开 .env 修改
```
```bash
# 在项目根目录执行
cp backend/.example.env backend/.env
```
```bash
# 在 backend 目录执行：修改敏感字段
cd backend
# 替换占位符 please-change-or-leave-for-random 为强随机值
# 重点字段：DB_PASS、JWT_SECRET
```
```bash
# 在项目根目录执行
cd ..
docker compose -f docker-compose.dev.yml up -d
```

**⚠️ 安全红线**：手动 `cp` 模板后，`ensure-env` 只补齐**缺失字段**，**不会覆盖**你已有的占位符值。`DB_PASS` / `JWT_SECRET` 如果仍是 `please-change-or-leave-for-random`，等于把密码写在仓库里。**必须手动替换。**

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

下面 4 个字段在两份 `.env` 里**必须完全相同**，否则 MySQL 初始化用一套、ThinkPHP 连另一套，安装向导一定在"测试数据库连接"这步失败：

- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `MYSQL_ROOT_PASSWORD`

`ensure-env` 会自动做这件事：以**根 `.env` 为主**，同步到 `backend/.env`。手动初始化时**自己对齐**。

`SWOOLE_HTTP_PORT` 也必须两份一致（容器绑定端口 ↔ 宿主端口映射）。

## 五、常见错误

### ❌ 安装向导"测试数据库连接"失败：`Access denied for user`
**原因**：`backend/.env` 的 `DB_PASS` 与 MySQL 容器初始化时用的值不一致。
**解决方案**：
1. 检查两份 `.env` 的 `DB_PASS` 是否相同
   ```bash
   # 在项目根目录执行
   grep '^DB_PASS=' .env backend/.env
   ```
2. 如果不一致：推倒重来（见下文），因为 MySQL 容器在首次启动时创建的用户密码**无法后期改动**

### ❌ `/install` 页面报 500
**原因**：已经安装过，`backend/install/install.lock` 存在，但前端旧版本无错误处理。
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
# 在项目根目录执行：停容器并删卷（⚠️ 会清空所有数据库数据）
docker compose -f docker-compose.dev.yml down -v
```
```bash
# 在项目根目录执行：清理本地目录
rm -rf data/
rm -rf backend/install/install.lock
rm -f backend/.env
rm -f .env
rm -rf backend/public/admin/*
```
```bash
# 在项目根目录执行：重新启动，ensure-env 会从模板生成两份 .env
docker compose -f docker-compose.dev.yml up -d
```

## 七、FAQ

**Q：我改了 `backend/.env` 的端口，没改根 `.env`，会怎样？**
A：容器内 Swoole 监听新端口，但宿主端口映射走旧端口，`curl` 连不上。**两份必须一起改**。

**Q：`ensure-env` 会覆盖我已设置的值吗？**
A：不会。它只补齐缺失字段，不触碰你已有的值。

**Q：为什么不做成一份 `.env`？**
A：docker compose 的变量插值**只读项目根目录 `.env`**（`.yml` 里没有可配路径的选项）。而 `backend/.env` 是 ThinkPHP 约定位置。两边合并需要软链或包装命令，权衡后选了两份独立，由 `ensure-env` 保持一致。

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
- `deploy/docker/.example.env`：**Docker 变量模板**，8 个字段，是 docker compose 插值用的
- `backend/.example.env`：**ThinkPHP 运行时模板**，40+ 字段，是应用业务配置
- 两份模板对应两份 `.env`，职责不重叠
