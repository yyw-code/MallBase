# MallBase 文档中心

这是项目文档的总入口。常用文档放在前面，不常用文档也在这里统一索引，避免只靠记忆在目录里翻。

官方网站：[https://platform.gosowong.cn/](https://platform.gosowong.cn/)

本地查找建议先用：

```bash
rg -n "会员|积分|余额|装修|充值|成长值" README.md docs
```

## 我该先看哪个

| 场景 | 推荐入口 |
|------|----------|
| 第一次安装 | [install/index.md](./install/index.md) |
| 本地安装页面失败，改用命令安装 | [install/cli-install.md](./install/cli-install.md) |
| 只想查一条命令 | [install/commands.md](./install/commands.md) |
| 命令报错或页面打不开 | [install/troubleshooting.md](./install/troubleshooting.md) |
| 不确定 `.env` 改哪一份 | [install/env-files.md](./install/env-files.md) |
| 配 Nginx / 静态资源路径 | [install/nginx-reverse-proxy.md](./install/nginx-reverse-proxy.md) |
| 配置上传云存储 | [install/cloud-storage-upload.md](./install/cloud-storage-upload.md) |
| 新增云存储驱动 | [upload-storage-driver-extension.md](./upload-storage-driver-extension.md) |
| 查看隐私与平台统计范围 | [privacy.md](./privacy.md) |
| 做前端构建或上传 | [install/commands-frontend.md](./install/commands-frontend.md) |
| 看测试入口 | [testing/change-trigger-test-matrix.md](./testing/change-trigger-test-matrix.md) |
| 看订单压测结果 | [testing/order-create-1000-concurrency-report.md](./testing/order-create-1000-concurrency-report.md) |
| 配 Swoole 并发参数 | [testing/swoole-concurrency-config-guide.md](./testing/swoole-concurrency-config-guide.md) |
| 查业务文档入口 | [faq.md](./faq.md) |
| 配置会员、积分、余额、装修 | [operation/index.md](./operation/index.md) |
| 理解会员、积分、余额、装修逻辑 | [logic/index.md](./logic/index.md) |
| 开发会员、积分、余额、装修功能 | [development/index.md](./development/index.md) |

## 文档入口

| 文档 | 说明 |
|------|------|
| [faq.md](./faq.md) | 业务文档常见问题和入口跳转 |
| [modules/member.md](./modules/member.md) | 会员模块总览：按角色跳转到操作、逻辑和开发文档 |
| [modules/points.md](./modules/points.md) | 积分模块总览：按角色跳转到操作、逻辑和开发文档 |
| [modules/wallet.md](./modules/wallet.md) | 余额模块总览：按角色跳转到操作、逻辑和开发文档 |
| [modules/client-diy.md](./modules/client-diy.md) | 客户端装修模块总览：按角色跳转到操作、逻辑和开发文档 |
| [operation/index.md](./operation/index.md) | 操作文档：后台在哪里配置、怎么操作、配置后前台有什么变化 |
| [logic/index.md](./logic/index.md) | 业务逻辑文档：业务规则、计算口径、状态流 |
| [development/index.md](./development/index.md) | 开发文档：表、接口、Service、前端页面、扩展点和测试 |

## 操作文档

| 文档 | 说明 |
|------|------|
| [operation/basic-config.md](./operation/basic-config.md) | 上传、支付、积分、会员、客户端配置等基础配置入口 |
| [operation/member.md](./operation/member.md) | 会员配置、会员等级、手动设置会员 |
| [operation/points.md](./operation/points.md) | 积分配置、积分规则、积分商品、兑换单和积分流水 |
| [operation/wallet.md](./operation/wallet.md) | 余额支付、充值套餐、用户余额调整和余额流水 |
| [operation/client-diy.md](./operation/client-diy.md) | 首页、个人中心、底部导航、悬浮按钮和主题装修 |

## 模块总览

| 文档 | 说明 |
|------|------|
| [modules/member.md](./modules/member.md) | 会员等级、成长值、会员权益和订单快照总览 |
| [modules/points.md](./modules/points.md) | 积分账户、积分规则、积分抵扣、积分商城和兑换单总览 |
| [modules/wallet.md](./modules/wallet.md) | 用户余额、余额流水、余额支付、余额退款和充值套餐总览 |
| [modules/client-diy.md](./modules/client-diy.md) | 页面库、首页/个人中心装修、底部导航、悬浮按钮和主题总览 |

## 业务逻辑

| 文档 | 说明 |
|------|------|
| [logic/member.md](./logic/member.md) | 成长值、等级匹配、手动等级、会员折扣和订单快照 |
| [logic/points.md](./logic/points.md) | 积分账户、赠送、冻结、释放、回收、抵扣和兑换 |
| [logic/wallet.md](./logic/wallet.md) | 余额账户、余额流水、余额支付、余额退款和充值套餐边界 |
| [logic/client-diy.md](./logic/client-diy.md) | 页面库、装修方案、系统默认、启用策略、主题和客户端读取 |

## 开发文档

| 文档 | 说明 |
|------|------|
| [development/member.md](./development/member.md) | 会员等级、成长值、会员权益和订单快照开发入口 |
| [development/points.md](./development/points.md) | 积分账户、积分规则、积分抵扣、积分商城和兑换单开发入口 |
| [development/wallet.md](./development/wallet.md) | 余额账户、余额流水、余额支付、余额退款和充值套餐开发入口 |
| [development/client-diy.md](./development/client-diy.md) | 页面库、装修方案、主题、配置读取和 UniApp 渲染开发入口 |
| [客服 H5 资源跳转设计](./development/customer-service-h5-resource-action.md) | 客服 Widget 商品与订单资源地址模板，以及 H5 顶层跳转的安全边界 |

## 安装与部署

| 文档 | 说明 |
|------|------|
| [install/index.md](./install/index.md) | 安装与部署导航：按方式选择完整步骤 |
| [install/manual.md](./install/manual.md) | 方式一：手动安装（无 Docker） |
| [install/docker-backend-only.md](./install/docker-backend-only.md) | 方式二：Docker 开发（仅后端） |
| [install/docker-fullstack.md](./install/docker-fullstack.md) | 方式三：Docker 开发（全套） |
| [install/docker-production.md](./install/docker-production.md) | 方式四：Docker 生产 |
| [install/cli-install.md](./install/cli-install.md) | 本地安装失败后的 `php think install:auto` 命令行安装 |
| [install/troubleshooting.md](./install/troubleshooting.md) | 安装、Docker、静态资源与运行时排障 |
| [install/env-files.md](./install/env-files.md) | 根 `.env`、`backend/.env` 与运行时配置职责 |
| [install/nginx-reverse-proxy.md](./install/nginx-reverse-proxy.md) | Nginx 反向代理与静态资源路径规则 |
| [install/cloud-storage-upload.md](./install/cloud-storage-upload.md) | 本地存储、阿里云 OSS、腾讯云 COS 上传驱动配置与验证 |
| [install/issues/docker-fullstack-first-run.md](./install/issues/docker-fullstack-first-run.md) | Docker 全套首次启动问题记录 |

## 命令分册

| 文档 | 说明 |
|------|------|
| [install/commands.md](./install/commands.md) | 命令导航入口 |
| [install/commands-common.md](./install/commands-common.md) | 常用命令速查 |
| [install/commands-local.md](./install/commands-local.md) | 本地安装、`install:auto`、Swoole 命令 |
| [install/commands-docker.md](./install/commands-docker.md) | Docker 启停、日志、容器内依赖 |
| [install/commands-frontend.md](./install/commands-frontend.md) | Admin / UniApp 构建和静态资源上传 |
| [install/commands-cleanup.md](./install/commands-cleanup.md) | 删除、清理、重新测试首装前检查 |
| [install/commands-maintenance.md](./install/commands-maintenance.md) | 验证、补同步、旧环境升级、E2E 准备 |

## 前端构建与发布

| 文档 | 说明 |
|------|------|
| [install/admin-build.md](./install/admin-build.md) | 后台前端 Admin 打包到 `backend/public/admin` |
| [install/uniapp-build.md](./install/uniapp-build.md) | UniApp H5 打包到 `backend/public/client` |
| [install/upload-frontend.md](./install/upload-frontend.md) | 上传 admin / client 静态资源到服务器 |
| [install/cleanup-dev.md](./install/cleanup-dev.md) | 分级清理基础运行态、前端文件、Docker 开发状态与镜像 |

## 项目与协作

| 文档 | 说明 |
|------|------|
| [uniapp-design-brief.md](./uniapp-design-brief.md) | UniApp 移动端设计需求 |
| [freight-template-roadmap.md](./freight-template-roadmap.md) | 运费模板路线图 |
| [upload-storage-driver-extension.md](./upload-storage-driver-extension.md) | 新增云存储服务商时需要修改的后端、前端、seed、测试和文档清单 |
| [privacy.md](./privacy.md) | 平台实例统计的数据范围、本地状态与关闭方式 |
| [testing/change-trigger-test-matrix.md](./testing/change-trigger-test-matrix.md) | 测试基线与触发矩阵 |
| [testing/order-create-1000-concurrency-report.md](./testing/order-create-1000-concurrency-report.md) | 订单创建 1000 并发压测报告 |
| [testing/swoole-concurrency-config-guide.md](./testing/swoole-concurrency-config-guide.md) | Swoole worker、连接数、backlog 和连接池配置建议 |
| [claude-code-guide.md](./claude-code-guide.md) | Claude Code 使用指南 |
| [superpowers/specs/2026-06-03-client-diy-design.md](./superpowers/specs/2026-06-03-client-diy-design.md) | 客户端装修一期范围、后台信息架构和方案库模型 |
| [superpowers/plans/2026-06-03-client-diy-foundation-plan.md](./superpowers/plans/2026-06-03-client-diy-foundation-plan.md) | 客户端装修后端基础能力的历史实施计划 |

## 后续补充建议

当前阶段优先把业务文档补完整，再考虑其它形式的展示入口。

后续建议按模块继续补：

1. 商品、订单、售后、物流继续按“操作 / 逻辑 / 开发”三类补文档。
2. 每次新增配置项、业务规则或接口时，同步更新对应文档。
3. 高频问题先补到 [faq.md](./faq.md)，再沉淀到具体专题文档。
