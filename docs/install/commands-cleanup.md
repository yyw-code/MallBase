# 删除与清理命令

本页收录分级清理脚本和手动删除命令。完整脚本说明见 [cleanup-dev.md](./cleanup-dev.md)。

## 分级清理脚本

| 场景 | 适用安装方式 | 命令 |
|------|--------------|------|
| 基础清理：安装锁、根 `.env`、Docker 运行配置、旧 `backend/.env`、演示运行时素材 | 方式一、方式二、方式三、本地构建后的方式四 | `sh deploy/docker/cleanup-dev.sh` |
| 前端清理：基础清理 + Admin / UniApp 依赖、构建产物和发布产物 | 方式一、方式二、方式三、本地构建后的方式四 | `sh deploy/docker/cleanup-dev.sh --frontend` |
| Docker 状态清理：前端清理 + 开发容器、网络、卷、`data/mysql`、`data/redis`、`backend/vendor` | 方式二、方式三 | `sh deploy/docker/cleanup-dev.sh --docker` |
| 项目镜像清理：Docker 状态清理 + `mallbase-backend:dev` | 方式二、方式三 | `sh deploy/docker/cleanup-dev.sh --images` |
| 共享基础镜像清理：项目镜像清理 + MySQL / Redis / Node / Alpine 基础镜像 | 方式二、方式三 | `sh deploy/docker/cleanup-dev.sh --all-images` |

查看帮助：

```bash
sh deploy/docker/cleanup-dev.sh --help
```

## 基础清理

适用：方式一、方式二、方式三、本地构建后的方式四。

```bash
sh deploy/docker/cleanup-dev.sh
```

等同于：

```bash
sh deploy/docker/cleanup-dev.sh --basic
```

## 清理前端文件

适用：方式一、方式二、方式三、本地构建后的方式四。

```bash
sh deploy/docker/cleanup-dev.sh --frontend
```

## 清理 Docker 开发状态

适用：方式二、方式三。

```bash
sh deploy/docker/cleanup-dev.sh --docker
```

## 清理本项目构建镜像

适用：方式二、方式三。

```bash
sh deploy/docker/cleanup-dev.sh --images
```

## 连共享基础镜像一起清理

适用：方式二、方式三。会影响本机其他项目，不确定时不要使用。

```bash
sh deploy/docker/cleanup-dev.sh --all-images
```

## 手动停掉并删除开发容器与卷

适用：方式二、方式三。

```bash
docker compose -f docker-compose.dev.yml --profile tools down -v --remove-orphans
docker compose -f docker-compose.frontend-build.yml down -v --remove-orphans
docker compose -f docker-compose.uniapp-build.yml down -v --remove-orphans
```

## 手动清理基础生成文件

适用：方式一、方式二、方式三、本地构建后的方式四。

```bash
rm -f backend/runtime/install/install.lock
rm -rf backend/.mallbase-env
rm -f backend/.backend-env.lock
rm -f backend/.env
rm -f .env
[ ! -d backend/public/static/demo ] || \
  find backend/public/static/demo -mindepth 1 -maxdepth 1 ! -name README.md -exec rm -rf -- {} +
```

上面的演示素材命令会保留 Git 跟踪的 `backend/public/static/demo/README.md`。不需要逐项操作时，优先使用清理脚本。

## 手动清理前端文件

适用：方式一、方式二、方式三、本地构建后的方式四。

```bash
rm -rf backend/public/admin
rm -rf backend/public/client
rm -rf frontend/admin/node_modules
rm -rf frontend/admin/apps/web-antd/node_modules
rm -rf frontend/admin/apps/web-antd/dist
rm -rf frontend/uniapp/node_modules
rm -rf frontend/uniapp/dist
```

## 手动清理 Docker 开发数据

适用：方式三；方式二仅在本地确实存在这些目录时使用。

```bash
rm -rf data/mysql
rm -rf data/redis
rm -rf backend/vendor
```

不要删除 `data/backend`；该目录保存生产后端环境配置、证书和业务素材。

## 手动清理镜像

适用：方式二、方式三。

```bash
docker image rm -f mallbase-backend:dev
```

连共享基础镜像一起删除：

```bash
docker image rm -f mysql:8.0 redis:7-alpine node:20-alpine alpine:3.19
```

## 重新测试首装前的清理边界

只删除安装锁：

```bash
rm -f backend/runtime/install/install.lock
```

这只会让安装流程重新执行，不会清空数据库和 Redis。重新测试首装时，至少要同时确认：

```bash
test -f backend/runtime/install/install.lock && cat backend/runtime/install/install.lock
mysql -h "${DB_HOST}" -P "${DB_PORT:-3306}" -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"
redis-cli -h "${REDIS_HOST}" -p "${REDIS_PORT:-6379}" -n "${REDIS_CACHE_DB:-0}" DBSIZE
```

数据库表数量和 Redis DB 键数量都应为 `0` 后再执行首装。方式三如果要同时清空本地开发数据库和 Redis，可直接使用：

```bash
sh deploy/docker/cleanup-dev.sh --docker
```
