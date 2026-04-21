# 方式一：手动安装（无 Docker）

适合低配服务器、需要完全控制 PHP / MySQL / Redis / Nginx 的场景。

## 前提

- 服务器可直接安装 PHP 8.2、MySQL 8.0、Redis 6、Nginx
- 你希望自行管理进程与系统服务
- 已准备好项目代码目录，例如 `mall-base/`

## 完整步骤

### 1. 安装 PHP 8.2 与扩展

#### Ubuntu / Debian

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php
sudo apt update

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

pecl install swoole
echo "extension=swoole.so" | sudo tee /etc/php/8.2/cli/conf.d/20-swoole.ini
```

#### CentOS / RHEL / AlmaLinux

```bash
sudo dnf install -y epel-release
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %rhel).rpm
sudo dnf module reset php
sudo dnf module enable php:remi-8.2

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

pecl install swoole
echo "extension=swoole.so" | sudo tee /etc/php.d/20-swoole.ini
```

#### macOS

```bash
brew install php@8.2
pecl install swoole
pecl install redis
```

验证：

```bash
php -v
php -m | grep -E "swoole|redis|pdo_mysql|gd|mbstring|zip|intl|bcmath|opcache"
```

### 2. 安装 MySQL 8.0

#### Ubuntu / Debian

```bash
sudo apt install -y mysql-server
sudo systemctl start mysql
sudo systemctl enable mysql

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
sudo apt install -y redis-server
sudo systemctl start redis
sudo systemctl enable redis
```

验证：

```bash
redis-cli ping
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
composer install --no-dev --optimize-autoloader
```

### 6. 构建前端

```bash
nvm install 22
nvm use 22
npm i -g pnpm

cd mall-base/frontend/admin
pnpm install
pnpm run build --filter=@vben/web-antd
```

构建前确认 `frontend/admin/apps/web-antd/.env.production` 与 Nginx 路径一致：

- `VITE_BASE=/admin/`
- `VITE_GLOB_API_URL=/admin/api`

### 7. 部署前端文件

```bash
sudo mkdir -p /var/www/mallbase/admin
sudo cp -r frontend/admin/apps/web-antd/dist/* /var/www/mallbase/admin/
```

如果你更想走打包后的上传脚本，参考 [upload-public-admin.md](./upload-public-admin.md)。

### 8. 配置 Nginx

复制并调整项目自带配置：

```bash
sudo cp deploy/nginx/mallbase.conf /etc/nginx/sites-available/mallbase.conf
sudo ln -s /etc/nginx/sites-available/mallbase.conf /etc/nginx/sites-enabled/
sudo vim /etc/nginx/sites-available/mallbase.conf
sudo nginx -t
sudo systemctl reload nginx
```

完整代理规则见 [nginx-reverse-proxy.md](./nginx-reverse-proxy.md)。

### 9. 启动 Swoole

```bash
cd mall-base/backend
php think swoole
```

默认监听 `0.0.0.0:8080`。

### 10. 访问安装向导

浏览器打开 `http://your-domain/install`，按向导完成：

1. 环境检测
2. 填写 MySQL 连接信息
3. 填写 Redis 连接信息
4. 创建管理员账号并配置 CORS
5. 可选导入演示数据

### 11. 重启 Swoole

安装向导会生成 `backend/.env`，必须重启 Swoole 才能加载新配置：

```bash
lsof -ti :8080 | xargs kill
cd mall-base/backend
php think swoole
```

## 完成后验证

```bash
curl -I http://127.0.0.1:8080/
ls /var/www/mallbase/admin/index.html
```

浏览器访问：

- `/install`：首次安装
- `/admin/`：安装完成后进入后台

## 常见下一步

- 查看常用命令集合：[commands.md](./commands.md)
- 查看常见错误：[troubleshooting.md](./troubleshooting.md)
- 调整反向代理：[nginx-reverse-proxy.md](./nginx-reverse-proxy.md)
