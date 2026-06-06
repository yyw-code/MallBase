# MallBase

MallBase 是一个面向中小型商城业务的基础后端框架，以 ThinkPHP 8 + think-swoole 为核心，提供一套清晰、可扩展、适合长期维护的项目骨架。

项目目标不是做"功能最全的商城"，而是提供一个结构合理、技术选型稳定、易于二次开发、适合团队协作的商城型应用基础底座。

## 技术栈

### 后端

- PHP >= 8.2
- ThinkPHP 8.0（多应用模式）
- think-swoole（Swoole HTTP 服务）
- MySQL 8.0+
- Redis 6+

### 前端

- Admin：Vben Admin 5（Vue3 + Vite + Ant Design Vue）
- 移动端：UniApp（预留）

### 部署

- Docker / Docker Compose（单后端容器生产 / 多容器开发全套）
- Swoole 原生部署

## 项目结构

```text
mall-base/
├── backend/                        # 后端（ThinkPHP + Swoole）
│   ├── app/
│   │   ├── admin/                  # 后台管理 API
│   │   │   ├── controller/         # 控制器（薄层，仅参数校验和路由）
│   │   │   ├── service/            # 业务逻辑（无状态）
│   │   │   ├── model/              # 数据模型
│   │   │   ├── validate/           # 验证器
│   │   │   └── middleware/         # 后台中间件（JWT、权限、操作日志）
│   │   ├── client/                 # C 端 API（预留）
│   │   ├── install/                # 安装模块
│   │   │   ├── controller/         # 安装接口
│   │   │   └── service/            # 安装逻辑
│   │   └── middleware/             # 全局中间件（CORS、安装检测）
│   ├── config/                     # 框架配置
│   ├── install/                    # 安装资源（SQL / 演示数据 / 地区数据 / 演示静态图）
│   ├── route/                      # 路由定义
│   ├── public/                     # 静态文件（前端构建产物、安装页面）
│   └── mall_base/                  # 项目基础类库（BaseController / BaseService）
│
├── frontend/
│   ├── admin/                      # Vben Admin 5 后台管理前端
│   └── uniapp/                     # UniApp 移动端（预留）
│
├── deploy/
│   ├── docker/
│   │   ├── Dockerfile              # 后端镜像构建
│   │   ├── frontend-build.sh       # 后台前端（Admin）打包脚本
│   │   ├── uniapp-build.sh         # UniApp H5 打包脚本
│   │   ├── cleanup-dev.sh          # 分级清理基础运行态、前端文件、Docker 开发状态与镜像
│   │   ├── prepare-data-dirs.sh    # 开发全套模式数据目录权限预检
│   │   ├── ...                     # 其它内部辅助脚本（ensure-env / check-db-auth / 入口脚本等）
│   │   └── mysql/                  # MySQL 初始化脚本
│   ├── nginx/
│   │   └── mallbase.conf           # Nginx 配置示例
│   ├── upload-frontend.sh          # 打包并上传前端静态资源（后台 admin + H5 client）
│   └── upload-frontend.local.sh.example  # 上传脚本本地配置示例（复制为 upload-frontend.local.sh，已被 git 忽略）
│
├── docs/                           # 文档
│   ├── index.md                    # 文档中心总入口
│   ├── install/                    # 安装与部署（导航入口：install/index.md）
│   │   ├── index.md                # 安装与部署导航
│   │   ├── manual.md               # 方式一：手动安装（无 Docker）
│   │   ├── docker-backend-only.md  # 方式二：Docker 开发（仅后端）
│   │   ├── docker-fullstack.md     # 方式三：Docker 开发（全套）
│   │   ├── docker-production.md    # 方式四：Docker 生产
│   │   ├── commands.md             # 安装与部署命令导航
│   │   ├── commands-common.md      # 常用命令速查
│   │   ├── commands-local.md       # 本地安装与 Swoole 命令
│   │   ├── commands-docker.md      # Docker 启停、日志与容器命令
│   │   ├── commands-frontend.md    # 前端构建与静态资源命令
│   │   ├── commands-cleanup.md     # 删除与清理命令
│   │   ├── commands-maintenance.md # 验证与维护命令
│   │   ├── cli-install.md          # 命令行安装 install:auto
│   │   ├── troubleshooting.md      # 安装与部署故障排查
│   │   ├── env-files.md            # 环境文件职责说明
│   │   ├── nginx-reverse-proxy.md  # Nginx 反向代理配置说明
│   │   ├── admin-build.md          # 后台前端（Admin）打包说明
│   │   ├── uniapp-build.md         # UniApp H5 打包说明
│   │   ├── upload-frontend.md      # 前端静态资源上传脚本说明
│   │   ├── cleanup-dev.md          # 分级清理脚本说明
│   │   └── issues/
│   │       └── docker-fullstack-first-run.md  # 方式三首装问题排查记录
│   ├── uniapp-design-brief.md      # UniApp 移动端设计需求文档
│   ├── freight-template-roadmap.md # 运费模板路线图
│   ├── claude-code-guide.md        # Claude Code 使用指南
│   └── testing/
│       └── change-trigger-test-matrix.md  # 测试基线与触发矩阵
├── docker-compose.yml              # 单后端容器（生产，需外部 MySQL / Redis）
├── docker-compose.dev.yml          # 开发全套（后端 + MySQL + Redis）
├── docker-compose.frontend-build.yml  # 后台前端打包
├── docker-compose.uniapp-build.yml # UniApp H5 打包
└── README.md
```

## 业务模块

