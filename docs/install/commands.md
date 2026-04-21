# 安装与部署命令集合

本页收录“可以独立执行”的常用命令。  
这些命令适合日常操作，但**不能替代完整安装文档**。执行前请先确认自己当前使用的是哪种安装方式。

## 快速定位

- 重新打包前端：看“前端构建与重新打包”
- 上传后台静态文件：看“静态资源上传”
- 删除容器、卷和本地生成文件：看“删除与清理”
- 检查服务状态：看“连接与快速验证”

## 前端构建与重新打包

### 本地构建生产前端

适用：方式一、方式四

```bash
cd frontend/admin
pnpm install
pnpm run build --filter=@vben/web-antd
```

### Docker 全套模式自动打包后台前端

适用：方式三

```bash
docker compose -f docker-compose.dev.yml --profile build up -d
```

### 单独重跑 `frontend-build`

适用：方式三

```bash
docker compose -f docker-compose.dev.yml --profile build up frontend-build
```

### 启动后台前端 dev server

适用：方式二、方式三

```bash
cd frontend/admin
pnpm install
pnpm run dev:antd
```

## 静态资源上传

### 直接上传到 Nginx 静态目录

适用：方式四

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

### 上传到服务器项目目录下的 `backend/public/admin`

适用：方式四

```bash
sh deploy/upload-public-admin.sh \
  --host root@server \
  --remote-root /www/wwwroot/example.com/mall-base
```

### 通过私钥上传

适用：方式四

```bash
sh deploy/upload-public-admin.sh \
  --host root@165.154.60.251 \
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

### 启动 Docker 开发全套并自动打包前端

适用：方式三

```bash
docker compose -f docker-compose.dev.yml --profile build up -d
```

### 启动 Docker 生产

适用：方式四

```bash
docker compose up -d --build
```

### 查看后端日志

适用：方式二、方式三、方式四

```bash
docker logs mallbase-dev
docker logs mallbase
```

### 查看 `install-auto` 与 `frontend-build` 日志

适用：方式三

```bash
docker logs mallbase-install-auto
docker logs mallbase-frontend-build
```

## 容器内依赖安装

### 初始化后端 `vendor`

适用：方式二、方式三

```bash
docker exec mallbase-dev composer install
```

### 初始化 Playwright 浏览器

适用：前端 E2E 测试

```bash
pnpm --dir frontend/admin run test:e2e:install
```

## 删除与清理

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
docker compose -f docker-compose.dev.yml rm -f ensure-env install-auto frontend-build
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

```bash
cd backend
php think region:import

docker exec mallbase-dev php think region:import
docker exec mallbase php think region:import
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
