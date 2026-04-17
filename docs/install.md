# MallBase 安装指南

## 环境要求

### Docker 部署（方式一 ~ 三）

| 依赖 | 版本 |
|------|------|
| Docker | >= 20.10 |
| Docker Compose | >= 2.0 |
| MySQL | >= 8.0（外部服务，方式三自带） |
| Redis | >= 6.0（外部服务，方式三自带） |

> Docker 镜像已内置 PHP 8.2、Swoole、Node.js 22 等构建和运行依赖，无需在宿主机安装。

### 原生部署（方式四）

| 依赖 | 版本 | 来源 |
|------|------|------|
| PHP | >= 8.2 | `composer.json` |
| Swoole 扩展 | >= 4.2.9（推荐 5.0+） | `think-swoole` 依赖链 `open-smf/connection-pool` |
| Redis 扩展 (phpredis) | >= 5.3.4（推荐 6.0+） | PHP 8.2 兼容性要求 |
| MySQL | >= 8.0 | `docker-compose.dev.yml` |
| Redis | >= 6.0 | `docker-compose.dev.yml` |
| Composer | >= 2.0 | `Dockerfile` |
| Node.js | >= 20.19.0（仅构建前端） | `frontend/admin/package.json` engines |
| pnpm | >= 10.0.0（仅构建前端） | `frontend/admin/package.json` engines |

## 方式一：Docker 一键部署（推荐）

单容器运行（前端 + 后端），MySQL 和 Redis 由用户自行准备（本地安装或云服务）。

> 以下所有 `docker compose` 命令均在**项目根目录**（`mall-base/`）执行。

```bash
cd mall-base

# 构建并启动
docker compose up -d --build

# 访问安装向导
# http://localhost（默认端口 80）
```

安装向导会引导你完成：
1. 环境检测
2. 填写 MySQL 连接信息
3. 填写 Redis 连接信息
4. 创建管理员账号 + 配置 CORS 跨域地址
5. 可选导入演示数据

**安装完成后必须重启容器（使 .env 配置生效）：**

```bash
docker compose restart
```

重启后访问 `/admin` 进入后台。

### 自定义端口

```bash
# 在项目根目录执行
APP_PORT=8080 docker compose up -d --build
```

### 自定义前端 API 地址

```bash
# 在项目根目录执行
VITE_GLOB_API_URL=/api docker compose up -d --build
```

## 方式二：Docker 双容器（生产环境）

前端走 Nginx（静态文件 + 反向代理），后端走 Swoole。适合需要 Nginx 做 SSL / 反向代理的生产场景。

```bash
# 在项目根目录执行
docker compose -f docker-compose.prod.yml up -d --build
```

- 前端：`http://localhost`（端口 80）
- 后端 API：`http://localhost:8080`

环境变量参考 `deploy/docker/.env.example`。

**安装完成后重启：**

```bash
# 在项目根目录执行
docker compose -f docker-compose.prod.yml restart
```

> 如需 SSL 证书或负载均衡，建议在容器外层加一层入口 Nginx / Traefik / 云 ALB，当前容器内的 `nginx.conf` 无需修改。

## 方式三：Docker 开发环境（含 MySQL + Redis）

在单容器基础上叠加 MySQL 8.0 和 Redis 7 容器，适合本地开发。

```bash
# 在项目根目录执行
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

MySQL/Redis 数据持久化在项目根目录的 `data/` 目录。

此模式下 MySQL 容器会自动导入建表 SQL，但仍需通过安装向导配置 .env 和创建管理员。安装向导中数据库地址填 `mysql`（容器名），Redis 地址填 `redis`。

**安装完成后重启：**

```bash
# 在项目根目录执行
docker compose -f docker-compose.yml -f docker-compose.dev.yml restart
```

## 方式四：原生部署

### 1. 安装后端依赖

```bash
# 从项目根目录进入 backend
cd mall-base/backend
composer install
```

### 2. 启动 Swoole 服务

```bash
# 在 backend/ 目录下执行
php think swoole
```

默认监听 `0.0.0.0:8080`。

### 3. 访问安装向导

浏览器打开 `http://your-server:8080`，按向导完成安装。

