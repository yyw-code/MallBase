# 方式二：Docker 开发（仅后端容器）

适合本地开发，宿主机已提供 MySQL 与 Redis，只希望后端跑在 Docker 容器里的场景。

## 前提

- 已安装 Docker 与 Docker Compose
- 宿主机已有 MySQL 8.0+ 和 Redis 6.0+
- 你准备在宿主机运行前端 dev server，或仅需要后端接口

## 完整步骤

### 1. 确认是否需要根 `.env`

项目存在两份配置模板，职责不同：

- `deploy/docker/.example.env`：项目根 `.env` 模板，供 Docker Compose 做端口、容器名等变量插值。
- `backend/.example.env`：后端运行配置模板；Docker 开发生成到 `backend/.mallbase-env/backend.env`。

进入项目根目录：

```bash
cd /path/to/mall-base
```

方式二最终只启动 backend 服务：

```bash
docker compose -f docker-compose.dev.yml up -d --no-deps backend
```

由于这里显式带了 `--no-deps`，依赖服务不会自动启动。不要直接只执行这一条；启动 backend 前需要按第 2 步单独运行 `prepare-data-dirs` 和 `ensure-env` 两个一次性容器，它们不会启动 MySQL 或 Redis。

默认端口、单套本地环境下，可以不准备根 `.env`，直接进入第 2 步启动后端容器。此时 Compose 会使用默认值：

- Compose 项目名：`mallbase`
- 容器名前缀：`mallbase`
- 后端端口：`8080`

需要以下场景时，再准备项目根 `.env`：

- 同一台机器上跑多套 MallBase，避免容器名冲突。
- 要把后端端口从 `8080` 改成其他端口。
- 希望安装向导打开时预填数据库、Redis 或站点地址。

可选生成命令：

```bash
cp deploy/docker/.example.env .env
```

如果要自定义，多数情况下优先改这几个字段：

- `MALLBASE_COMPOSE_PROJECT_NAME`：Compose 项目名，建议与当前副本用途一致，例如 `mallbase-dev2`。
- `MALLBASE_CONTAINER_PREFIX`：显式容器名前缀；方式二使用了 `container_name`，多套本地环境时尤其要改它，例如 `mallbase-dev2`。
- `SWOOLE_HTTP_PORT`：后端容器对外端口。改成 `18080` 时，浏览器访问 `http://localhost:18080`。
- `SITE_URL`：站点地址兜底值；如果改了端口，请同步成对应地址，例如 `http://localhost:18080`。
- `DB_PORT`：backend 容器连接宿主机 MySQL 的实际端口，默认 `3306`。
- `REDIS_PORT`：backend 容器连接宿主机 Redis 的实际端口，默认 `6379`。

`MYSQL_PORT` / `REDIS_HOST_PORT` 是方式三 MySQL / Redis 容器给宿主机暴露端口时用的变量。方式二不启动 MySQL / Redis 容器，所以一般不需要改它们。

`DB_HOST` 和 `REDIS_HOST` 不是启动后端容器的必填项。走 Web 安装向导时，可以在安装表单里填写：

- Docker Desktop：`host.docker.internal`
- Linux Docker Engine：宿主机网关 IP 或宿主机实际内网 IP

不要手动复制或编辑 `backend/.mallbase-env/backend.env`。`ensure-env` 会根据根 `.env` 派生；安装向导完成后会按表单内容重新写入该运行配置。

如果仓库里已经存在旧的 `backend/.env`，`ensure-env` 会将它复制到新路径作为迁移输入，并保留旧文件供宿主机直跑。重新首装、确定不需要旧配置时，再使用清理脚本统一清理：

```bash
sh deploy/docker/cleanup-dev.sh --basic
```

### 2. 启动后端容器

```bash
docker compose -f docker-compose.dev.yml run --rm --no-deps prepare-data-dirs
docker compose -f docker-compose.dev.yml run --rm --no-deps ensure-env
docker compose -f docker-compose.dev.yml up -d --no-deps backend
```

