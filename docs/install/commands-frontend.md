# 前端构建与静态资源命令

本页收录后台前端 Admin、UniApp H5、前端 dev server 和静态资源上传命令。完整说明见：

- [admin-build.md](./admin-build.md)
- [uniapp-build.md](./uniapp-build.md)
- [upload-frontend.md](./upload-frontend.md)

## 后台前端 Admin 构建

### 本地构建生产前端

适用：方式一、方式四。

```bash
cd frontend/admin
pnpm install
pnpm run build --filter=@vben/web-antd
```

### Docker 全套模式单独打包后台前端

适用：方式三。

```bash
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

### 单独重跑 `frontend-build`

适用：方式三。

```bash
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

## 启动后台前端 dev server

适用：方式二、方式三。

```bash
cd frontend/admin
pnpm install
pnpm run dev:antd
```

## 启动前端临时调试容器

用于在 `node:20-alpine` 中进入 `frontend/admin` 的临时调试环境。

适用：方式二、方式三；需要本机已安装 Docker。

隔离卷版：

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

最小改动版：

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

## 构建 UniApp H5 到发布目录

```bash
docker compose -f docker-compose.uniapp-build.yml up uniapp-build
```

执行后 H5 会同步到 `backend/public/client`。

## 静态资源上传

### 直接上传到 Nginx 静态目录

适用：方式四。

```bash
sh deploy/upload-frontend.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

如果本地存在 `backend/public/client/index.html`，脚本会同时上传到 `/var/www/mallbase/client`。

### 上传到服务器项目目录下的 `backend/public/admin` 和 `backend/public/client`

适用：方式四。

```bash
sh deploy/upload-frontend.sh \
  --host root@server \
  --remote-root /www/wwwroot/example.com/mall-base
```

### 通过私钥上传

适用：方式四。

```bash
sh deploy/upload-frontend.sh \
  --host root@server \
  --identity ~/.ssh/id_ed25519 \
  --remote-root /www/wwwroot/mallbase.gosowong.cn/mall-base
```