### 4. 重启服务（使 .env 配置生效）

```bash
# 在 backend/ 目录下执行
php think swoole:stop
php think swoole
```

重启后访问 `/admin` 进入后台。

### 5. 前端单独构建（可选）

如果需要修改前端代码后重新构建：

```bash
# 从项目根目录进入前端目录
cd mall-base/frontend/admin
pnpm install
pnpm run build --filter=@vben/web-antd
```

构建产物复制到 `backend/public/admin/`。

## 安装后

- 后台地址：`/admin`
- 使用安装时创建的管理员账号登录
- 如需导入地区数据：在 `backend/` 目录下执行 `php think region:import`

## 安装后修改配置

安装向导会自动生成 `backend/.env` 文件。如需修改配置（如 CORS、JWT、调试模式等），编辑此文件后重启服务。

### Docker 部署

```bash
# 进入容器（容器内工作目录为 /app，即 backend）
docker exec -it mallbase sh
vi .env

# 编辑完成后退出容器，重启使配置生效
exit
docker restart mallbase
```

如使用 Docker Compose（在项目根目录执行）：

```bash
docker compose restart
# 或双容器：
docker compose -f docker-compose.prod.yml restart
```

### 原生部署

```bash
# 在 backend/ 目录下执行
vi .env
php think swoole:stop && php think swoole
```

### 常用配置项

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| `APP_DEBUG` | 调试模式（生产环境必须关闭） | `false` |
| `JWT_SECRET` | JWT 签名密钥（安装时自动生成） | 随机值 |
| `JWT_EXPIRE` | 访问令牌有效期（秒） | `7200` |
| `JWT_REFRESH_EXPIRE` | 刷新令牌有效期（秒） | `2592000` |
| `CORS_ALLOWED_ORIGINS` | 允许跨域的前端地址（逗号分隔，`*` 全部允许） | 安装时配置 |
| `CORS_ALLOW_METHODS` | 允许的 HTTP 方法 | `GET,POST,PUT,DELETE,OPTIONS` |
| `CORS_ALLOW_HEADERS` | 允许的请求头 | `Authorization,Content-Type,X-Requested-With` |
| `SWOOLE_WORKER_NUM` | Worker 进程数（`0` = CPU 核数自动） | `0` |
| `SWOOLE_MAX_REQUEST` | Worker 处理请求上限后重启（防内存泄漏） | `2000` |
| `CACHE_DRIVER` | 缓存驱动（`redis` / `file`） | `redis` |

完整配置项参考 `backend/.example.env`。

## 目录结构

```
backend/app/install/          # 安装模块
├── controller/               # 安装接口
├── service/                  # 安装逻辑
└── data/                     # 安装数据
    ├── schema/               # 建表 SQL（按序号排列）
    ├── demo/                 # 演示数据
    └── region/               # 地区数据
```

## 常见问题

### 安装完成后页面报错

安装向导写入 `.env` 后，Swoole 进程仍使用旧配置。**必须重启服务**才能加载新的数据库和 Redis 配置。

### Docker 容器内连接宿主机 MySQL/Redis

使用 `host.docker.internal` 作为数据库/Redis 地址（Docker Desktop 自动支持，Linux 需要 `extra_hosts` 配置，`docker-compose.yml` 已包含）。

### 重新安装

删除 `backend/install.lock` 文件后重启服务，即可重新进入安装向导。

### 验证 CORS 配置

```bash
# 白名单 Origin（应返回 204 + Access-Control-Allow-Origin）
curl -i -X OPTIONS 'http://127.0.0.1:8080/' \
  -H 'Origin: http://localhost:3000' \
  -H 'Access-Control-Request-Method: GET'

# 非白名单 Origin（应返回 403）
curl -i -X OPTIONS 'http://127.0.0.1:8080/' \
  -H 'Origin: http://evil.example.com' \
  -H 'Access-Control-Request-Method: GET'
```

## 交流与反馈

- QQ 群：958717939