第一条命令会创建并交接 `backend/.mallbase-env`、`backend/runtime`、上传目录和演示素材目录权限；第二条命令生成根 `.env` 和 Docker 运行配置。这样即使代码刚从 Git 拉取，backend 也不会因为宿主机目录属于其他 UID 而无法写入。

这里必须带 `--no-deps`，否则 Compose 会顺带把方式三的 `ensure-env`、MySQL、Redis 一并拉起。

### 3. 理解目录映射

方式二虽然只启动 backend 容器，但仍然使用开发目录映射：

| 宿主机路径 | 容器路径 | 读写 | 用途 |
|------------|----------|------|------|
| `./backend` | `/app` | 读写 | 后端代码、PHP 依赖、`backend/.mallbase-env/backend.env`、运行时文件、安装锁和上传文件都在这里；宿主机和容器看到的是同一份。 |
| `./.version` | `/.version` | 只读 | 给安装页和状态页展示当前版本信息。 |

方式二没有 `./data/mysql` 和 `./data/redis` 映射，因为 MySQL / Redis 由宿主机或外部服务提供。

开发模式下 `/app` 是 bind mount，本地改代码后容器能看到文件变化；但 Swoole 会常驻加载 PHP 代码，安装完成或改后端代码后建议重启 backend 容器。

### 4. 首次启动时等待自动安装 PHP 依赖

开发模式下，PHP 依赖会直接写回宿主机 `backend/vendor`，方便编辑器跳转。

首次启动时，如果宿主机 `backend/vendor` 还不存在，容器入口脚本会自动执行一次 `composer install`。

这一步会让首次启动时间明显变长，日志里看到 `composer install` 输出是正常现象。完成后，宿主机和容器都会看到同一份 `backend/vendor`。

这里是 Docker 开发模式，入口脚本故意使用普通 `composer install`：

- 保留 `require-dev` 依赖，方便本地跑测试和调试。
- 不默认加 `--optimize-autoloader`，避免日常新增或移动类后频繁重建自动加载映射。

如果你想在启动前手动先装好依赖，也可以执行：

```bash
docker compose -f docker-compose.dev.yml run --rm --no-deps backend composer install
```

### 5. 访问安装向导

浏览器打开：

```bash
http://localhost:8080/install
```

安装向导里的数据库和 Redis 地址，填写宿主机可达地址即可：

- Docker Desktop：`host.docker.internal`
- Linux Docker Engine：宿主机网关 IP 或宿主机实际内网 IP

如果没有准备根 `.env`，安装表单可能显示模板默认的 `mysql` / `redis`；方式二连接的是宿主机 MySQL / Redis，请在表单里改成上面的宿主机可达地址。

如果准备了根 `.env`，安装表单会优先使用其中的数据库和 Redis 地址。如果表单里仍显示旧值，先检查 `backend/.mallbase-env/backend.env` 的派生结果和浏览器缓存；需要彻底重测首装时按第 1 步执行清理脚本，再重新运行两个一次性容器。

安装流程完成后会自动执行：

- 路由权限同步
- 设置菜单权限同步
- 地区数据导入

### 6. 重启后端容器

安装向导更新 `backend/.mallbase-env/backend.env` 后，需要重启容器：

```bash
docker compose -f docker-compose.dev.yml restart backend
```

### 7. 启动前端开发服务器

```bash
cd frontend/admin
pnpm install
pnpm run dev:antd
```

默认访问地址：

```bash
http://localhost:5666
```

如果你只想偶尔打一份后台静态资源，而不是长期跑 dev server，可参考前端命令分册里的 `frontend-build` 命令：[commands-frontend.md](./commands-frontend.md)。

## 完成后验证

```bash
docker ps
ls backend/vendor
curl -I http://127.0.0.1:8080/
```

如果容器已经正常起来，再补充检查：

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker exec ${PREFIX}-dev php -v
```

浏览器访问：

- `http://localhost:8080/install`
- `http://localhost:5666`

## 常见下一步

- 看命令导航：[commands.md](./commands.md)
- 遇到 Docker / MySQL / Redis 问题：[troubleshooting.md](./troubleshooting.md)
- 若以后切到 Docker 全套模式，再看 [env-files.md](./env-files.md)
