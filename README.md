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
│   ├── faq.md                      # 业务文档常见问题与入口跳转
│   ├── modules/                    # 业务模块总览
│   │   ├── member.md               # 会员模块总览
│   │   ├── points.md               # 积分模块总览
│   │   ├── wallet.md               # 余额模块总览
│   │   └── client-diy.md           # 客户端装修模块总览
│   ├── operation/                  # 操作文档
│   │   ├── index.md                # 操作文档入口
│   │   ├── basic-config.md         # 基础配置操作说明
│   │   ├── member.md               # 会员操作说明
│   │   ├── points.md               # 积分操作说明
│   │   ├── wallet.md               # 余额操作说明
│   │   └── client-diy.md           # 客户端装修操作说明
│   ├── logic/                      # 业务逻辑文档
│   │   ├── index.md                # 业务逻辑文档入口
│   │   ├── member.md               # 会员业务逻辑
│   │   ├── points.md               # 积分业务逻辑
│   │   ├── wallet.md               # 余额业务逻辑
│   │   └── client-diy.md           # 客户端装修业务逻辑
│   ├── development/                # 开发文档
│   │   ├── index.md                # 开发文档入口
│   │   ├── member.md               # 会员开发文档
│   │   ├── points.md               # 积分开发文档
│   │   ├── wallet.md               # 余额开发文档
│   │   └── client-diy.md           # 客户端装修开发文档
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
│   │   ├── cloud-storage-upload.md # 上传云存储配置（本地 / OSS / COS）
│   │   ├── admin-build.md          # 后台前端（Admin）打包说明
│   │   ├── uniapp-build.md         # UniApp H5 打包说明
│   │   ├── upload-frontend.md      # 前端静态资源上传脚本说明
│   │   ├── cleanup-dev.md          # 分级清理脚本说明
│   │   └── issues/
│   │       └── docker-fullstack-first-run.md  # 方式三首装问题排查记录
│   ├── uniapp-design-brief.md      # UniApp 移动端设计需求文档
│   ├── freight-template-roadmap.md # 运费模板路线图
│   ├── upload-storage-driver-extension.md # 新增云存储上传驱动开发指南
│   ├── privacy.md                  # 隐私与平台实例统计说明
│   ├── claude-code-guide.md        # Claude Code 使用指南
│   ├── superpowers/                # 历史规划与设计档案
│   │   ├── specs/                  # 功能设计方案
│   │   └── plans/                  # 实施计划
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
| Member | 会员等级、成长值、会员折扣、规格会员价 |
| Points | 积分账户、积分赠送、积分抵扣、积分商城 |
| Wallet | 用户余额、余额流水、余额支付、余额退款 |
| Client DIY | 页面库、首页/个人中心装修、底部导航、主题 |

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

### 文档入口

| 文档 | 说明 |
|------|------|
| [文档中心](docs/index.md) | 所有文档的总入口：按场景查常用与不常用文档 |
| [业务文档常见问题](docs/faq.md) | 会员、积分、余额、客户端装修等业务文档入口 |
| [会员模块总览](docs/modules/member.md) | 会员等级、成长值、会员权益和订单快照总览 |
| [积分模块总览](docs/modules/points.md) | 积分账户、积分规则、积分抵扣、积分商城和兑换单总览 |
| [余额模块总览](docs/modules/wallet.md) | 用户余额、余额流水、余额支付、余额退款和充值套餐总览 |
| [客户端装修模块总览](docs/modules/client-diy.md) | 页面库、装修方案、底部导航、悬浮按钮和主题总览 |

### 操作文档

| 文档 | 说明 |
|------|------|
| [操作文档入口](docs/operation/index.md) | 后台操作文档总入口 |
| [基础配置操作文档](docs/operation/basic-config.md) | 上传、支付、积分、会员、客户端配置等基础配置入口 |
| [会员操作文档](docs/operation/member.md) | 会员配置、会员等级、手动设置会员 |
| [积分操作文档](docs/operation/points.md) | 积分配置、积分规则、积分商品、兑换单和积分流水 |
| [余额操作文档](docs/operation/wallet.md) | 余额支付、充值套餐、用户余额调整和余额流水 |
| [客户端装修操作文档](docs/operation/client-diy.md) | 首页、个人中心、底部导航、悬浮按钮和主题装修 |

### 业务逻辑

| 文档 | 说明 |
|------|------|
| [业务逻辑文档入口](docs/logic/index.md) | 核心业务规则和状态流总入口 |
| [会员业务逻辑](docs/logic/member.md) | 成长值、等级匹配、手动等级、会员折扣和订单快照 |
| [积分业务逻辑](docs/logic/points.md) | 积分账户、赠送、冻结、释放、回收、抵扣和兑换 |
| [余额业务逻辑](docs/logic/wallet.md) | 余额账户、余额流水、余额支付、余额退款和充值套餐边界 |
| [客户端装修业务逻辑](docs/logic/client-diy.md) | 页面库、装修方案、系统默认、启用策略、主题和客户端读取 |

### 开发文档

| 文档 | 说明 |
|------|------|
| [开发文档入口](docs/development/index.md) | 表、接口、Service、前端页面、扩展点和测试入口 |
| [会员开发文档](docs/development/member.md) | 会员等级、成长值、会员权益和订单快照开发入口 |
| [积分开发文档](docs/development/points.md) | 积分账户、积分规则、积分抵扣、积分商城和兑换单开发入口 |
| [余额开发文档](docs/development/wallet.md) | 余额账户、余额流水、余额支付、余额退款和充值套餐开发入口 |
| [客户端装修开发文档](docs/development/client-diy.md) | 页面库、装修方案、主题、配置读取和 UniApp 渲染开发入口 |

### 安装与部署

| 文档 | 说明 |
|------|------|
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
| [上传云存储配置](docs/install/cloud-storage-upload.md) | 本地存储、阿里云 OSS、腾讯云 COS 上传驱动配置与验证 |
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
| [新增云存储上传驱动开发指南](docs/upload-storage-driver-extension.md) | 新增云存储服务商时需要修改的后端、前端、seed、测试和文档清单 |
| [隐私与平台实例统计说明](docs/privacy.md) | 平台实例统计的数据范围、本地状态与关闭方式 |
| [测试基线与触发矩阵](docs/testing/change-trigger-test-matrix.md) | 后端 / 前端测试入口与变更触发规则 |
| [Claude Code 使用指南](docs/claude-code-guide.md) | AI 工具、Skills、MCP、多 Agent 协作 |
| [客户端装修功能设计方案](docs/superpowers/specs/2026-06-03-client-diy-design.md) | 客户端装修一期范围、后台信息架构和方案库模型 |
| [客户端装修基础实施计划](docs/superpowers/plans/2026-06-03-client-diy-foundation-plan.md) | 客户端装修后端基础能力的历史实施计划 |

## 开发约定

- 严格三层：Controller -> Service -> Model
- Swoole 长驻内存，Service 必须无状态
- 事务遵循"先校验再事务"
- 分页查询 list/total 条件同源
- 后台路由权限后端驱动
- 详见 `.codex/skills/` 目录下的项目规范

## 交流与反馈

- QQ 群：958717939
- 微信号：yyw1329847115

## 开源协议

本项目基于 MIT License 开源。
