# 方式四：Docker 生产

适合 HTTP、Queue、Cron 三个独立业务角色 + 宿主机 Nginx 的生产部署方式。

## 前提

- 服务器已安装 Docker 与 Docker Compose
- 生产环境准备了外部 MySQL 8.0+ 与 Redis 6.0+
- 宿主机可配置 Nginx，并能托管 H5 根目录和 `/admin/` 静态目录
- 客户端 H5 与后台前端静态资源将在本地开发机或 CI 上提前构建

## 完整步骤

### 1. 准备生产环境变量

生产模式只维护项目根目录 `.env`。根 `.env` 必须存在，因为 `docker-compose.yml` 使用 `env_file: .env` 向三个业务角色注入容器环境变量；但这不等于安装前必须把数据库和 Redis 全部填完。

推荐先从模板生成：

```bash
cp deploy/docker/.example.env .env
```

也可以手动创建 `.env`，只要文件位于项目根目录即可。

生成强随机值：

```bash
openssl rand -hex 16
openssl rand -hex 32
```

启动容器前建议先确认这些字段：

- `MALLBASE_COMPOSE_PROJECT_NAME`
- `APP_DEBUG=false`
- `SWOOLE_HTTP_PORT`
- `JWT_SECRET`

其中 `MALLBASE_COMPOSE_PROJECT_NAME` / `SWOOLE_HTTP_PORT` 是 Compose 启动层配置，安装流程不会修改它们。`JWT_SECRET` 如果留空或保持占位符，后端容器入口脚本会生成随机值；生产环境建议把固定强随机值写回根 `.env`，避免容器重新创建后登录 Token 签名密钥变化。

默认持久卷名为 `mallbase_runtime` 和 `mallbase_uploads`。需要复用已有卷时，在根 `.env` 显式配置 `MALLBASE_RUNTIME_VOLUME_NAME` 和 `MALLBASE_UPLOADS_VOLUME_NAME`。

下面这些字段可以提前写在根 `.env` 里，也可以先在 Web 安装向导里填写：

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `REDIS_HOST`
- `REDIS_PORT`
- `SITE_URL`

安装向导提交后，会把表单里的数据库、Redis、站点域名等配置写入容器内 `/app/.mallbase-env/backend.env`，对应宿主机 `data/backend/env/backend.env`。为了保证后续重新构建、重建容器或迁移服务器时配置不回退，安装完成后请把最终生效值同步回项目根目录 `.env`。

生产的三个业务角色不启动 MySQL / Redis 容器，所以不需要配置 `MYSQL_PORT` / `REDIS_HOST_PORT`。这两个字段只用于 Docker 开发全套模式把 MySQL / Redis 容器端口暴露给宿主机。

不要手动复制或编辑 `backend/.env`。生产 `docker-compose.yml` 会通过 `env_file: .env` 把根 `.env` 注入后端容器；容器入口脚本会根据这些环境变量派生 `/app/.mallbase-env/backend.env`。

同一台服务器部署主站和演示站时，给演示站使用独立的 Compose 名称、容器名前缀和后端宿主机端口。例如：

```env
MALLBASE_COMPOSE_PROJECT_NAME=mallbase-demo
SWOOLE_HTTP_PORT=18080
SITE_URL=https://demo.example.com
```

### 2. 构建后台前端静态资源

在本地开发机或 CI 上执行：

```bash
cd frontend/admin
pnpm install
pnpm run build --filter=@vben/web-antd
```

构建前确认 `frontend/admin/apps/web-antd/.env.production`：

- `VITE_BASE=/admin/`
- `VITE_GLOB_API_URL=/admin/api`

### 3. 构建 UniApp H5

在本地开发机或 CI 上执行：

```bash
docker compose -f docker-compose.uniapp-build.yml up uniapp-build
```

构建后确认：

- `backend/public/client/index.html`

### 4. 部署前端文件到服务器

方式 A：直接上传 `dist/`

```bash
scp -r frontend/admin/apps/web-antd/dist/* user@server:/var/www/mallbase/admin/
```

方式 B：如果你本地已经准备好了 `backend/public/admin` 和 `backend/public/client`，直接用项目脚本上传：

