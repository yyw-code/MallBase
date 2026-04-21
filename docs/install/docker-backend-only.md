# 方式二：Docker 开发（仅后端容器）

适合本地开发，宿主机已提供 MySQL 与 Redis，只希望后端跑在 Docker 容器里的场景。

## 前提

- 已安装 Docker 与 Docker Compose
- 宿主机已有 MySQL 8.0+ 和 Redis 6.0+
- 你准备在宿主机运行前端 dev server，或仅需要后端接口

## 完整步骤

### 1. 生成环境变量

项目存在两份 `.env` 模板，职责不同：

- `deploy/docker/.example.env`：docker compose 插值用
- `backend/.example.env`：ThinkPHP 运行时配置

进入项目根目录：

```bash
cd /path/to/mall-base
```

零配置方式：可直接跳过复制步骤，首次启动时由 `ensure-env` 自动生成两份 `.env`。

如果要自定义端口、密码或数据库名，手动准备：

```bash
cp deploy/docker/.example.env .env
cp backend/.example.env backend/.env
```

然后编辑 `backend/.env`，把以下主机改成宿主机地址：

- `DB_HOST=host.docker.internal`（Docker Desktop）或宿主机实际 IP
- `REDIS_HOST=host.docker.internal`（Docker Desktop）或宿主机实际 IP

### 2. 启动后端容器

```bash
docker compose -f docker-compose.dev.yml up -d --no-deps backend
```

这里必须带 `--no-deps`，否则 Compose 会顺带把方式三的 MySQL、Redis、`install-auto` 一并拉起。

### 3. 初始化 PHP 依赖

开发模式下，PHP 依赖会直接写回宿主机 `backend/vendor`，方便编辑器跳转：

```bash
docker exec mallbase-dev composer install
```

执行完成后，宿主机和容器都会看到同一份 `backend/vendor`。

### 4. 访问安装向导

浏览器打开：

```bash
http://localhost:8080/install
```

安装向导里的数据库和 Redis 地址，填写宿主机可达地址即可。

### 5. 重启后端容器

安装向导生成 `backend/.env` 后，需要重启容器：

```bash
docker compose -f docker-compose.dev.yml restart backend
```

### 6. 启动前端开发服务器

```bash
cd frontend/admin
pnpm install
pnpm run dev:antd
```

默认访问地址：

```bash
http://localhost:5666
```

如果你只想偶尔打一份后台静态资源，而不是长期跑 dev server，可参考命令集合里的 `frontend-build` 命令：[commands.md](./commands.md)。

## 完成后验证

```bash
docker ps
docker exec mallbase-dev php -v
ls backend/vendor
curl -I http://127.0.0.1:8080/
```

浏览器访问：

- `http://localhost:8080/install`
- `http://localhost:5666`

## 常见下一步

- 看完整命令集合：[commands.md](./commands.md)
- 遇到 Docker / MySQL / Redis 问题：[troubleshooting.md](./troubleshooting.md)
- 若以后切到 Docker 全套模式，再看 [env-files.md](./env-files.md)
