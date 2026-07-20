# 分级清理脚本说明

本文档说明 `deploy/docker/cleanup-dev.sh` 的作用与用法。脚本按清理范围分成四个等级，从基础安装运行态到 Docker 镜像逐级扩大，适合本地开发、重新测试首装和清理构建产物。

> 注意：`--docker`、`--images`、`--all-images` 会删除本地数据库、Redis 数据或镜像状态。生产服务器不建议直接使用本脚本清理 Docker 资源。

## 脚本位置

- 脚本：[`deploy/docker/cleanup-dev.sh`](../../deploy/docker/cleanup-dev.sh)

## 清理等级

| 等级 | 命令 | 包含内容 | 适合安装方式 |
|------|------|----------|--------------|
| 基础清理 | `sh deploy/docker/cleanup-dev.sh` 或 `--basic` | 安装锁、根 `.env`、Docker 开发运行配置、旧 `backend/.env`、演示运行时素材 | 方式一、方式二、方式三、本地构建后的方式四 |
| 前端清理 | `sh deploy/docker/cleanup-dev.sh --frontend` | 基础清理 + Admin / UniApp 依赖、构建产物和发布产物 | 方式一、方式二、方式三、本地构建后的方式四 |
| Docker 状态清理 | `sh deploy/docker/cleanup-dev.sh --docker` | 前端清理 + 开发容器、网络、卷、`data/mysql`、`data/redis`、`backend/vendor` | 方式二、方式三 |
| 项目镜像清理 | `sh deploy/docker/cleanup-dev.sh --images` | Docker 状态清理 + `mallbase-backend:dev` | 方式二、方式三 |

`--all-images` 是额外清理档，包含 `--images`，并删除 MySQL、Redis、Node、Alpine 等共享基础镜像。它可能影响本机其他项目，不确定时不要使用。

一次只指定一个清理等级；如果同时传入多个等级参数，脚本会拒绝执行。

## 各等级清理内容

### 1. 基础清理

```bash
sh deploy/docker/cleanup-dev.sh
```

等同于：

```bash
sh deploy/docker/cleanup-dev.sh --basic
```

清理内容：

- `.env`
- `backend/.mallbase-env`（包括 `backend.env`、环境锁和未完成的临时文件）
- `backend/.env`
- `backend/.backend-env.lock`（旧版入口脚本可能遗留）
- `backend/runtime/install/install.lock`
- `backend/public/static/demo` 中安装生成的运行时素材

`backend/public/static/demo/README.md` 是 Git 跟踪的目录说明文件，清理脚本会原样保留，不会让代码仓库出现删除记录。`backend/.mallbase-env` 是专用运行目录，基础清理会将它整体删除，下一次 preflight 会重新创建并交接权限。

适用场景：

- 重新走安装流程，但暂时不清数据库和 Redis
- 清掉安装生成的运行态配置
- 清掉安装流程移动或生成的演示静态文件，同时保留仓库内 README

### 2. 前端清理

```bash
sh deploy/docker/cleanup-dev.sh --frontend
```

包含基础清理，并额外删除：

- `backend/public/admin`
- `backend/public/client`
- `frontend/admin/node_modules`
- `frontend/admin/apps/web-antd/node_modules`
- `frontend/admin/apps/web-antd/dist`
- `frontend/uniapp/node_modules`
- `frontend/uniapp/dist`

适用场景：

- 重新打包 Admin 后台前端
- 重新打包 UniApp H5
- 排查前端构建产物、发布产物或依赖缓存问题

### 3. Docker 状态清理

```bash
sh deploy/docker/cleanup-dev.sh --docker
```

包含前端清理，并额外处理：

- `docker-compose.dev.yml` 创建的开发容器、网络、卷
- `docker-compose.frontend-build.yml` 创建的前端打包容器和卷
- `docker-compose.uniapp-build.yml` 创建的 UniApp 打包容器和卷
- 可能残留的开发容器
- `data/mysql`
- `data/redis`
- `backend/vendor`

`data/backend` 保存生产后端环境配置、证书和业务素材，清理脚本不会删除该目录。

适用场景：

- 方式三环境需要完整重置
- MySQL / Redis 本地数据需要删除后重新安装
- Docker 开发容器、卷或依赖状态异常

### 4. 项目镜像清理

```bash
sh deploy/docker/cleanup-dev.sh --images
```

包含 Docker 状态清理，并额外删除：

- `mallbase-backend:dev`

适用场景：

- Dockerfile 或后端依赖构建状态异常
- 希望下次重新构建本项目后端镜像

### 5. 共享基础镜像清理

```bash
sh deploy/docker/cleanup-dev.sh --all-images
```

包含项目镜像清理，并额外删除：

- `mysql:8.0`
- `redis:7-alpine`
- `node:20-alpine`
- `alpine:3.19`

这些镜像可能被本机其他项目使用，删除后下次还要重新拉取。

## 对应安装方式

| 安装方式 | 推荐清理等级 | 说明 |
|----------|--------------|------|
| 方式一：手动安装（无 Docker） | `--basic`、`--frontend` | 不涉及 Docker 容器；数据库和 Redis 需要按实际部署单独处理 |
| 方式二：Docker 开发（仅后端） | `--basic`、`--frontend`、`--docker`、`--images` | `--docker` 会删除后端开发容器和本地生成文件，不会清理外部 MySQL / Redis |
| 方式三：Docker 开发（全套） | 四个等级都适用 | `--docker` 会删除本地 `data/mysql` 和 `data/redis`，相当于清空开发 MySQL / Redis 数据 |
| 方式四：Docker 生产 | 本地构建机可用 `--basic`、`--frontend` | 生产服务器上的容器、卷和数据不要用本脚本清理，需按部署方案单独确认 |

## 查看帮助

```bash
sh deploy/docker/cleanup-dev.sh --help
```

## 不想用脚本时的等价手动命令

见 [commands-cleanup.md](./commands-cleanup.md)，里面有分步的 `docker compose down -v`、`docker rm -f`、`rm -rf` 等命令，可以按需只清一部分。

## 清理后如何重新开始

- 方式一：回到 [manual.md](./manual.md) 的安装步骤，重新生成 `backend/.env` 并启动 Swoole。
- 方式二：回到 [docker-backend-only.md](./docker-backend-only.md) 重新启动后端容器。
- 方式三：回到 [docker-fullstack.md](./docker-fullstack.md) 从头启动开发全套；preflight 会重建 `backend/.mallbase-env` 与演示素材目录并交接权限。
- 方式四：本地重新构建前端后，按 [upload-frontend.md](./upload-frontend.md) 上传产物。
