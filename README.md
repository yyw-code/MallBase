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

- Docker / Docker Compose（单容器 / 双容器）
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
│   │   │   ├── service/            # 安装逻辑
│   │   │   └── data/               # 建表 SQL / 演示数据 / 地区数据
│   │   └── middleware/             # 全局中间件（CORS、安装检测）
│   ├── config/                     # 框架配置
│   ├── route/                      # 路由定义
│   ├── public/                     # 静态文件（前端构建产物、安装页面）
│   └── mall_base/                  # 项目基础类库（BaseController / BaseService）
│
├── frontend/
│   ├── admin/                      # Vben Admin 5 后台管理前端
│   └── uniapp/                     # UniApp 移动端（预留）
│
├── deploy/
│   └── docker/
│       ├── Dockerfile              # 单容器多阶段构建
│       ├── backend/Dockerfile      # 双容器 - 后端
│       ├── frontend/               # 双容器 - Nginx 前端
│       └── mysql/                  # MySQL 初始化脚本
│
├── docs/                           # 文档
│   ├── install.md                  # 安装指南
│   ├── freight-template-roadmap.md # 运费模板路线图
│   └── testing/
│       └── change-trigger-test-matrix.md  # 测试基线与触发矩阵
├── docker-compose.yml              # 单容器部署（默认）
├── docker-compose.prod.yml         # 双容器生产部署
├── docker-compose.dev.yml          # 开发环境（含 MySQL + Redis）
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

- 测试站：[https://mallbase.gosowong.cn](https://mallbase.gosowong.cn)

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

详细部署方式（双容器 / 开发环境 / 原生部署）见 [docs/install.md](docs/install.md)。

## 文档

| 文档 | 说明 |
|------|------|
| [安装指南](docs/install.md) | Docker / 原生部署、安装向导、配置修改 |
| [运费模板路线图](docs/freight-template-roadmap.md) | 运费计算能力落地进度与订单接入计划 |
| [测试基线与触发矩阵](docs/testing/change-trigger-test-matrix.md) | 后端 / 前端测试入口与变更触发规则 |

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