| 模块 | 说明 |
|------|------|
| Auth | 管理员登录、JWT 认证、角色权限（RBAC） |
| User | C 端用户管理、状态控制 |
| Goods | 商品 SPU/SKU、分类、品牌、规格、标签、评论 |
| Order | 购物车、下单、状态流转、物流、售后退款 |
| Delivery | 收货地址、运费模板（按件/按重、多层级区域匹配） |
| Region | 省市区街道四级地区库 |
| Setting | 系统设置（分组 + 设置项，后台动态配置） |

## 在线演示

- 演示站 Admin：[https://preview.gosowong.cn/admin](https://preview.gosowong.cn/admin)
- 演示站 H5：[https://preview.gosowong.cn/client/#/](https://preview.gosowong.cn/client/#/)

## 快速开始

### Docker 一键部署（推荐）

```bash
docker compose up -d --build
```

访问 `http://localhost` 进入安装向导，填写数据库和 Redis 配置，创建管理员账号。

安装完成后重启服务：

```bash
docker compose restart
```

重启后访问 `/admin` 进入后台。

其它安装方式（手动安装、开发全套、生产部署）与完整步骤见下方文档表，或直接看 [安装与部署导航](docs/install/index.md)。

## 文档

### 安装与部署

| 文档 | 说明 |
|------|------|
| [文档中心](docs/index.md) | 所有文档的总入口：按场景查常用与不常用文档 |
| [安装与部署导航](docs/install/index.md) | 唯一入口：选择安装方式、环境要求、专题文档索引 |
| [方式一：手动安装](docs/install/manual.md) | 无 Docker 场景的完整部署步骤 |
| [方式二：Docker 开发（仅后端）](docs/install/docker-backend-only.md) | 宿主机 MySQL / Redis + 后端容器 |
| [方式三：Docker 开发（全套）](docs/install/docker-fullstack.md) | 后端 + MySQL + Redis 一键启动，前端打包单独执行 |
| [方式四：Docker 生产](docs/install/docker-production.md) | 单后端容器 + 宿主机 Nginx |
| [安装与部署命令导航](docs/install/commands.md) | 命令分册入口，按常用、本地、Docker、前端、清理、维护拆分 |
| [命令行安装 install:auto](docs/install/cli-install.md) | 手动安装或本地安装失败时执行 `php think install:auto` |
| [安装与部署故障排查](docs/install/troubleshooting.md) | 安装、Docker、前端静态资源与运行时故障处理 |
| [环境文件说明](docs/install/env-files.md) | 根 `.env`、`backend/.env` 与安装运行时配置职责 |
| [Nginx 反向代理配置](docs/install/nginx-reverse-proxy.md) | `/`、`/client/`、`/admin/`、`/client/api/`、`/admin/api/` 等路径规则 |
| [方式三首装问题记录](docs/install/issues/docker-fullstack-first-run.md) | 方式三首次启动的密码错位、时序问题与修复结论 |

### 命令分册

| 文档 | 说明 |
|------|------|
| [常用命令速查](docs/install/commands-common.md) | 日常最常用命令：安装、启动、日志、构建、上传、清理 |
| [本地安装与 Swoole 命令](docs/install/commands-local.md) | 本地 PHP / MySQL / Redis、`install:auto`、Swoole 重启 |
| [Docker 命令](docs/install/commands-docker.md) | Docker 启停、日志、容器内依赖、连接容器服务 |
| [前端构建与静态资源命令](docs/install/commands-frontend.md) | Admin / UniApp 构建、前端 dev server、静态资源上传 |
| [删除与清理命令](docs/install/commands-cleanup.md) | 开发环境清理、安装锁、重新测试首装前检查 |
| [验证与维护命令](docs/install/commands-maintenance.md) | HTTP 验证、地区数据导入、旧环境升级、E2E 准备 |

### 前端构建与发布

| 文档 | 说明 |
|------|------|
| [后台前端（Admin）打包](docs/install/admin-build.md) | 把 `frontend/admin` 打包到 `backend/public/admin`（Docker 一键 / 本地） |
| [UniApp H5 打包](docs/install/uniapp-build.md) | 把 UniApp H5 打包到 `backend/public/client` |
| [前端静态资源上传脚本](docs/install/upload-frontend.md) | 用 `deploy/upload-frontend.sh` 上传 admin / client 到服务器 |
| [分级清理脚本](docs/install/cleanup-dev.md) | `deploy/docker/cleanup-dev.sh`：按等级清理基础运行态、前端文件、Docker 开发状态与镜像 |

### 其它

| 文档 | 说明 |
|------|------|
| [UniApp 移动端设计需求](docs/uniapp-design-brief.md) | UniApp 端功能范围、页面清单、数据结构与设计方向 |
| [运费模板路线图](docs/freight-template-roadmap.md) | 运费计算能力落地进度与订单接入计划 |
| [测试基线与触发矩阵](docs/testing/change-trigger-test-matrix.md) | 后端 / 前端测试入口与变更触发规则 |
| [Claude Code 使用指南](docs/claude-code-guide.md) | AI 工具、Skills、MCP、多 Agent 协作 |

## 开发约定

- 严格三层：Controller -> Service -> Model
- Swoole 长驻内存，Service 必须无状态
- 事务遵循"先校验再事务"
- 分页查询 list/total 条件同源
- 后台路由权限后端驱动
- 详见 `.codex/skills/` 目录下的项目规范

## 交流与反馈

- QQ 群：958717939

## 开源协议

本项目基于 MIT License 开源。