```bash
sh deploy/upload-frontend.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

完整脚本说明见 [upload-frontend.md](./upload-frontend.md)。

### 5. 准备持久目录并启动后端角色

干净检出后必须先执行宿主机预检。该脚本会校验 Agent 二进制文件，并创建 `upgrade/` 共享工作目录和 `data/backend/` 业务数据目录：

```bash
sh deploy/docker/host-preflight.sh
```

`docker-compose.yml` 对这些 bind 目录禁用了自动创建。跳过预检时，Compose 会因源目录不存在而拒绝启动。

```bash
docker compose up -d --build
```

如果这次只有根 `.env` 变化，不需要重新构建镜像，执行下面命令让 Compose 重新创建容器并注入新变量：

```bash
docker compose up -d
```

生产镜像由 Dockerfile 执行 `COPY backend/ .` 构建，因此 `backend/install/` 会随镜像进入容器内 `/app/install/`。同一镜像分别启动 `backend`、`queue`、`cron` 三个业务角色。安装完成标记写入 `/app/runtime/install/install.lock`，由 `backend_runtime` volume 持久化。

生产镜像也会把项目根目录 `.version` 复制到容器 `/.version`，用于安装页和状态页展示版本信息。生产 compose 不挂载 `/workspace`，后端容器不会读取项目根目录文件，只读取 Compose 注入的环境变量。

后端 PHP 依赖也在 Dockerfile 构建阶段处理：镜像会安装生产依赖并执行优化后的自动加载生成。生产 Docker 部署时不需要进入容器手动执行 `composer install --no-dev --optimize-autoloader`。

### 6. 理解生产容器文件与数据卷

生产模式和本地开发模式不同，不会把项目源码目录 bind mount 到容器：

| 来源 | 容器路径 | 类型 | 用途 |
|------|----------|------|------|
| Docker 镜像 | `/app` | 镜像内文件 | 后端代码、`vendor`、`install`、已随镜像打入的静态文件。 |
| Docker 镜像 | `/.version` | 镜像内文件 | 安装页和状态页展示版本信息。 |
| 根 `.env` | 容器环境变量 | `env_file` 注入 | Compose 读取项目根 `.env`，启动时注入容器；入口脚本再派生 `/app/.mallbase-env/backend.env`。 |
| `backend_runtime` volume | `/app/runtime` | 命名 volume | 运行时缓存、日志、安装锁等持久化数据。 |
| `backend_uploads` volume | `/app/public/uploads` | 命名 volume | 用户上传文件持久化数据。 |
| `data/backend/env` | `/app/.mallbase-env` | bind 目录 | 后端运行环境配置。 |
| `data/backend/cert` | `/app/storage/cert` | bind 目录 | 支付等业务证书。 |
| `data/backend/demo` | `/app/public/static/demo` | bind 目录 | 演示素材。 |
| `data/backend/public-storage` | `/app/public/storage` | bind 目录 | public storage 业务文件。 |
| `upgrade/config`、`upgrade/run`、`upgrade/jobs`、`upgrade/backups` | `/app/upgrade/...` | bind 目录 | PHP 与宿主机 Go 升级程序的共享工作区。 |

生产 compose 不挂载 `/workspace`，也不会把服务器上的项目根目录映射进容器。重建镜像后，代码来自新镜像；运行时数据和上传文件继续由两个命名 volume 保留。

如果生产环境选择 Nginx 直接托管静态资源，前端文件在宿主机 Nginx 目录；如果选择所有请求统一代理到 Swoole，需要确保前端产物已经进入镜像，或额外挂载到容器内 `/app/public/admin` 和 `/app/public/client`。

### 7. 配置宿主机 Nginx

```bash
sudo cp deploy/nginx/mallbase.conf /etc/nginx/sites-available/mallbase.conf
sudo ln -s /etc/nginx/sites-available/mallbase.conf /etc/nginx/sites-enabled/
sudo vim /etc/nginx/sites-available/mallbase.conf
sudo nginx -t
sudo systemctl reload nginx
```

路径规则与示例配置见 [nginx-reverse-proxy.md](./nginx-reverse-proxy.md)。

### 8. 访问安装向导

浏览器打开：

```bash
https://mall.example.com/install
```

按向导填写数据库、Redis 和管理员账号。

安装流程完成后会自动执行：

- 从 `/app/install/data/schema` 导入首装 SQL
- 路由权限同步
- 设置菜单权限同步
- 地区数据导入

### 9. 重启容器

安装向导写入配置后，重启后端容器：

```bash
docker compose restart
```

重启不会丢失安装状态；`install.lock` 位于 `/app/runtime/install/install.lock`，生产 compose 已将 `/app/runtime` 挂载为命名 volume。

如果后续执行 `docker compose up -d --build` 重建容器，容器会重新根据根 `.env` 派生 `/app/.mallbase-env/backend.env`。因此生产环境长期配置应回写到根 `.env`，不要依赖容器内临时修改过的运行环境文件。

### 10. 升级或回滚后重建业务角色

Go 升级程序修改的是宿主机项目源码，生产容器运行的是镜像内代码。因此升级或回滚完成后，仅执行 `docker compose restart` 不会加载新代码。请重新构建镜像并重建全部三个业务角色：

```bash
docker compose up -d --build backend queue cron
```

Go 程序不会执行 Docker、`systemctl` 或服务重启命令。非 Docker 部署则按原运行方式先重启 Queue/Cron，最后重启 HTTP。

## 完成后验证

```bash
docker ps
curl -I http://127.0.0.1:8080/
ls /var/www/mallbase/client/index.html
ls /var/www/mallbase/admin/index.html
```

浏览器访问：

- `/`
- `/install`
- `/admin/`

## 常见下一步

- 上传静态资源脚本：[upload-frontend.md](./upload-frontend.md)
- Nginx 代理与静态目录：[nginx-reverse-proxy.md](./nginx-reverse-proxy.md)
- 常用运维命令：[commands-common.md](./commands-common.md)
- 安装与部署排障：[troubleshooting.md](./troubleshooting.md)
