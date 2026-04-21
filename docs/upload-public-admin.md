# 后台静态资源上传脚本

## 适用场景

- 本地已经生成 `backend/public/admin`
- 需要把这份后台静态资源上传到服务器
- 希望避免手工 `scp` 多个文件或遗漏旧文件

## 脚本位置

```bash
deploy/upload-public-admin.sh
```

脚本会：

1. 打包本地 `backend/public/admin`
2. 上传到服务器临时目录
3. 解压到服务器目标目录
4. 默认清空目标目录旧文件，避免静态资源残留

## 使用前准备

### 1. 本地已经有构建产物

确认本地目录存在：

```bash
ls backend/public/admin/index.html
```

如果不存在，先生成后台前端资源，例如：

```bash
docker compose -f docker-compose.dev.yml --profile build up frontend-build
```

### 2. 本机可通过 SSH 登录服务器

脚本依赖以下命令：

- `tar`
- `scp`
- `ssh`

## 用法

### 方式一：直接指定服务器目标目录

适合服务器用 Nginx 直接托管后台静态文件，例如 `/var/www/mallbase/admin`。

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

### 方式二：指定服务器项目根目录

适合服务器上也有 MallBase 项目目录，希望自动上传到对应的 `backend/public/admin`。

```bash
sh deploy/upload-public-admin.sh \
  --host root@server \
  --remote-root /www/wwwroot/example.com/mall-base
```

上面这条命令最终会上传到：

```bash
/www/wwwroot/example.com/mall-base/backend/public/admin
```

### 可选参数

```bash
--port 22
```

自定义 SSH 端口，例如：

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --port 2222 \
  --remote-dir /var/www/mallbase/admin
```

```bash
--identity ~/.ssh/your_key
```

如果服务器只允许私钥登录，可以显式指定 SSH 私钥文件：

```bash
sh deploy/upload-public-admin.sh \
  --host root@165.154.60.251 \
  --identity ~/.ssh/id_ed25519 \
  --remote-root /www/wwwroot/mallbase.gosowong.cn/mall-base
```

```bash
--keep-extra
```

默认脚本会先清空服务器目标目录，再解压新文件。如果你想保留服务器目录里额外文件，可以加这个参数：

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin \
  --keep-extra
```

## 推荐用法

### Nginx 直接托管后台静态文件

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

对应现有部署文档中的 Nginx 配置，静态目录通常就是 `/var/www/mallbase/admin`。

### Docker 生产环境，代码目录里托管后台静态文件

```bash
sh deploy/upload-public-admin.sh \
  --host root@server \
  --remote-root /www/wwwroot/mallbase.gosowong.cn/mall-base
```

如果这台服务器只接受密钥登录，补上 `--identity`：

```bash
sh deploy/upload-public-admin.sh \
  --host root@165.154.60.251 \
  --identity ~/.ssh/id_ed25519 \
  --remote-root /www/wwwroot/mallbase.gosowong.cn/mall-base
```

## 注意事项

- 本地源目录固定是 `backend/public/admin`
- `--remote-dir` 和 `--remote-root` 必须二选一
- 默认会清空服务器目标目录内容，避免旧版本资源残留
- 如果你确认服务器目录里还有其他需要保留的文件，再使用 `--keep-extra`
- 如果你没有 root 密码、只持有私钥，请使用 `--identity`

## 常见问题

### 服务器目录上传后还是旧页面

先确认脚本上传到了你实际在用的目录，再检查：

- Nginx 静态目录是否就是这个路径
- 浏览器是否缓存了旧资源
- `_app.config.js` 是否还是旧配置

### 为什么脚本默认先清空目标目录

前端静态资源通常带 hash 文件名。如果只做追加上传，旧版本文件可能残留，出现资源引用混用或页面异常。默认清空再解压更稳妥。
