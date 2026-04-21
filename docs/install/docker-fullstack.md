# 方式三：Docker 开发（全套）

适合本地一键启动后端、MySQL、Redis，并使用 `frontend-build` 自动把后台前端资源同步到 `backend/public/admin`。

## 前提

- 已安装 Docker Desktop（Mac / Windows）或 Docker Engine + Compose Plugin（Linux）
- `docker --version` 与 `docker compose version` 均可正常输出版本
- 允许项目根目录生成 `.env`、`data/`、`backend/public/admin` 等开发期文件

## 完整步骤

### 1. 进入项目根目录

```bash
cd /path/to/mall-base
pwd
```

### 2. （可选）自定义端口、密码、数据库名

如果不关心默认值，可以跳过本步；`ensure-env` 会在启动时自动生成根 `.env` 和派生 `backend/.env`。

如果需要自定义：

```bash
cp deploy/docker/.example.env .env
```

再编辑以下字段：

- `SWOOLE_HTTP_PORT`
- `MYSQL_PORT`
- `REDIS_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `MYSQL_ROOT_PASSWORD`
- `ADMIN_USER`
- `ADMIN_PASS`
- `INSTALL_DEMO`

`.env` 与 `backend/.env` 的主从关系见 [env-files.md](./env-files.md)。

### 3. 启动所有容器（含前端自动打包）

```bash
docker compose -f docker-compose.dev.yml --profile build up -d
```

启动顺序是：

1. `ensure-env` 生成或补齐配置
2. `mysql` / `redis` 变为健康
3. `check-db-auth` 校验库账号
4. `install-auto` 执行零向导安装
5. `backend` 启动 Swoole
6. `frontend-build` 自动构建并同步 `backend/public/admin`

### 4. 查看关键状态

```bash
docker ps
docker logs mallbase-install-auto
docker logs mallbase-frontend-build
ls backend/public/admin/index.html
```

期望结果：

- 常驻容器：`mallbase-dev`、`mallbase-mysql`、`mallbase-redis`
- 一次性容器：`mallbase-install-auto`、`mallbase-ensure-env`、`mallbase-frontend-build` 最终 `Exited (0)`
- `backend/public/admin/index.html` 存在

### 5. 登录后台（零向导）

方式三不需要浏览器安装向导，`install-auto` 已自动建库、建表、建超管。

浏览器打开：

```bash
http://localhost:8080/admin/
```

默认账号：

- 用户名：`admin`
- 密码：`admin123`

首次登录会强制进入改密页。

### 6. 前端改动后重新打包

如果你改了后台前端代码，重新执行：

```bash
docker compose -f docker-compose.dev.yml --profile build up frontend-build
```

它会重新构建 `@vben/web-antd`，并把产物覆盖到 `backend/public/admin`。

### 7. 从宿主机连接 MySQL / Redis

```bash
mysql -h 127.0.0.1 -P 3306 -u <DB_USER> -p
redis-cli -h 127.0.0.1 -p 6379
```

真实端口、账号和密码以根 `.env` 为准。

## 完成后验证

```bash
grep -E '^(DB_PASS|MYSQL_ROOT_PASSWORD|ADMIN_USER|ADMIN_PASS|INSTALL_DEMO)=' .env
grep -E '^(DB_PASS|JWT_SECRET|ADMIN_USER|ADMIN_PASS|INSTALL_DEMO)=' backend/.env
curl -I http://127.0.0.1:8080/
ls backend/public/admin/index.html
```

## 常见下一步

- 查看 `.env` 机制与零向导变量：[env-files.md](./env-files.md)
- 看常用命令集合：[commands.md](./commands.md)
- 遇到首装时序或密码问题：[troubleshooting.md](./troubleshooting.md) 和 [issues/docker-fullstack-first-run.md](./issues/docker-fullstack-first-run.md)
