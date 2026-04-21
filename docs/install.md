# MallBase 安装指南

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

---

## 选择安装方式

| 方式 | 容器数 | 适合场景 | 前端 |
|------|-------|---------|------|
| [方式一：手动安装](#方式一手动安装无-docker) | 0 | 低配服务器、完全控制 | 宿主机 Nginx / 或拷进 `backend/public/admin` |
| [方式二：Docker 开发（仅后端）](#方式二docker-开发仅后端容器) | 1 | 本地开发、已有 MySQL/Redis | 本地 `pnpm dev:antd` |
| [方式三：Docker 开发（全套）](#方式三docker-开发全套) | 3（+ 可选前端打包容器） | 本地开发、一键启动 | Docker `--profile build` 自动打包 |
| [方式四：Docker 生产](#方式四docker-生产) | 1 | 生产部署 | CI 打包后拷进 `backend/public/admin` 或宿主 Nginx |

---

## 方式一：手动安装（无 Docker）

适合低配服务器、需要完全控制环境的场景。

### 1. 安装 PHP 8.2 + 扩展

#### Ubuntu / Debian

```bash
# 添加 PHP 仓库
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php
sudo apt update

# 安装 PHP 和扩展
sudo apt install -y \
    php8.2-cli \
    php8.2-dev \
    php8.2-mysql \
    php8.2-redis \
    php8.2-mbstring \
    php8.2-gd \
    php8.2-zip \
    php8.2-intl \
    php8.2-bcmath \
    php8.2-opcache \
    php8.2-xml \
    php8.2-curl

# 安装 Swoole 扩展
pecl install swoole
echo "extension=swoole.so" | sudo tee /etc/php/8.2/cli/conf.d/20-swoole.ini
```

#### CentOS / RHEL / AlmaLinux

```bash
# 添加 Remi 仓库
sudo dnf install -y epel-release
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %rhel).rpm
sudo dnf module reset php
sudo dnf module enable php:remi-8.2

# 安装 PHP 和扩展
sudo dnf install -y \
    php-cli \
    php-devel \
    php-mysqlnd \
    php-pecl-redis \
    php-mbstring \
    php-gd \
    php-pecl-zip \
    php-intl \
    php-bcmath \
    php-opcache \
    php-xml \
    php-curl

# 安装 Swoole 扩展
pecl install swoole
echo "extension=swoole.so" | sudo tee /etc/php.d/20-swoole.ini
```

#### macOS

```bash
brew install php@8.2
pecl install swoole
pecl install redis
```

#### 验证安装

```bash
php -v
# PHP 8.2.x ...

php -m | grep -E "swoole|redis|pdo_mysql|gd|mbstring|zip|intl|bcmath|opcache"
# 应输出以上所有扩展名
```

### 2. 安装 MySQL 8.0

#### Ubuntu / Debian

```bash
sudo apt install -y mysql-server
sudo systemctl start mysql
sudo systemctl enable mysql

# 创建数据库和用户
sudo mysql -e "
CREATE DATABASE mallbase DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mallbase'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON mallbase.* TO 'mallbase'@'localhost';
FLUSH PRIVILEGES;
"
```

#### CentOS / RHEL

```bash
sudo dnf install -y mysql-server
sudo systemctl start mysqld
sudo systemctl enable mysqld
```

### 3. 安装 Redis

```bash
# Ubuntu / Debian
sudo apt install -y redis-server
sudo systemctl start redis
sudo systemctl enable redis

# CentOS / RHEL
sudo dnf install -y redis
sudo systemctl start redis
sudo systemctl enable redis

# 验证
redis-cli ping
# 输出 PONG
```

### 4. 安装 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer -V
```

### 5. 部署后端

```bash
cd mall-base/backend

# 安装 PHP 依赖（生产环境不装开发依赖）
composer install --no-dev --optimize-autoloader
```

### 6. 构建前端

```bash
# 安装 Node.js（如未安装）
# 推荐使用 nvm: https://github.com/nvm-sh/nvm
nvm install 22
nvm use 22

# 安装 pnpm
npm i -g pnpm

# 构建前端
cd mall-base/frontend/admin
pnpm install
pnpm run build --filter=@vben/web-antd
```

构建产物在 `frontend/admin/apps/web-antd/dist/`。

> 构建前确认 `frontend/admin/apps/web-antd/.env.production` 配置正确：
> - `VITE_BASE=/admin/` — 与 Nginx 的 `location /admin/` 一致
> - `VITE_GLOB_API_URL=/admin/api` — 相对路径，由 Nginx 反代到 Swoole

### 7. 部署前端文件

```bash
# 复制构建产物到 Nginx 目录
sudo mkdir -p /var/www/mallbase/admin
sudo cp -r frontend/admin/apps/web-antd/dist/* /var/www/mallbase/admin/
```

### 8. 配置 Nginx

```bash
# 复制配置文件
sudo cp deploy/nginx/mallbase.conf /etc/nginx/sites-available/mallbase.conf
sudo ln -s /etc/nginx/sites-available/mallbase.conf /etc/nginx/sites-enabled/

# 编辑配置：修改 server_name 和路径
sudo vim /etc/nginx/sites-available/mallbase.conf

# 验证配置 + 重载
sudo nginx -t
sudo systemctl reload nginx
```

> 配置文件详见 `deploy/nginx/mallbase.conf`，包含 SSL 和非 SSL 两个版本。

### 9. 启动 Swoole

```bash
cd mall-base/backend
php think swoole
```

默认监听 `0.0.0.0:8080`。

> **后台运行**：可使用 `nohup php think swoole &` 或 systemd 管理。

### 10. 访问安装向导

浏览器打开 `http://your-domain/install`，按向导完成：

1. 环境检测
2. 填写 MySQL 连接信息
3. 填写 Redis 连接信息
4. 创建管理员账号 + 配置 CORS
5. 可选导入演示数据

### 11. 重启 Swoole

安装向导会生成 `backend/.env` 配置文件，**必须重启 Swoole 才能加载**：

```bash
# 找到 Swoole 主进程并杀掉
lsof -ti :8080 | xargs kill

# 重新启动
cd mall-base/backend
php think swoole
```

重启后访问 `/admin` 进入后台管理系统。

### 方式一利弊

| 优点 | 缺点 |
|------|------|
| 资源占用最低 | 环境配置繁琐 |
| 完全掌控所有组件 | 需要手动管理进程 |
| 适合低配服务器（1 核 2G 即可） | PHP 扩展安装可能遇到编译问题 |
| 性能最好（无容器开销） | 不同服务器环境差异大 |

---

## 方式二：Docker 开发（仅后端容器）

适合本地开发，宿主机已有 MySQL 和 Redis 的场景。

### 前提

- Docker 已安装
- 宿主机已有 MySQL 8.0+ 和 Redis 6.0+

### 1. 生成环境变量

项目有两份 `.env` 模板，互不干扰，详见 [env-files.md](./env-files.md)：

- `deploy/docker/.example.env`：docker compose 插值用（8 字段）
- `backend/.example.env`：ThinkPHP 运行时配置（40+ 字段）

**两种方式任选其一：**

```bash
# 在项目根目录执行
cd /path/to/mall-base
```

**方式 A：零配置（推荐，首次体验）**
跳过本步，直接 `up -d`，ensure-env 会自动生成两份 `.env` + 随机密码 + 默认端口（8080/3306/6379）。

**方式 B：自定义端口 / 密码**
```bash
# 在项目根目录执行
cp deploy/docker/.example.env .env
```
```bash
# 在项目根目录执行：用你惯用的编辑器改 .env 的端口 / 密码 / 数据库名
```
```bash
# 在项目根目录执行
cp backend/.example.env backend/.env
```
```bash
# 在项目根目录执行：编辑 backend/.env，把 DB_PASS / JWT_SECRET 的占位符替换成强随机值
# 生成强随机值：openssl rand -hex 16
```

> 连宿主机的 MySQL/Redis：编辑生成后的 `backend/.env`，把 `DB_HOST` / `REDIS_HOST` 改成 `host.docker.internal`（Docker Desktop）或宿主机 IP。

### 2. 启动后端容器

```bash
docker compose -f docker-compose.dev.yml up -d --no-deps backend
```

> 这里要显式加 `--no-deps`。当前 `backend` 服务在 `docker-compose.dev.yml` 里声明了 `mysql`、`redis`、`install-auto` 等依赖；如果不加这个参数，Compose 会把“全套模式”的依赖服务也一起拉起，就不是“仅后端容器”了。

### 3. 初始化 `vendor`（在容器内执行 Composer）

这里**不需要在宿主机单独安装 Composer**。`mallbase-dev` 镜像在 `deploy/docker/Dockerfile` 里已经内置了 Composer，下面这条命令是直接在容器里执行。

之所以首次启动后还要跑一次 `composer install`，是因为开发模式把：

- `./backend` 挂载到了容器内的 `/app`

开发模式现在默认把 PHP 依赖直接写回宿主机 `backend/vendor`，这样编辑器可以直接跳转 vendor 里的源码；镜像构建阶段装好的依赖不会自动落到宿主机，所以第一次仍然需要手动执行一次：

```bash
docker exec mallbase-dev composer install
```

执行完成后，宿主机可直接看到 `backend/vendor`，容器内 `/app/vendor` 与宿主机目录保持一致。

> 如果你之前用过旧版开发编排，Docker 里可能还残留一个历史 volume `backend_vendor`。它不会再被当前开发模式使用，可以先忽略；想清理时执行 `docker volume rm mall-base_backend_vendor` 即可。

### 4. 访问安装向导

浏览器打开 `http://localhost:8080/install`，按向导完成安装。

> 数据库地址填 `host.docker.internal`（Docker Desktop）或宿主机实际 IP。

### 5. 重启容器

```bash
docker compose -f docker-compose.dev.yml restart backend
```

### 6. 启动前端开发服务器

```bash
cd mall-base/frontend/admin
pnpm install
pnpm run dev:antd
```

前端开发服务器默认运行在 `http://localhost:5666`。

> 方式二的默认推荐流程仍然是**宿主机运行前端 dev server**。这样最直接，也和 `frontend/admin/apps/web-antd/.env.development` 里默认的 `VITE_GLOB_API_URL=http://127.0.0.1:8080/admin/api` 一致。

如果你想把前端 dev server 也放进容器里跑，当前 `docker-compose.dev.yml` **没有内置 `frontend-dev` 服务**，只有一次性 `frontend-build` 打包容器。可以临时起一个 Node 容器：

```bash
docker run --rm -it \
  -p 5666:5666 \
  -v "$PWD/frontend/admin:/app" \
  -v mallbase-pnpm-store:/root/.local/share/pnpm/store \
  -w /app \
  node:20-alpine sh
```
```bash
# 进入容器后执行
corepack enable
corepack prepare pnpm@10.28.2 --activate
pnpm install
pnpm run dev:antd -- --host 0.0.0.0
```

这样浏览器仍然访问 `http://localhost:5666`，但 dev server 实际运行在容器里。

### 修改代码

代码通过 Docker volume 映射（`./backend:/app`），**直接修改宿主机的 `backend/` 目录即可**，容器内实时同步。

开发模式下，前后端依赖目录也都会直接落到宿主机，方便编辑器跳转和本地检索：

- PHP 依赖：`backend/vendor`
- 前端依赖：`frontend/admin/node_modules`
- 应用级依赖：`frontend/admin/apps/web-antd/node_modules`

开启 `APP_DEBUG=true` 后 Swoole 会自动检测文件变化并重载（macOS 需要 `brew install fswatch`）。

### 方式二利弊

| 优点 | 缺点 |
|------|------|
| 只多一个容器，资源占用小 | 需要自行安装 MySQL/Redis |
| 代码实时同步，开发体验好 | MySQL/Redis 版本需要自行管理 |
| 不影响宿主机 PHP 环境 | Docker Desktop 文件映射有性能损耗 |

---

## 方式三：Docker 开发（全套）

一键启动后端 + MySQL + Redis（可选含前端打包），适合快速搭建完整开发环境。

### 前置要求

- Docker Desktop（Mac/Windows）或 Docker Engine + Compose Plugin（Linux）
- 终端里 `docker --version` 与 `docker compose version` 都能正常输出版本号

### 1. 进入项目根目录

```bash
# 所有后续命令默认在项目根目录执行，例如 /Users/you/code/mall-base
cd /path/to/mall-base
```
```bash
# 在项目根目录执行：确认当前位置
pwd
# 应输出 .../mall-base
```

### 2.（可选）自定义端口或密码

要改端口 / 密码 / 数据库名，先复制 Docker 模板到根 `.env`：

```bash
# 在项目根目录执行
cp deploy/docker/.example.env .env
```
```bash
# 在项目根目录执行：用你惯用的编辑器打开 .env 修改端口 / 密码 / 数据库名
# 关键字段：SWOOLE_HTTP_PORT / MYSQL_PORT / REDIS_PORT / DB_NAME / DB_USER / DB_PASS / MYSQL_ROOT_PASSWORD / ADMIN_USER / ADMIN_PASS / INSTALL_DEMO
```

**如果不关心默认值，跳过本步**——`ensure-env` 容器会在第 3 步自动生成根 `.env`，并继续派生 `backend/.env`。

- 根 `.env`：Docker 开发全套模式唯一主配置源
- `backend/.env`：ThinkPHP / Swoole 运行时派生文件，文件头会明确提示“请改根 `.env`，不要手改 backend/.env”

> 两份 `.env` 的职责分工见 [env-files.md](./env-files.md)。

### 3. 启动所有容器（含前端自动打包）

```bash
# 在项目根目录执行
docker compose -f docker-compose.dev.yml --profile build up -d
```

- `--profile build` 会额外启动 `frontend-build` 容器，自动把 Vben 打包好放到 `backend/public/admin/`
- 不加 `--profile build` 则跳过前端打包（适合已经有 admin 产物，或只改后端的场景）

启动序列：`ensure-env`（生成根 `.env` + 派生 `backend/.env`）→ `mysql/redis` healthy → `check-db-auth`（校验现有库账号与根 `.env` 一致）→ `install-auto`（零向导自动装）→ `backend`（Swoole 起来）。`install-auto` 容器执行 `php think install:auto`：

- 从 `backend/.env` 读 DB/Redis 连接（不需要手填 `127.0.0.1` / `mysql:3306`）
- 按 `deploy/install/data/schema/*.sql` 建表
- 创建超管 `admin / admin123`，并把 `password_changed_at` 标记为 `NULL`（首次登录强制改密）
- 同步权限菜单
- 写入 `deploy/install/install.lock` 后退出 0

**预期看到：**

```bash
# 在项目根目录执行
docker ps
# 应该看到 3 个常驻容器：mallbase-dev / mallbase-mysql / mallbase-redis
# mallbase-install-auto / mallbase-ensure-env / mallbase-frontend-build 执行完自动 Exited (0)
```
```bash
# 在项目根目录执行：查看零向导安装日志
docker logs mallbase-install-auto
# 末尾应看到 [install:auto] done
```
```bash
# 在项目根目录执行：frontend-build 打包完会自动退出
docker logs mallbase-frontend-build
# 末尾应看到 [frontend-build] done
# 构建过程中每 15 秒会额外打印一次“进行中，请稍候”，用于提示容器没有卡死
```
```bash
# 在项目根目录执行：确认前端产物落地
ls backend/public/admin/index.html
# 应打印文件路径，不报错
```

### 4. 查看自动生成的关键配置（首次启动）

```bash
# 在项目根目录执行：Docker 主配置
grep -E '^(DB_PASS|MYSQL_ROOT_PASSWORD|ADMIN_USER|ADMIN_PASS|INSTALL_DEMO)=' .env
```
```bash
# 在项目根目录执行：派生后的 TP 运行时配置
grep -E '^(DB_PASS|JWT_SECRET|ADMIN_USER|ADMIN_PASS|INSTALL_DEMO)=' backend/.env
```

> Docker 开发全套模式下，根 `.env` 是唯一主配置源；`backend/.env` 会由 ensure-env 自动派生。`MYSQL_ROOT_PASSWORD` 仅 MySQL 容器首次初始化 / 运维脚本使用，不写进 `backend/.env`。

### 5. 登录后台（零向导）

方式三**不需要浏览器向导**——`install-auto` 容器已在启动序列里完成建库、建表、建超管。

1. 浏览器打开 `http://localhost:8080/admin/`（注意带 `/admin/` 尾斜杠）
2. 输入默认账号登录：
   - 用户名：`admin`
   - 密码：`admin123`
3. 登录成功后会**被强制跳转到"首次登录改密"页**（`/auth/change-password`）
4. 依次填：当前密码 `admin123` → 新密码（≥6 位，不能再是 `admin123`）→ 确认新密码 → 点"修改密码"
5. 改密成功后自动退回登录页，使用**新密码**重新登录即可进入后台首页

之后使用新密码登录不再出现改密页（`password_changed_at` 已记录最新改密时间）。

> **想改默认账号？** 启动前直接在根 `.env` 里加 `ADMIN_USER=xxx` / `ADMIN_PASS=yyy`，`ensure-env` 会自动同步到 `backend/.env`。
> **想自动导入 demo 数据？** 加 `INSTALL_DEMO=1`。
> 详见 [env-files.md](./env-files.md#八零向导环境变量方式三专用)。

> 方式一 / 方式二 / 方式四 不走零向导——它们自备 DB/Redis，连接信息必须用户手填，仍走 `/install` 浏览器向导。

### 6. 改了前端代码想重新打包

```bash
# 在项目根目录执行：单独跑 frontend-build 容器
docker compose -f docker-compose.dev.yml --profile build up frontend-build
```

`frontend-build` 是**一次性打包容器**，作用是把 `@vben/web-antd` 构建成静态文件并同步到 `backend/public/admin/`，它不是常驻的前端开发容器：

- 它没有 `5666:5666` 这样的 dev 端口映射
- 默认命令是 `sh /frontend-build.sh`，执行完会自动退出
- 适合“我要重新出一份静态资源”，不适合“HMR 实时开发”

开发模式下，这个打包容器安装的前端依赖会直接落到宿主机：

- `frontend/admin/node_modules`
- `frontend/admin/apps/web-antd/node_modules`

这样便于编辑器跳转依赖源码；pnpm 的下载缓存仍放在 Docker volume `frontend_pnpm_store` 中。

所以方式三里如果你问“怎么在容器里面跑 `pnpm run dev:antd`”，答案是：**当前 compose 默认没提供这条现成流程**。推荐二选一：

1. 继续按官方默认方式，在宿主机 `frontend/admin` 下执行 `pnpm run dev:antd`
2. 临时起一个 Node 容器来跑 dev server：

```bash
docker run --rm -it \
  -p 5666:5666 \
  -v "$PWD/frontend/admin:/app" \
  -v mallbase-pnpm-store:/root/.local/share/pnpm/store \
  -w /app \
  node:20-alpine sh
```
```bash
# 进入容器后执行
corepack enable
corepack prepare pnpm@10.28.2 --activate
pnpm install
pnpm run dev:antd -- --host 0.0.0.0
```

### 7. 从本地客户端连接容器内的 MySQL / Redis

容器端口已映射到宿主机，可用本地客户端连接：

```bash
# 在任意目录执行
mysql -h 127.0.0.1 -P 3306 -u <DB_USER> -p
# 密码：grep '^DB_PASS=' .env 的值
```

GUI 工具（Navicat / DBeaver / DataGrip）：主机 `127.0.0.1`、端口 `3306`（或根 `.env` 的 `MYSQL_PORT`）、用户名/密码/数据库取自根 `.env`。

```bash
# 在任意目录执行
redis-cli -h 127.0.0.1 -p 6379
# 默认无密码
```

### 常见错误

#### ❌ `install-auto` 容器执行失败

**排查**：

```bash
# 在项目根目录执行：看零向导日志
docker logs mallbase-install-auto
```

常见失败原因：
- `缺少必要 env 变量：DB_HOST, DB_USER, ...` → `ensure-env` 没跑成功，先 `docker logs mallbase-ensure-env` 排查
- `Access denied` / `业务库账号认证失败` → `data/mysql` 是旧数据，而根 `.env` 的 `DB_PASS` 后来改过；可执行显式改密，或走"第 8 步 完全清零重来"
- `step=import_schema` → 建表 SQL 导入失败，检查 `deploy/install/data/schema/` 下的 SQL 是否完整

修复问题后重跑：

```bash
# 在项目根目录执行：重跑 install-auto
docker compose -f docker-compose.dev.yml rm -f install-auto
docker compose -f docker-compose.dev.yml up -d install-auto
```

#### ❌ 打开 `/admin/` 不跳改密页，也不报错

**原因**：`deploy/install/install.lock` 已存在但数据库里 `mb_admin.password_changed_at` 不为 NULL（可能是旧版本升级上来的）。

**解决**：

```bash
# 在项目根目录执行：把超管标记为"从未改密"
docker exec -it mallbase-mysql sh -c 'mysql -u root -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" -e "UPDATE mb_admin SET password_changed_at=NULL WHERE id=1"'
```

再次登录即会跳到改密页。

#### ❌ `Connection refused` 连不上 MySQL

**原因**：DB Host 填了 `127.0.0.1`。在 backend 容器里 `127.0.0.1` 指容器自己，不是 MySQL 容器。

**解决**：改成 `mysql`（compose 文件里的服务名）。

#### ❌ `Access denied for user` 数据库连接被拒

**原因**：当前根 `.env` 的 `DB_PASS` 已经变了，但 `data/mysql` 里现有业务账号的真实密码没跟着变。

**解决**：

```bash
# 在项目根目录执行：先确认根 .env 中的目标新密码
grep '^DB_PASS=' .env
```

如果要**保留现有数据**，执行显式轮换：

```bash
# 在项目根目录执行：把 MySQL 中的业务账号密码同步为根 .env 的 DB_PASS
docker compose -f docker-compose.dev.yml --profile tools up rotate-db-password
```

如果**不要现有数据**，再按“第 8 步 完全清零重来”推倒重装。

#### ❌ 访问 `/install` 页面 500 或白屏

**原因**：已经安装过，`deploy/install/install.lock` 存在，但浏览器缓存了旧版本页面。

**解决**：新版本页面会自动显示"系统已安装"卡片 + "进入后台管理"按钮。若仍 500，强制刷新（Cmd+Shift+R / Ctrl+F5）清浏览器缓存。

#### ❌ 登录后台后菜单是空的

**原因**：权限数据未同步（安装时会自动同步，但可能失败）。

**解决**：

```bash
# 在项目根目录执行
docker exec -it mallbase-dev php think sync:permissions
```

#### ❌ 访问 `/admin` 404 或白屏

**原因**：前端产物没有落到 `backend/public/admin/`。

**解决**：

```bash
# 在项目根目录执行：单跑 frontend-build
docker compose -f docker-compose.dev.yml --profile build up frontend-build
```
```bash
# 在项目根目录执行：确认产物
ls backend/public/admin/index.html
```

### 8. 完全清零重来

如果状态混乱想回到全新环境：

```bash
# 在项目根目录执行：推荐，一键清理 Docker 开发全套模式产生的本地状态
sh deploy/docker/cleanup-dev.sh
```
```bash
# 在项目根目录执行：如果连 mysql / redis / node / alpine 这些基础镜像也想一起删
# ⚠️ 可能影响本机其他项目，下次需要重新拉取
sh deploy/docker/cleanup-dev.sh --all-images
```

上面脚本会自动清理：
- compose 容器 / 网络 / 卷
- 本项目镜像 `mallbase-backend:dev`
- `data/`
- 根 `.env`
- `backend/.env`
- `backend/vendor`
- `deploy/install/install.lock`
- `backend/public/admin`
- `frontend/admin/node_modules`
- `frontend/admin/apps/web-antd/node_modules`
- `frontend/admin/apps/web-antd/dist`

如果你想手动分步执行，也可以继续用下面这些命令：

```bash
# 在项目根目录执行：停容器并清卷（⚠️ 会清空所有数据库数据）
docker compose -f docker-compose.dev.yml down -v
```
```bash
# 在项目根目录执行：删掉已退出的 init 容器（ensure-env / install-auto / frontend-build）
docker compose -f docker-compose.dev.yml rm -f ensure-env install-auto frontend-build
```
```bash
# 在项目根目录执行：清理本地数据 + 配置 + 前端产物
rm -rf data/
rm -f deploy/install/install.lock
rm -f backend/.env
rm -f .env
rm -rf backend/vendor
rm -rf backend/public/admin
rm -rf frontend/admin/node_modules
rm -rf frontend/admin/apps/web-antd/node_modules
rm -rf frontend/admin/apps/web-antd/dist
```
```bash
# 在项目根目录执行：从模板重来，零向导会自动跑一遍
docker compose -f docker-compose.dev.yml --profile build up -d
```

### 修改代码

代码通过 volume 映射（`./backend:/app`），**直接修改宿主机的 `backend/` 目录即可**，容器内实时同步。`APP_DEBUG=true` 下 Swoole 会监听文件变化并自动 reload（macOS 需要 `brew install fswatch`）。

### 方式三利弊

| 优点 | 缺点 |
|------|------|
| 一键启动完整环境 | 3 个容器 + 首次前端打包，资源占用较高 |
| 零配置，开箱即用 | 2 核 4G 以下服务器可能吃力 |
| 数据持久化到宿主机 `data/` | Docker Desktop 文件映射有性能损耗 |
| 本地客户端可直接连接 MySQL/Redis | |

---

## 方式四：Docker 生产

单后端容器 + 宿主机 Nginx，适合生产部署。

### 架构

```
用户浏览器
    ↓
宿主机 Nginx (:443)
    ├── /admin/*        → 直接返回前端静态文件
    ├── /admin/api/*    → proxy_pass → Swoole 容器 (:8080)
    ├── /install/*      → proxy_pass → Swoole 容器 (:8080)
    ├── /client/*       → proxy_pass → Swoole 容器 (:8080)
    └── /               → 301 → /admin/
```

### 1. 生成环境变量

生产单容器模式 `docker-compose.yml` 只用一份 `backend/.env`（构建镜像时 `COPY` 进镜像），**不依赖根目录 `.env`**。

```bash
# 在项目根目录执行
cp backend/.example.env backend/.env
```
```bash
# 在 backend 目录执行：生成强随机值
cd backend
openssl rand -hex 16
# 用于 DB_PASS
```
```bash
# 在 backend 目录执行
openssl rand -hex 32
# 用于 JWT_SECRET
```

用编辑器打开 `backend/.env`，重点调整以下字段：

```ini
# 调试（生产必须 false）
APP_DEBUG=false

# 数据库（连宿主机 MySQL：host.docker.internal 或宿主机 IP）
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=mallbase
DB_USER=mallbase
DB_PASS=<openssl rand -hex 16 的输出>

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# JWT
JWT_SECRET=<openssl rand -hex 32 的输出>

# CORS（填写你的前端域名）
CORS_ALLOWED_ORIGINS=https://mall.example.com
```

> **⚠️ 安全红线**：`backend/.example.env` 里的 `DB_PASS` / `JWT_SECRET` 占位符为 `please-change-or-leave-for-random`，生产环境必须手动替换成强随机值，**否则等于把密码写在仓库里**。

### 2. 先构建前端静态资源

前端要在**本地开发机**或 **CI** 上构建，推荐放在 `docker compose up -d --build` 之前完成：

```bash
cd mall-base/frontend/admin
pnpm install
pnpm run build --filter=@vben/web-antd
```

> 构建前确认 `.env.production`：`VITE_BASE=/admin/`、`VITE_GLOB_API_URL=/admin/api`。
> 部署后如需修改 API 地址，可直接编辑 `/var/www/mallbase/admin/_app.config.js`，无需重新构建。

这是因为生产模式的后端镜像只会复制 `backend/` 目录，`docker-compose.yml` 也只挂载了 `runtime` 和 `public/uploads` 两个 volume，并**不会把 `frontend/admin` 源码映射进容器**。所以前端构建不能指望在 `docker compose up -d --build` 之后临时去容器里执行，它本来就不在这个生产容器里。

### 3. 部署前端文件到服务器

```bash
# 将构建产物上传到服务器
scp -r frontend/admin/apps/web-antd/dist/* user@server:/var/www/mallbase/admin/
```

如果你本地已经把后台静态资源整理到了 `backend/public/admin`，也可以直接使用项目自带脚本打包并上传：

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

脚本说明见 [upload-public-admin.md](./upload-public-admin.md)。

### 4. 构建并启动后端容器

```bash
docker compose up -d --build
```

如果这次只改了后端代码或 `backend/.env`，而前端代码没有变化，可以直接执行这一步，不需要重复构建前端。

### 5. 配置宿主机 Nginx

```bash
# 复制配置文件到服务器
sudo cp deploy/nginx/mallbase.conf /etc/nginx/sites-available/mallbase.conf
sudo ln -s /etc/nginx/sites-available/mallbase.conf /etc/nginx/sites-enabled/

# 修改 server_name、SSL 证书路径、前端文件路径
sudo vim /etc/nginx/sites-available/mallbase.conf

# 验证并重载
sudo nginx -t
sudo systemctl reload nginx
```

反向代理路径拆分和常见坑位，详见 [nginx-reverse-proxy.md](./nginx-reverse-proxy.md)。

### 6. 访问安装向导

浏览器打开 `https://mall.example.com/install`，按向导完成安装。

### 7. 重启容器

```bash
docker compose restart
```

### 修改代码

生产环境代码打包在镜像中，修改代码需要重新构建镜像：

```bash
# 拉取最新代码
git pull

# 重新构建并启动
docker compose up -d --build
```

如果只是修改 `.env` 配置，直接进容器编辑：

```bash
docker exec -it mallbase sh
vi .env
exit
docker compose restart
```

### 方式四利弊

| 优点 | 缺点 |
|------|------|
| 镜像不可变，部署一致 | 改代码需重新 build |
| 版本可回滚（保留旧镜像） | 需要额外配置宿主机 Nginx |
| 资源占用低（仅 1 个容器） | 前端构建需要在别处完成 |
| SSL / 负载均衡由宿主机 Nginx 处理 | |

---

## 安装后

### 访问后台

安装并重启后，访问 `/admin` 进入后台管理系统，使用安装时创建的管理员账号登录。

### 导入地区数据

```bash
# 手动安装
cd mall-base/backend
php think region:import

# Docker
docker exec mallbase php think region:import
# 或开发环境
docker exec mallbase-dev php think region:import
```

### 修改配置

安装向导生成的 `backend/.env` 文件包含所有运行配置。修改后需要重启：

| 部署方式 | 重启命令 |
|---------|---------|
| 手动安装 | `lsof -ti :8080 \| xargs kill && php think swoole` |
| Docker 生产 | `docker compose restart` |
| Docker 开发 | `docker compose -f docker-compose.dev.yml restart` |

### 常用配置项

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| `APP_DEBUG` | 调试模式（**生产必须关闭**） | `false` |
| `JWT_SECRET` | JWT 签名密钥（安装时自动生成） | 随机值 |
| `JWT_EXPIRE` | 访问令牌有效期（秒） | `7200` |
| `JWT_REFRESH_EXPIRE` | 刷新令牌有效期（秒） | `2592000` |
| `CORS_ALLOWED_ORIGINS` | 允许跨域的前端地址 | 安装时配置 |
| `SWOOLE_WORKER_NUM` | Worker 进程数（`0` = CPU 核数） | `0` |

### 升级已装环境（password_changed_at 列）

若项目是在 `password_changed_at` 特性引入**之前**就已安装的老环境，
需要跑一次幂等升级命令给 `mb_admin` 表补齐该列：

```bash
# 手动安装
cd mall-base/backend
php think upgrade:admin-schema

# Docker 开发（全套）
docker compose -f docker-compose.dev.yml exec -T backend php think upgrade:admin-schema

# Docker 生产
docker compose exec -T backend php think upgrade:admin-schema
```

命令行为（幂等 + 重试安全 + 基于密码哈希判定）：

- **缺列** → `ALTER TABLE` 补上
- **有列** → 跳过 DDL
- **回填阶段**：扫描所有 `password_changed_at IS NULL` 的行，对每行用 `password_verify()` 逐一对照**公开默认密码清单**（当前为 `admin123` + `password`）
  - **任一命中** → 保留 NULL，下次登录强制改密（哪怕这账号天天登录也不放过）
  - **全部不中** → 回填为 `NOW()`，视为已改密

> ⚠️ 为什么清单里有两个：老版 `deploy/install/data/schema/01_mb_auth.sql` 的种子哈希对应明文是 `password`；
> 新版 `createSuperAdmin` 用 `admin123` 并走 `ON DUPLICATE KEY UPDATE` 覆写。
> 两种 legacy 残留都得拦住，只查 `admin123` 会让仍在用公开 `password` 的老环境静默通过。

> ⚠️ 之前用"登录过"这种代理指标判定的版本是错的——长期用默认密码登录的账号会被误认为"已改密"。
> 现在改为直接比对密码哈希，任何已知公开默认都永远不会静默通过安全门。

因为回填是按行 `WHERE id=?` 写入，已回填的行下次不会再被 `SELECT`，
`ALTER` 成功但回填中断的半升级状态，直接再跑一次即可补齐。

新装环境理论上不需要运行（`install:auto` 走的 schema 已包含此列）；
即便误触发，由于新装超管密码仍命中公开默认清单，哈希检查会保留 NULL，首次改密安全门不会被绕过。

如果升级后想**有选择地**强制某个账号改密，按"常见错误 → 打开 /admin/ 不跳改密页"
章节里的 `UPDATE mb_admin SET password_changed_at=NULL WHERE id=?` 手工置 NULL 即可。

---

## 常见问题

### 安装完成后页面报错 / 接口返回旧数据

Swoole 是常驻内存服务，`.env` 变更后**必须重启**才能加载。

### Docker 容器内连接宿主机 MySQL/Redis

| 平台 | 地址 |
|------|------|
| Docker Desktop (Mac/Win) | `host.docker.internal` |
| Linux | `172.17.0.1` 或宿主机实际 IP |

`docker-compose.yml` 已配置 `extra_hosts`，Linux 下也支持 `host.docker.internal`。

### 重新安装

删除锁文件后重启服务：

```bash
# 手动安装：在项目根目录执行
rm deploy/install/install.lock
```
```bash
# Docker 生产：在项目根目录执行
docker exec mallbase rm install/install.lock
docker compose restart
```
```bash
# Docker 开发：在项目根目录执行
rm -f deploy/install/install.lock
docker compose -f docker-compose.dev.yml restart backend
```

> ⚠️ 若 MySQL 数据库已有业务数据但想完全重装，参考方式三的"完全清零重来"序列（会清空所有数据库数据）。

### Swoole 进程杀不掉

```bash
# 按端口批量杀
lsof -ti :8080 | xargs kill -9
```

### 前端构建 out of memory

```bash
export NODE_OPTIONS=--max-old-space-size=4096
pnpm run build --filter=@vben/web-antd
```

### 修改前端 API 地址（无需重新构建）

构建产物中的 `_app.config.js` 包含运行时配置。部署后如需修改 API 地址，直接编辑：

```bash
vim /var/www/mallbase/admin/_app.config.js
# 修改 VITE_GLOB_API_URL 的值
```

修改后刷新浏览器即可生效。

### 验证 CORS 配置

```bash
# 白名单 Origin（应返回 204 + Access-Control-Allow-Origin）
curl -i -X OPTIONS 'http://127.0.0.1:8080/' \
  -H 'Origin: https://mall.example.com' \
  -H 'Access-Control-Request-Method: GET'
```

---

## 目录结构

```
mall-base/
├── backend/                   # 后端（PHP/ThinkPHP/Swoole）
│   ├── app/                   # 应用代码
│   ├── config/                # 配置文件
│   ├── install/               # 安装数据
│   │   └── data/
│   │       ├── schema/        # 建表 SQL
│   │       ├── demo/          # 演示数据
│   │       └── region/        # 地区数据
│   ├── public/                # 静态资源（含 admin 前端产物）
│   │   └── admin/             # Vben Admin 打包产物（gitignored）
│   └── .example.env           # ThinkPHP 运行时模板（40+ 字段）
├── frontend/admin/            # 后台前端（Vben Admin，pnpm + turbo monorepo）
├── deploy/
│   ├── docker/
│   │   ├── Dockerfile         # 后端容器构建
│   │   ├── ensure-env.sh      # 开发环境 init 容器：补齐 / 派生两份 .env
│   │   ├── .example.env       # docker compose 变量模板（8 字段）
│   │   └── mysql/init.sh      # MySQL 初始化脚本
│   └── nginx/
│       └── mallbase.conf      # Nginx 配置示例
├── docker-compose.yml         # 生产（单容器，需自备 MySQL/Redis + Nginx）
└── docker-compose.dev.yml     # 开发（后端 + MySQL + Redis + 可选 frontend-build）
```

## 交流与反馈

- QQ 群：958717939
