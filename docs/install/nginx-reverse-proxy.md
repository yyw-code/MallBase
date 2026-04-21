# MallBase 反向代理配置说明

## 适用范围

- 方式一：手动安装（无 Docker）
- 方式四：Docker 生产
- 其他“前端静态文件 + Swoole API”分离部署场景

## 设计目标

MallBase 的前后端路径拆分规则如下：

| 路径 | 处理方式 | 说明 |
|------|----------|------|
| `/admin/` | Nginx 直接返回静态文件 | 后台前端入口与静态资源 |
| `/admin/api/` | 反向代理到 Swoole | 后台 API |
| `/install` | 反向代理到 Swoole | 安装向导 |
| `/client/` | 反向代理到 Swoole | 客户端 API（预留） |
| `/uploads/` | 反向代理到 Swoole | 上传文件访问 |
| `/` | 301 跳转到 `/admin/` | 默认进入后台 |

## 使用前确认

### 1. 后端已能直接访问

先确认 Swoole 已经启动，并且宿主机能直接访问：

```bash
curl -I http://127.0.0.1:8080/
```

预期至少能看到 `HTTP/1.1 200 OK`、`302` 或其他有效 HTTP 响应，而不是连接被拒绝。

### 2. 前端静态文件已经存在

```bash
ls /var/www/mallbase/admin/index.html
```

如果文件不存在，先按 [index.md](./index.md) 选择对应安装方式，完成前端构建和上传。

### 3. 前端构建参数正确

`frontend/admin/apps/web-antd/.env.production` 应保持：

```ini
VITE_BASE=/admin/
VITE_GLOB_API_URL=/admin/api
```

这样前端路由和接口地址才能与下面的 Nginx 路径规则对齐。

## Nginx 配置示例

项目已提供示例文件：`deploy/nginx/mallbase.conf`。下面给出一个可直接理解的精简版。

### HTTP 版本

```nginx
server {
    listen 80;
    server_name mall.example.com;

    gzip on;
    gzip_min_length 1k;
    gzip_comp_level 6;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml image/svg+xml;
    gzip_vary on;

    location /admin/ {
        alias /var/www/mallbase/admin/;
        try_files $uri $uri/ /admin/index.html;
    }

    location /admin/api/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_connect_timeout 60s;
        proxy_read_timeout 120s;
        proxy_send_timeout 60s;
    }

    location /install {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /client/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /uploads/ {
        proxy_pass http://127.0.0.1:8080;
    }

    location = / {
        return 301 /admin/;
    }
}
```

### HTTPS 版本

如果线上启用 HTTPS，直接使用仓库里的 `deploy/nginx/mallbase.conf` 更合适；它已经包含：

- 80 → 443 自动跳转
- `ssl_certificate` / `ssl_certificate_key`
- 与 HTTP 版一致的路径拆分规则

## 推荐部署步骤

### 方式一：手动安装

1. 后端直接运行在宿主机，确认 `php think swoole` 已监听 `127.0.0.1:8080` 或 `0.0.0.0:8080`
2. 将前端构建产物放到 `/var/www/mallbase/admin/`
3. 把 `deploy/nginx/mallbase.conf` 复制到 Nginx 站点目录
4. 按实际域名、证书路径、静态目录修改配置
5. 执行 `nginx -t && systemctl reload nginx`

### 方式四：Docker 生产

1. 先构建前端并上传到 `/var/www/mallbase/admin/`
2. 再执行 `docker compose up -d --build` 启动后端容器
3. Nginx 的 `/admin/api/`、`/install`、`/client/`、`/uploads/` 指向宿主机暴露的 `127.0.0.1:8080`
4. 执行 `nginx -t && systemctl reload nginx`

## 自检命令

### 1. 检查静态首页

```bash
curl -I http://mall.example.com/admin/
```

预期返回 `200 OK`。

### 2. 检查后台 API 已经过代理

```bash
curl -i http://mall.example.com/admin/api/auth/admin/info
```

未登录时通常会返回业务错误或未授权响应，但关键是它应该是 **MallBase 后端返回的 JSON**，而不是 Nginx 的 404 HTML。

### 3. 检查安装向导

```bash
curl -I http://mall.example.com/install
```

首次安装时应能打开安装向导；已安装环境可能会跳到“系统已安装”的提示。

## 常见问题

### `/admin/` 404 或白屏

优先检查两点：

- `/var/www/mallbase/admin/index.html` 是否存在
- `location /admin/` 里是否使用了 `alias /var/www/mallbase/admin/;`

如果把 `alias` 误写成 `root`，或者目录少了尾部 `/`，很容易出现静态资源路径错位。

### `/admin/api/` 返回 Nginx 404

通常是下面几类问题：

- 没有配置 `location /admin/api/`
- `proxy_pass` 指到了错误端口
- Swoole 后端没有成功启动

先在宿主机执行：

```bash
curl -I http://127.0.0.1:8080/
```

确认后端本身可访问，再排查 Nginx。

### 前端能打开，但接口走成了错误地址

检查前端生产构建参数：

```ini
VITE_BASE=/admin/
VITE_GLOB_API_URL=/admin/api
```

如果这里写成了 `/api` 或完整域名，而 Nginx 实际路径不是这样，登录、菜单和数据请求都会异常。

### 修改配置后没有生效

每次修改站点配置后都要执行：

```bash
sudo nginx -t
sudo systemctl reload nginx
```

只改文件不 reload，线上流量仍会走旧配置。
