# MallBase 安装与部署目录

## 环境要求

| 依赖 | 最低版本 | 用途 |
|------|---------|------|
| PHP | 8.2+ | 后端运行 |
| Swoole 扩展 | 5.0+（兼容 4.2.9+） | 高性能 HTTP 服务 |
| Redis 扩展 (phpredis) | 5.3.4+（推荐 6.0+） | 缓存/会话 |
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

## 安装方式总览

| 方式 | 适合场景 | 完整步骤 | 相关命令 | 排障 |
|------|---------|----------|----------|------|
| 方式一：手动安装（无 Docker） | 低配服务器、需要完全控制环境 | [manual.md](./manual.md) | [commands.md](./commands.md) | [troubleshooting.md](./troubleshooting.md) |
| 方式二：Docker 开发（仅后端） | 本地开发、宿主机已有 MySQL/Redis | [docker-backend-only.md](./docker-backend-only.md) | [commands.md](./commands.md) | [troubleshooting.md](./troubleshooting.md) |
| 方式三：Docker 开发（全套） | 本地一键启动后端 + MySQL + Redis + 前端自动打包 | [docker-fullstack.md](./docker-fullstack.md) | [commands.md](./commands.md) | [troubleshooting.md](./troubleshooting.md) |
| 方式四：Docker 生产 | 单后端容器 + 宿主机 Nginx 的生产部署 | [docker-production.md](./docker-production.md) | [commands.md](./commands.md) | [troubleshooting.md](./troubleshooting.md) |

## 推荐阅读顺序

1. 先根据上表选择安装方式。
2. 进入对应的完整步骤文档，从头按顺序执行，不要只看命令集合拼装流程。
3. 涉及 Docker 全套模式时，优先阅读 [env-files.md](./env-files.md)。
4. 涉及 Nginx 或前端静态文件发布时，配合阅读 [nginx-reverse-proxy.md](./nginx-reverse-proxy.md) 和 [upload-public-admin.md](./upload-public-admin.md)。
5. 遇到报错时，先查 [troubleshooting.md](./troubleshooting.md)；如果是方式三首装时序问题，再看 [issues/docker-fullstack-first-run.md](./issues/docker-fullstack-first-run.md)。

## 各方式摘要

### 方式一：手动安装（无 Docker）

- 适合低配服务器、需要自行管理 PHP / MySQL / Redis / Nginx 的场景。
- 完整步骤见 [manual.md](./manual.md)。
- Nginx 配置和反向代理规则见 [nginx-reverse-proxy.md](./nginx-reverse-proxy.md)。

### 方式二：Docker 开发（仅后端）

- 只启动后端容器，数据库和 Redis 由宿主机提供。
- 完整步骤见 [docker-backend-only.md](./docker-backend-only.md)。
- 常用命令如 `docker exec mallbase-dev composer install` 和清理命令见 [commands.md](./commands.md)。

### 方式三：Docker 开发（全套）

- 适合本地一键起全套环境，并使用 `frontend-build` 自动把后台前端资源同步到 `backend/public/admin`。
- 完整步骤见 [docker-fullstack.md](./docker-fullstack.md)。
- `.env` 的主从关系与零向导变量见 [env-files.md](./env-files.md)。

### 方式四：Docker 生产

- 适合单后端容器 + 宿主机 Nginx 的生产部署方式。
- 完整步骤见 [docker-production.md](./docker-production.md)。
- 前端文件上传脚本见 [upload-public-admin.md](./upload-public-admin.md)。

## 专题文档

| 文档 | 说明 |
|------|------|
| [commands.md](./commands.md) | 可独立执行的安装与部署命令集合，包含删除与清理命令 |
| [troubleshooting.md](./troubleshooting.md) | 安装与部署相关的总故障排查 |
| [env-files.md](./env-files.md) | 根 `.env`、`backend/.env` 与 Docker 全套模式配置职责 |
| [nginx-reverse-proxy.md](./nginx-reverse-proxy.md) | `/admin/`、`/admin/api/`、`/install` 等路径的代理规则 |
| [upload-public-admin.md](./upload-public-admin.md) | 把本地 `backend/public/admin` 打包上传到服务器 |
| [issues/docker-fullstack-first-run.md](./issues/docker-fullstack-first-run.md) | Docker 全套模式首次启动的时序与密码问题专题记录 |

## 重要提醒

- `docs/install.md` 作为稳定入口保留，实际安装目录在 `docs/install/`。
- `commands.md` 里的命令可以单独执行，但它不是完整安装教程的替代品。
- 每种安装方式的完整闭环都在各自独立文档里，执行时请优先跟随对应方式文档。
