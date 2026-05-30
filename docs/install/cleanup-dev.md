# Docker 开发全套清理脚本说明

本文档说明 `deploy/docker/cleanup-dev.sh` 的作用与用法。该脚本用于把「方式三：Docker 开发（全套）」产生的本地状态清理干净，回到全新仓库的状态。

> ⚠️ 这是破坏性操作：会删除本地 `.env`、`data/`（MySQL / Redis 数据）等文件。执行前请确认没有需要保留的数据。

## 适用场景

- 方式三环境装坏了、想从头再来
- 切换数据库账号或配置后想清掉旧数据重新安装
- 释放磁盘空间

## 脚本位置

- 脚本：[`deploy/docker/cleanup-dev.sh`](../../deploy/docker/cleanup-dev.sh)

## 用法

```bash
sh deploy/docker/cleanup-dev.sh
```

连共享基础镜像一起删：

```bash
sh deploy/docker/cleanup-dev.sh --all-images
```

## 默认清理内容

1. `docker-compose.dev.yml`、`docker-compose.frontend-build.yml` 创建的容器 / 网络 / 卷（含匿名卷），以及 orphan 容器
2. 兜底删除可能残留的容器（默认前缀为 `mallbase`；设置 `MALLBASE_CONTAINER_PREFIX` 后按对应前缀清理）
3. 本项目构建镜像 `mallbase-backend:dev`
4. 宿主机 bind mount 生成的文件：
   - `data/`（MySQL / Redis 数据目录）
   - `.env`、`backend/.env`
   - `backend/vendor`
   - `deploy/install/install.lock`
   - `backend/public/admin`
   - `frontend/admin/node_modules`
   - `frontend/admin/apps/web-antd/node_modules`
   - `frontend/admin/apps/web-antd/dist`

## `--all-images` 额外清理

额外删除以下共享基础镜像：

- `mysql:8.0`
- `redis:7-alpine`
- `node:20-alpine`
- `alpine:3.19`

注意：这些镜像可能被本机其他项目使用，删除后下次还要重新拉取。不确定就不要加这个参数。

## 不想用脚本时的等价手动命令

见 [commands.md 的「删除与清理」](./commands.md#删除与清理)，里面有分步的 `docker compose down -v`、`docker rm -f`、`rm -rf` 等命令，可以按需只清一部分。

## 清理后如何重新开始

清理完按 [docker-fullstack.md](./docker-fullstack.md) 从头执行方式三的安装步骤即可。
