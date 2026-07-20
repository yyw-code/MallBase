# MallBase 安装与部署导航

本页是 MallBase 安装与部署的唯一导航入口：先按需求选一种安装方式，再进入对应的完整步骤文档，遇到问题查排障文档，需要单独执行某条命令查命令导航。

## 安装方式

| 方式 | 适合场景 | 完整步骤 |
|------|---------|----------|
| 方式一：手动安装（无 Docker） | 低配服务器、需要完全控制 PHP / MySQL / Redis / Nginx | [manual.md](./manual.md) |
| 方式二：Docker 开发（仅后端） | 本地开发，宿主机已有 MySQL / Redis | [docker-backend-only.md](./docker-backend-only.md) |
| 方式三：Docker 开发（全套） | 本地一键起后端 + MySQL + Redis，前端打包单独执行 | [docker-fullstack.md](./docker-fullstack.md) |
| 方式四：Docker 生产 | HTTP + Queue + Cron 独立容器 + 宿主机 Nginx | [docker-production.md](./docker-production.md) |

四种方式都配合 [commands.md](./commands.md)（命令导航）和 [troubleshooting.md](./troubleshooting.md)（排障）使用。

## 统一安装目录

四种安装方式都遵循同一套目录约定：

| 类型 | 位置 | 说明 |
|------|------|------|
| 安装资源 | `backend/install/` | SQL、演示数据、地区数据、演示静态图；随源码进入生产镜像 |
| 安装状态 | `backend/runtime/install/install.lock` | 安装完成标记；运行时生成，不入库；Docker 生产由 `/app/runtime` volume 持久化 |

## 安装专题文档

| 文档 | 说明 |
|------|------|
| [commands.md](./commands.md) | 安装与部署命令导航，按常用、本地、Docker、前端、清理、维护拆分 |
| [cli-install.md](./cli-install.md) | 手动安装或本地安装失败时，使用 `php think install:auto` 执行命令行安装 |
| [troubleshooting.md](./troubleshooting.md) | 安装、Docker、前端静态资源与运行时的故障排查 |
| [env-files.md](./env-files.md) | 根 `.env`、`backend/.env` 与安装运行时配置职责 |
| [nginx-reverse-proxy.md](./nginx-reverse-proxy.md) | `/`、`/client/`、`/admin/`、`/client/api/`、`/admin/api/` 等路径的代理与静态托管规则 |
| [upgrade-agent.md](./upgrade-agent.md) | 一次性升级 Agent 的职责、systemd 安装与排障 |
| [cloud-storage-upload.md](./cloud-storage-upload.md) | 本地存储、阿里云 OSS、腾讯云 COS 上传驱动配置与验证 |
| [issues/docker-fullstack-first-run.md](./issues/docker-fullstack-first-run.md) | 方式三首次启动的密码错位、时序问题专题记录 |

## 命令分册

| 文档 | 说明 |
|------|------|
| [commands-common.md](./commands-common.md) | 常用命令速查：安装、启动、日志、构建、上传、清理 |
| [commands-local.md](./commands-local.md) | 本地安装、`install:auto`、Swoole 启停 |
| [commands-docker.md](./commands-docker.md) | Docker 启停、日志、容器内依赖 |
| [commands-frontend.md](./commands-frontend.md) | Admin / UniApp 构建与静态资源上传 |
| [commands-cleanup.md](./commands-cleanup.md) | 分级清理安装运行态、前端文件、Docker 开发状态与镜像 |
| [commands-maintenance.md](./commands-maintenance.md) | 验证、补同步、旧环境升级、E2E 准备 |

## 前端构建与发布

| 文档 | 说明 |
|------|------|
| [admin-build.md](./admin-build.md) | 后台前端（Vben Admin）打包到 `backend/public/admin`（Docker 一键打包 / 本地打包） |
| [uniapp-build.md](./uniapp-build.md) | UniApp H5 打包到 `backend/public/client` |
| [upload-frontend.md](./upload-frontend.md) | 用 `deploy/upload-frontend.sh` 把 `backend/public/admin`（及 `client`）上传到服务器 |
| [cleanup-dev.md](./cleanup-dev.md) | `deploy/docker/cleanup-dev.sh`：按等级清理基础运行态、前端文件、Docker 开发状态与镜像 |

## 环境要求

| 依赖 | 最低版本 | 用途 |
|------|---------|------|
| PHP | 8.2+ | 后端运行 |
| Swoole 扩展 | 5.0+（兼容 4.2.9+） | 高性能 HTTP 服务 |
| Redis 扩展 (phpredis) | 5.3.4+（推荐 6.0+） | 缓存 / 会话 |
| MySQL | 8.0+ | 数据库 |
| Redis | 6.0+ | 缓存 |
| Composer | 2.0+ | PHP 依赖管理 |
| Node.js | 20.19.0+（仅构建前端） | 前端打包 |
| pnpm | 10.0.0+（仅构建前端） | 前端包管理 |

### PHP 扩展清单

| 扩展 | 用途 | 必须 |
|------|------|------|
| swoole | HTTP 服务器 | 是 |
| pdo_mysql | 数据库驱动 | 是 |
| redis | 缓存驱动 | 是 |
| mbstring | 多字节字符串 | 是 |
| gd | 图片处理 | 是 |
| zip | 压缩包处理 | 是 |
| intl | 国际化 | 是 |
| bcmath | 高精度数学（价格计算） | 是 |
| opcache | PHP 性能优化 | 推荐 |

## 推荐阅读顺序

1. 按上面的「安装方式」表选一种。
2. 进入对应的完整步骤文档，从头按顺序执行，不要只看命令分册拼装流程。
3. 用方式三时，先读 [env-files.md](./env-files.md) 理清 `.env` 的主从关系。
4. 涉及 Nginx 或前端静态文件发布时，配合 [nginx-reverse-proxy.md](./nginx-reverse-proxy.md)、[admin-build.md](./admin-build.md)、[uniapp-build.md](./uniapp-build.md)、[upload-frontend.md](./upload-frontend.md)。
5. 遇到报错先查 [troubleshooting.md](./troubleshooting.md)；如果是方式三首装时序问题，再看 [issues/docker-fullstack-first-run.md](./issues/docker-fullstack-first-run.md)。

## 说明

- `commands.md` 是命令导航，命令分册可以单独查阅，但不能替代完整安装教程。
- `cli-install.md` 只覆盖本地命令行安装；需要页面交互确认时仍按对应安装方式文档执行。
- 每种安装方式的完整闭环都在各自独立文档里，执行时请优先跟随对应方式文档。
