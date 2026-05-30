# Docker 全套模式首装问题记录

> 这是一份历史问题记录，针对“方式三默认自动执行 `install:auto`”时期的首装时序问题。当前默认流程已改为服务启动后由用户访问 `/install` 确认安装；本文保留，是因为其中关于 `ensure-env`、MySQL 变量桥接和密码轮换的结论仍然有效。

## 适用范围

- 旧版方式三：Docker 开发（全套，默认自动安装）
- 命令：`docker compose -f docker-compose.dev.yml up -d`

## 现象

在完全清理后首次执行 `docker compose -f docker-compose.dev.yml up -d`，曾经出现过以下问题：

- `<prefix>-mysql` 第一次启动不健康，第二次又恢复正常
- `<prefix>-check-db-auth` 首次执行报业务库账号认证失败
- `<prefix>-install-auto` 因依赖失败没有真正开始执行
- `deploy/install/install.lock` 没有生成
## 根因分析

这次问题实际由两类初始化时序问题叠加导致。

### 1. Compose 解析时机早于 `ensure-env`

根目录 `.env` 由 `ensure-env` 容器在运行时生成，但 Compose 的 `${VAR}` 插值和 `env_file` 读取发生在容器启动前。

如果 MySQL 直接依赖 Compose 插值：

- 首次启动时，`mysql` 可能使用默认值或旧值初始化
- 而后续 `check-db-auth`、`backend`、`install-auto` 读取到的又是刚生成的新 `.env`
- 结果就是同一次启动里，不同服务使用了不同密码

这不是单纯“加延迟”可以解决的问题，因为问题发生在 Compose 解析阶段，而不是容器健康检查阶段。

### 2. MySQL 官方镜像首装识别的是 `MYSQL_*` 变量

项目根 `.env` 的主配置字段是：

- `DB_NAME`
- `DB_USER`
- `DB_PASS`

但 MySQL 官方镜像在首次初始化业务库和业务账号时，真正识别的是：

- `MYSQL_DATABASE`
- `MYSQL_USER`
- `MYSQL_PASSWORD`

如果只把根 `.env` 读进容器，却没有把 `DB_*` 桥接到 `MYSQL_*`：

- `MYSQL_ROOT_PASSWORD` 可能是正确的
- `root` 健康检查也可能通过
- 但业务库和业务账号不会按预期初始化
- 最终导致 `check-db-auth` 使用 `DB_USER/DB_PASS` 登录失败

## 已采用的修复方案

### 1. 根 `.env` 改为 Docker 全套模式唯一主配置源

- 用户只编辑项目根目录 `.env`
- `backend/.env` 由 `ensure-env.sh` 自动派生
- `backend/.env` 顶部增加中文说明，明确“不要手改”

### 2. 改为运行时读取 `.env`

- `mysql` 不再依赖 Compose 预插值拿密码
- `backend` 和 `install-auto` 也不再依赖 Compose 预加载 `backend/.env`
- 改为在容器入口脚本中运行时读取生成后的配置文件

### 3. MySQL 首装增加变量桥接

在 `deploy/docker/mysql-entrypoint.sh` 中增加以下桥接：

- `DB_NAME -> MYSQL_DATABASE`
- `DB_USER -> MYSQL_USER`
- `DB_PASS -> MYSQL_PASSWORD`

这样 MySQL 在首次初始化时，`root` 密码、业务库名、业务账号、业务密码都会来自同一份根 `.env`。

### 4. 增加业务库预检查

新增 `check-db-auth` 一次性容器：

- 在 `install-auto` 前先验证 `DB_USER/DB_PASS` 是否可以登录
- 如果是“旧 `data/mysql` + 新 `DB_PASS`”场景，会更早给出明确提示

### 5. 增加显式密码轮换工具

新增 `rotate-db-password` 工具容器：

- 当用户要保留已有 `data/mysql`，但修改了根 `.env` 的 `DB_PASS`
- 通过 root 账号显式执行业务账号密码轮换
- 避免“改了配置但库里真实密码没变”的假成功状态

## 验证结果

按以下顺序执行：

```bash
sh deploy/docker/cleanup-dev.sh --all-images
docker compose -f docker-compose.dev.yml up -d
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

本次已验证首次启动可以直接跑通：

- `<prefix>-ensure-env`：`Exited (0)`
- `<prefix>-mysql`：`healthy`
- `<prefix>-check-db-auth`：`Exited (0)`
- `<prefix>-install-auto`：`Exited (0)`
- `<prefix>-dev`：`Up`
- `<prefix>-frontend-build`：`Exited (0)`
- `deploy/install/install.lock` 成功生成
- `backend/public/admin` 成功生成后台静态资源
- `http://127.0.0.1:8080` 返回 `HTTP/1.1 200 OK`

## 经验结论

- Docker 全套模式下，配置源必须收敛到单一入口
- Compose 解析期和容器运行期是两套时序，不能混用同一类假设
- MySQL 官方镜像的初始化变量名必须和项目主配置名做桥接
- 对“已有数据 + 修改密码”场景，必须提供显式轮换工具，不能靠自动猜测
