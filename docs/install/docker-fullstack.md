# 方式三：Docker 开发（全套）

适合本地一键启动后端、MySQL、Redis，并按需单独执行后台前端静态资源打包。

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

### 2. （可选）自定义端口、密码与站点域名兜底

如果不关心默认值，可以跳过本步；`ensure-env` 会在启动时自动生成根 `.env` 和派生 `backend/.env`。

如果需要自定义：

```bash
cp deploy/docker/.example.env .env
```

再编辑以下字段：

- `MALLBASE_COMPOSE_PROJECT_NAME`
- `MALLBASE_CONTAINER_PREFIX`
- `SWOOLE_HTTP_PORT`
- `MYSQL_PORT`
- `REDIS_HOST_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `MYSQL_ROOT_PASSWORD`
- `SITE_URL`

`.env` 与 `backend/.env` 的主从关系见 [env-files.md](./env-files.md)。

同一台服务器部署多套实例时，建议每套使用不同的 Compose 名称、容器名前缀和宿主机端口。例如演示站：

```env
MALLBASE_COMPOSE_PROJECT_NAME=mallbase-demo
MALLBASE_CONTAINER_PREFIX=mallbase-demo
SWOOLE_HTTP_PORT=18080
MYSQL_PORT=13306
REDIS_HOST_PORT=16379
DB_PORT=3306
REDIS_PORT=6379
SITE_URL=https://demo.example.com
```

这里有两组端口不要混用：

- `MYSQL_PORT` / `REDIS_HOST_PORT`：宿主机访问容器用的端口，例如 Navicat、DBeaver、`redis-cli` 从宿主机连接。
- `DB_PORT` / `REDIS_PORT`：backend 容器和安装流程连接 MySQL / Redis 的端口。Docker 全套模式下通常保持 `3306` / `6379`。

### 3. 启动开发运行时服务

```bash
docker compose -f docker-compose.dev.yml up -d
```

启动顺序是：

1. `ensure-env` 生成或补齐配置
2. `mysql` / `redis` 变为健康
3. `check-db-auth` 校验库账号
4. `backend` 启动 Swoole
5. 用户访问 `/install` 并确认执行统一安装主流程

### 4. 理解开发目录映射

方式三会把项目目录以开发模式挂到容器里：

| 宿主机路径 | 容器路径 | 读写 | 用途 |
|------------|----------|------|------|
| `./backend` | `/app` | 读写 | 后端代码、`vendor`、`runtime`、安装锁、上传文件和前端静态产物都在这里，宿主机和容器看到的是同一份文件。 |
| `./` | `/workspace` | 只读 | 让后端容器读取项目根 `.env`，用于启动时派生 `backend/.env` 和安装页默认值。 |
| `./.version` | `/.version` | 只读 | 给安装页和状态页展示当前版本信息。 |
| `./data/mysql` | `/var/lib/mysql` | 读写 | MySQL 数据目录，删除后等同于清空开发库。 |
| `./data/redis` | `/data` | 读写 | Redis 数据目录，删除后等同于清空开发 Redis 数据。 |

开发模式的 `/app` 是 bind mount，所以本地改后端文件，容器里会立即看到；但 PHP 代码在 Swoole 下常驻内存，安装完成或改运行时代码后仍建议重启 backend 容器。

### 5. 单独执行后台前端打包

```bash
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

### 6. 查看关键状态

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker ps
docker logs ${PREFIX}-check-db-auth
docker logs ${PREFIX}-frontend-build
ls backend/public/admin/index.html
```

期望结果：

- 常驻容器：`<prefix>-dev`、`<prefix>-mysql`、`<prefix>-redis`
- 一次性容器：`<prefix>-ensure-env`、`<prefix>-check-db-auth`、`<prefix>-frontend-build` 最终 `Exited (0)`
- `backend/public/admin/index.html` 存在

### 7. 打开安装向导并确认安装

浏览器打开：

```bash
http://localhost:8080/install
```

安装页会默认预填根 `.env` 中的数据库、Redis 和站点域名兜底值。管理员账号与是否导入 demo 统一在安装页确认后提交。安装流程会完成：

- 建库 / 建表
- 创建管理员账号
- 路由权限同步
- 设置菜单同步
- 地区数据导入
- 站点域名写入
- 生成 `backend/runtime/install/install.lock`

### 8. 登录后台

```bash
http://localhost:8080/admin/
```

默认账号：

- 用户名：以安装向导里提交的值为准
- 密码：以安装向导里提交的值为准

登录后可在个人资料页修改管理员密码。

### 9. 前端改动后重新打包

如果你改了后台前端代码，重新执行：

```bash
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

它会重新构建 `@vben/web-antd`，并把产物覆盖到 `backend/public/admin`。

### 10. 从宿主机连接 MySQL / Redis

```bash
mysql -h 127.0.0.1 -P 3306 -u <DB_USER> -p
redis-cli -h 127.0.0.1 -p 6379
```

真实端口、账号和密码以根 `.env` 为准：MySQL 看 `MYSQL_PORT`，Redis 看 `REDIS_HOST_PORT`。

## 完成后验证

```bash
grep -E '^(DB_PASS|MYSQL_ROOT_PASSWORD|SITE_URL)=' .env
grep -E '^(DB_PASS|JWT_SECRET|SITE_URL)=' backend/.env
curl -I http://127.0.0.1:8080/
ls backend/public/admin/index.html
```

## 常见下一步

- 查看 `.env` 机制与运行时托底字段：[env-files.md](./env-files.md)
- 看命令导航：[commands.md](./commands.md)
- 遇到首装时序或密码问题：[troubleshooting.md](./troubleshooting.md) 和 [issues/docker-fullstack-first-run.md](./issues/docker-fullstack-first-run.md)
