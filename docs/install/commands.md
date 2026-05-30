# 安装与部署命令集合

本页收录“可以独立执行”的常用命令。  
这些命令适合日常操作，但**不能替代完整安装文档**。执行前请先确认自己当前使用的是哪种安装方式。

## 快速定位

- 重新打包前端：看“前端构建与重新打包”
- 前端临时调试容器：看“前端构建与重新打包”
- 上传后台静态文件：看“静态资源上传”
- 删除容器、卷和本地生成文件：看“删除与清理”
- 检查服务状态：看“连接与快速验证”

## 前端构建与重新打包

> 后台前端（Admin）打包的完整说明见 [admin-build.md](./admin-build.md)，UniApp H5 打包见 [uniapp-build.md](./uniapp-build.md)。

### 本地构建生产前端

适用：方式一、方式四

```bash
cd frontend/admin
pnpm install
pnpm run build --filter=@vben/web-antd
```

### Docker 全套模式单独打包后台前端

适用：方式三

```bash
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

### 单独重跑 `frontend-build`

适用：方式三

```bash
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

### 启动后台前端 dev server

适用：方式二、方式三

```bash
cd frontend/admin
pnpm install
pnpm run dev:antd
```

### 启动前端临时调试容器

用于在 `node:20-alpine` 中进入 `frontend/admin` 的临时调试环境。

适用：方式二、方式三；需要本机已安装 Docker

推荐：隔离卷版（`node_modules` 不写回宿主机）

```bash
docker run --rm -it \
  -p 5666:5666 \
  -v "$PWD/frontend/admin:/app" \
  -v mallbase-pnpm-store:/root/.local/share/pnpm/store \
  -v mallbase-admin-node_modules:/app/node_modules \
  -w /app \
  node:20-alpine \
  sh -lc 'corepack enable && corepack prepare pnpm@10.28.2 --activate && exec sh'
```

补充：最小改动版（沿用宿主机目录，不单独挂载 `/app/node_modules`）

```bash
docker run --rm -it \
  -p 5666:5666 \
  -v "$PWD/frontend/admin:/app" \
  -v mallbase-pnpm-store:/root/.local/share/pnpm/store \
  -w /app \
  node:20-alpine \
  sh -lc 'corepack enable && corepack prepare pnpm@10.28.2 --activate && exec sh'
```

进入容器后继续执行：

```bash
pnpm -v
pnpm install
pnpm run dev:antd
```

说明：`node:20-alpine` 基础镜像默认没有激活 `pnpm`。当前项目的前端工作区声明使用 `pnpm@10.28.2`，因此进入容器后需要先启用 `corepack`，再按项目声明激活对应版本的 `pnpm`。

## 静态资源上传

> 上传脚本的完整说明（含本地配置文件、SSH 密钥与密码登录）见 [upload-frontend.md](./upload-frontend.md)。

### 构建 UniApp H5 到发布目录

```bash
docker compose -f docker-compose.uniapp-build.yml up uniapp-build
```

执行后 H5 会同步到 `backend/public/client`。

### 直接上传到 Nginx 静态目录

适用：方式四

```bash
sh deploy/upload-frontend.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

如果本地存在 `backend/public/client/index.html`，脚本会同时上传到 `/var/www/mallbase/client`。

### 上传到服务器项目目录下的 `backend/public/admin` 和 `backend/public/client`

适用：方式四

```bash
sh deploy/upload-frontend.sh \
  --host root@server \
  --remote-root /www/wwwroot/example.com/mall-base
```

### 通过私钥上传

适用：方式四

```bash
sh deploy/upload-frontend.sh \
  --host root@server \
  --identity ~/.ssh/id_ed25519 \
  --remote-root /www/wwwroot/mallbase.gosowong.cn/mall-base
```

## Docker 启停与查看日志

### 只启动后端容器

适用：方式二

```bash
docker compose -f docker-compose.dev.yml up -d --no-deps backend
```

### 启动 Docker 开发全套

适用：方式三

```bash
docker compose -f docker-compose.dev.yml up -d
```

### 启动 Docker 开发全套并单独执行前端打包

适用：方式三

```bash
docker compose -f docker-compose.dev.yml up -d
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

### 启动 Docker 生产

适用：方式四

```bash
docker compose up -d --build
```

### 查看后端日志

适用：方式二、方式三、方式四

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker logs ${PREFIX}-dev
docker logs ${PREFIX}
```

### 查看 `frontend-build` 日志

适用：方式三

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker logs ${PREFIX}-frontend-build
```

### 手动执行 `install:auto`（可选 CLI 安装）

适用：方式三；仅用于无人值守或手动 CLI 安装，不是默认流程

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker exec ${PREFIX}-dev php think install:auto
```

## 容器内依赖安装

### 启动前初始化后端 `vendor`

适用：方式二、方式三

```bash
docker compose -f docker-compose.dev.yml run --rm --no-deps backend composer install
```

### 已启动容器里重新安装后端依赖

适用：方式二、方式三

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker exec ${PREFIX}-dev composer install
```

### 初始化 Playwright 浏览器

适用：前端 E2E 测试

```bash
pnpm --dir frontend/admin run test:e2e:install
```

## 删除与清理

> 一键清理脚本的完整说明见 [cleanup-dev.md](./cleanup-dev.md)。

### 一键清理 Docker 开发全套状态

适用：方式三

```bash
sh deploy/docker/cleanup-dev.sh
```

### 连基础镜像一起清理

适用：方式三

```bash
sh deploy/docker/cleanup-dev.sh --all-images
```

### 手动停掉并删除开发容器与卷

适用：方式三

```bash
docker compose -f docker-compose.dev.yml down -v
docker compose -f docker-compose.frontend-build.yml down -v
docker compose -f docker-compose.dev.yml rm -f ensure-env check-db-auth
```

### 手动清理本地生成文件

适用：方式三

```bash
rm -rf data/
rm -f deploy/install/install.lock
rm -f backend/.env
rm -f .env
rm -rf backend/vendor
rm -rf backend/public/admin
rm -rf frontend/admin/node_modules
rm -rf frontend/admin/apps/web-antd/node_modules
rm -rf frontend/admin/apps/web-antd/dist
```

## 连接与快速验证

### 检查后台前端产物是否存在

适用：方式三、方式四

```bash
ls backend/public/admin/index.html
```

### 检查后端 HTTP 是否可访问

适用：方式一、方式二、方式三、方式四

```bash
curl -I http://127.0.0.1:8080/
```

### 连接 Docker 全套模式的 MySQL / Redis

适用：方式三

```bash
mysql -h 127.0.0.1 -P 3306 -u <DB_USER> -p
redis-cli -h 127.0.0.1 -p 6379
```

### 导入地区数据

适用：方式一、方式二、方式三、方式四

说明：统一安装流程已经默认导入地区数据。以下命令主要用于补同步或手工修复。

```bash
cd backend
php think region:import

PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker exec ${PREFIX}-dev php think region:import
docker exec ${PREFIX} php think region:import
```

### 升级旧环境的 `password_changed_at` 列

适用：方式一、方式三、方式四

```bash
cd backend
php think upgrade:admin-schema

docker compose -f docker-compose.dev.yml exec -T backend php think upgrade:admin-schema
docker compose exec -T backend php think upgrade:admin-schema
```

### 重启服务以加载新的 `.env`

适用：方式一、方式二、方式三、方式四

```bash
lsof -ti :8080 | xargs kill && php think swoole
docker compose -f docker-compose.dev.yml restart backend
docker compose restart
```
