# MallBase 文档中心

这是项目文档的总入口。常用文档放在前面，不常用文档也在这里统一索引，避免只靠记忆在目录里翻。

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
| [claude-code-guide.md](./claude-code-guide.md) | Claude Code 使用指南 |

## 后续文档站建议

当前阶段建议先做“文档中心 + 命令分册 + 排障页”，不急着单独建设论坛。

更稳的演进顺序：

1. 先把仓库内 Markdown 索引完整，保证 README 和 `docs/index.md` 能找到所有文档。
2. 用户常问的问题沉淀到 [install/troubleshooting.md](./install/troubleshooting.md)。
3. 外部用户反馈变多后，用 GitHub Issues 或 Discussions 承接问答。
4. 文档访问量稳定后，再用 VitePress 或 Docusaurus 把 `docs/` 发布成文档网站。

论坛适合多人问答和社区沉淀；安装、部署、命令这类内容更适合结构化文档。先把结构化文档做好，后面再接论坛会更省维护成本。
