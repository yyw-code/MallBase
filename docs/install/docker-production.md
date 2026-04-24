# 方式四：Docker 生产

适合单后端容器 + 宿主机 Nginx 的生产部署方式。

## 前提

- 服务器已安装 Docker 与 Docker Compose
- 生产环境准备了外部 MySQL 8.0+ 与 Redis 6.0+
- 宿主机可配置 Nginx，并能托管 `/admin/` 静态目录
- 后台前端静态资源将在本地开发机或 CI 上提前构建

## 完整步骤

### 1. 准备后端环境变量

生产单容器模式只使用 `backend/.env`：

```bash
cp backend/.example.env backend/.env
```

生成强随机值：

```bash
cd backend
openssl rand -hex 16
openssl rand -hex 32
```

重点修改：

- `APP_DEBUG=false`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `REDIS_HOST`
- `REDIS_PORT`
- `JWT_SECRET`
- `SITE_URL`

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

### 3. 部署前端文件到服务器

方式 A：直接上传 `dist/`

```bash
scp -r frontend/admin/apps/web-antd/dist/* user@server:/var/www/mallbase/admin/
```

方式 B：如果你本地已经准备好了 `backend/public/admin`，直接用项目脚本上传：

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

完整脚本说明见 [upload-public-admin.md](./upload-public-admin.md)。

### 4. 构建并启动后端容器

```bash
docker compose up -d --build
```

如果这次只有后端代码或 `backend/.env` 变化，不需要重复构建前端。

### 5. 配置宿主机 Nginx

```bash
sudo cp deploy/nginx/mallbase.conf /etc/nginx/sites-available/mallbase.conf
sudo ln -s /etc/nginx/sites-available/mallbase.conf /etc/nginx/sites-enabled/
sudo vim /etc/nginx/sites-available/mallbase.conf
sudo nginx -t
sudo systemctl reload nginx
```

路径规则与示例配置见 [nginx-reverse-proxy.md](./nginx-reverse-proxy.md)。

### 6. 访问安装向导

浏览器打开：

```bash
https://mall.example.com/install
```

按向导填写数据库、Redis 和管理员账号。

安装流程完成后会自动执行：

- 路由权限同步
- 设置菜单权限同步
- 地区数据导入

### 7. 重启容器

安装向导写入配置后，重启后端容器：

```bash
docker compose restart
```

## 完成后验证

```bash
docker ps
curl -I http://127.0.0.1:8080/
ls /var/www/mallbase/admin/index.html
```

浏览器访问：

- `/install`
- `/admin/`

## 常见下一步

- 上传静态资源脚本：[upload-public-admin.md](./upload-public-admin.md)
- Nginx 代理与静态目录：[nginx-reverse-proxy.md](./nginx-reverse-proxy.md)
- 常用运维命令：[commands.md](./commands.md)
- 安装与部署排障：[troubleshooting.md](./troubleshooting.md)
