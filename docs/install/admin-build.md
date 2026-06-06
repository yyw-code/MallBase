# 后台前端（Admin）打包说明

本文档说明如何把后台管理前端（Vben Admin 5 / `frontend/admin`）打包成静态产物，输出到 `backend/public/admin`，供 Swoole 直接托管或上传到 Nginx 静态目录。

## 打包文件位置

- 打包用 Compose：[`docker-compose.frontend-build.yml`](../../docker-compose.frontend-build.yml)
- 配套脚本：[`deploy/docker/frontend-build.sh`](../../deploy/docker/frontend-build.sh)

## 方式一：Docker 一键打包（推荐，适用方式三）

在仓库根目录执行：

```bash
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

容器会在 `frontend/admin` 下完成打包，并把产物同步到 `backend/public/admin`。需要重新打包时再执行同一条命令即可（会先清空 `backend/public/admin` 再写入）。

查看打包日志：

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker logs ${PREFIX}-frontend-build
```

## 方式二：本地打包（适用方式一 / 方式四）

需要本机已安装 Node.js 20.19.0+ 和 pnpm 10+：

```bash
cd frontend/admin
pnpm install
pnpm run build:antd
```

构建产物在 `frontend/admin/apps/web-antd/dist`。手动同步到发布目录：

```bash
mkdir -p backend/public/admin
rm -rf backend/public/admin/*
cp -r frontend/admin/apps/web-antd/dist/. backend/public/admin/
```

## 构建流程

`deploy/docker/frontend-build.sh` 的步骤：

1. 启用 `corepack` 并激活项目声明的 `pnpm` 版本
2. `pnpm install --frozen-lockfile` 安装依赖（严格按 lockfile）
3. `pnpm run build:antd` 构建后台前端
4. 把 `apps/web-antd/dist` 拷贝到产物目录（容器内挂载为 `backend/public/admin`）

## 产物校验

```bash
ls backend/public/admin/index.html
```

文件存在即说明产物完整。

## 部署

打包完成后：

- 方式三（Docker 开发全套）：`backend/public/admin` 由后端容器直接托管，访问 `/admin` 即可。
- 方式一 / 方式四：用 [upload-frontend.md](./upload-frontend.md) 里的 `deploy/upload-frontend.sh` 上传到服务器；如果同时存在 `backend/public/client`（UniApp H5 产物，见 [uniapp-build.md](./uniapp-build.md)），脚本会一并上传。

## 相关文档

- [commands-frontend.md](./commands-frontend.md) —— 前端构建相关命令、临时调试容器
- [docker-fullstack.md](./docker-fullstack.md) —— 方式三的完整步骤（含前端打包环节）
- [upload-frontend.md](./upload-frontend.md) —— 把产物上传到服务器
- [uniapp-build.md](./uniapp-build.md) —— UniApp H5 打包
