# 安装与部署命令导航

本页只做命令入口导航。完整安装流程请先看 [index.md](./index.md)，不要把单条命令拼成安装教程使用。

## 按使用频率找

| 分册 | 适合场景 |
|------|----------|
| [commands-common.md](./commands-common.md) | 日常最常用命令速查：启动、日志、安装、重启、构建、清理 |
| [commands-local.md](./commands-local.md) | 本地 PHP / MySQL / Redis 安装、`install:auto`、Swoole 重启 |
| [commands-docker.md](./commands-docker.md) | Docker 启停、查看日志、容器内依赖安装、连接容器服务 |
| [commands-frontend.md](./commands-frontend.md) | Admin / UniApp 构建、前端 dev server、静态资源上传 |
| [commands-cleanup.md](./commands-cleanup.md) | 分级清理安装运行态、前端文件、Docker 开发状态与镜像 |
| [commands-maintenance.md](./commands-maintenance.md) | HTTP 验证、地区数据导入、旧环境升级、E2E 浏览器初始化 |

## 按问题找

| 我想做什么 | 入口 |
|------------|------|
| 本地安装页面失败，改用命令安装 | [commands-local.md](./commands-local.md#执行命令行安装) |
| 查看当前服务有没有启动 | [commands-common.md](./commands-common.md#检查后端-http) |
| 看 Docker 后端日志 | [commands-docker.md](./commands-docker.md#查看后端日志) |
| 重新打包后台前端 | [commands-frontend.md](./commands-frontend.md#后台前端-admin-构建) |
| 上传 admin / client 静态资源 | [commands-frontend.md](./commands-frontend.md#静态资源上传) |
| 清理安装运行态、前端文件或 Docker 开发状态 | [commands-cleanup.md](./commands-cleanup.md#分级清理脚本) |
| 重新测试首装 | [commands-cleanup.md](./commands-cleanup.md#重新测试首装前的清理边界) |
| 导入地区数据 | [commands-maintenance.md](./commands-maintenance.md#导入地区数据) |
| 升级旧环境管理员表结构 | [commands-maintenance.md](./commands-maintenance.md#升级旧环境的-password_changed_at-列) |

## 和完整文档的关系

- 第一次安装：先看 [manual.md](./manual.md)、[docker-backend-only.md](./docker-backend-only.md)、[docker-fullstack.md](./docker-fullstack.md) 或 [docker-production.md](./docker-production.md)。
- 本地安装失败后改走 CLI：看 [cli-install.md](./cli-install.md)。
- 命令报错：看 [troubleshooting.md](./troubleshooting.md)。
- 不确定 `.env` 应该改哪一份：看 [env-files.md](./env-files.md)。
