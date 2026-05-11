# 前端静态资源上传脚本

## 适用场景

- 本地已经生成 `backend/public/admin`
- 如需发布 H5，本地已经生成 `backend/public/client`
- 需要把后台和 H5 静态资源上传到服务器
- 希望避免手工 `scp` 多个文件或遗漏旧文件

## 脚本位置

```bash
deploy/upload-frontend.sh
```

脚本会：

1. 打包本地 `backend/public/admin`
2. 如果 `backend/public/client/index.html` 存在，则一并打包 H5
3. 上传到服务器临时目录
4. 解压到服务器目标目录
5. 默认清空目标目录旧文件，避免静态资源残留

## 使用前准备

### 1. 本地已经有构建产物

确认本地目录存在：

```bash
ls backend/public/admin/index.html
```

如果不存在，先生成后台前端资源，例如：

```bash
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

如果需要同时发布 H5，确认本地 H5 产物存在：

```bash
ls backend/public/client/index.html
```

如果不存在，先生成 UniApp H5：

```bash
docker compose -f docker-compose.uniapp-build.yml up uniapp-build
```

### 2. 本机可通过 SSH 登录服务器

脚本依赖以下命令：

- `tar`
- `scp`
- `ssh`

推荐用 SSH 密钥登录（不需要知道服务器密码）。如果你已经能通过密钥连上服务器（比如云厂商创建实例时已注入公钥），跳过这一节，直接用 `--identity` 指定私钥路径即可。否则按下面四步配置一次：

**① 在本机生成密钥对**（已有 `~/.ssh/id_ed25519` 可跳过）：

```bash
ssh-keygen -t ed25519 -C "mallbase-deploy"
```

一路回车即可（passphrase 可留空）。生成两个文件：

- `~/.ssh/id_ed25519`：私钥，留在本机，**不要外传**
- `~/.ssh/id_ed25519.pub`：公钥，需要放到服务器上

**② 把公钥装到服务器**（这一步需要你当前还能登录服务器，比如有初始密码、或云控制台的 VNC/Web 终端）：

```bash
ssh-copy-id -i ~/.ssh/id_ed25519.pub root@服务器IP
```

如果没有 `ssh-copy-id`，手动追加也行：

```bash
cat ~/.ssh/id_ed25519.pub | ssh root@服务器IP "mkdir -p ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys"
```

**③ 验证免密登录**：

```bash
ssh -i ~/.ssh/id_ed25519 root@服务器IP
```

能直接进、不再要密码，就配好了。

**④ 跑上传脚本时带上私钥**：

```bash
sh deploy/upload-frontend.sh \
  --host root@服务器IP \
  --identity ~/.ssh/id_ed25519 \
  --remote-root /www/wwwroot/example.com/mall-base
```

> 注意：如果服务器禁止 root 直接 SSH，把上面的 `root` 换成你能登录的用户（比如 `ubuntu`），并确认该用户对目标静态目录有写权限（必要时先 `sudo chown -R 用户:用户 目标目录`）。

### 3. 可选：用本地配置文件免去重复输入参数

为了不在仓库里写真实的服务器地址、私钥路径和远端目录，可以复制一份本地配置：

```bash
cp deploy/upload-frontend.local.sh.example deploy/upload-frontend.local.sh
```

然后在 `deploy/upload-frontend.local.sh` 里填真实值（`SSH_HOST`、`SSH_PORT`、`SSH_IDENTITY`、`SSH_PASSWORD`、`REMOTE_ROOT` 或 `REMOTE_ADMIN_DIR` / `REMOTE_CLIENT_DIR`、`KEEP_EXTRA`）。该文件已在 `.gitignore` 中忽略，不会进入版本库。

如果服务器只能用密码登录，把密码写到 `SSH_PASSWORD`（需要本机安装 `sshpass`：macOS `brew install hudochenkov/sshpass/sshpass`，Debian/Ubuntu `apt-get install sshpass`）。密码建议只写在本地配置文件里，不要用命令行 `--password`，否则会出现在进程列表。

之后直接运行脚本即可，无需再带一长串参数：

```bash
sh deploy/upload-frontend.sh
```

命令行参数仍会覆盖本地配置中的同名值，临时换目标时依然可以用 `--host` 等参数临时指定。也可以用环境变量 `MALLBASE_UPLOAD_CONFIG` 指定其它配置文件路径。

## 用法

### 方式一：直接指定服务器目标目录

适合服务器用 Nginx 直接托管静态文件，例如：

- 后台：`/var/www/mallbase/admin`
- H5：`/var/www/mallbase/client`

```bash
sh deploy/upload-frontend.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

使用 `--remote-dir` 时，如果本地存在 `backend/public/client/index.html`，脚本默认会把 H5 上传到后台目录的同级 `client` 目录，即 `/var/www/mallbase/client`。

### 方式二：指定服务器项目根目录

适合服务器上也有 MallBase 项目目录，希望自动上传到对应的 `backend/public/admin` 和 `backend/public/client`。

```bash
sh deploy/upload-frontend.sh \
  --host root@server \
  --remote-root /www/wwwroot/example.com/mall-base
```

上面这条命令最终会上传到：

```bash
/www/wwwroot/example.com/mall-base/backend/public/admin
/www/wwwroot/example.com/mall-base/backend/public/client
```

### 可选参数

```bash
--port 22
```

自定义 SSH 端口，例如：

```bash
sh deploy/upload-frontend.sh \
  --host user@server \
  --port 2222 \
  --remote-dir /var/www/mallbase/admin
```

```bash
--identity ~/.ssh/your_key
```

如果服务器只允许私钥登录，可以显式指定 SSH 私钥文件：

```bash
sh deploy/upload-frontend.sh \
  --host root@your-server \
  --identity ~/.ssh/id_ed25519 \
  --remote-root /www/wwwroot/mallbase.gosowong.cn/mall-base
```

```bash
--password '你的密码'
```

服务器只能用密码登录时使用（需要本机安装 `sshpass`）。注意密码会出现在进程列表里，更推荐把密码写到 `deploy/upload-frontend.local.sh` 的 `SSH_PASSWORD`：

```bash
sh deploy/upload-frontend.sh \
  --host root@your-server \
  --password 'your-password' \
  --remote-root /www/wwwroot/example.com/mall-base
```

```bash
--keep-extra
```

默认脚本会先清空服务器目标目录，再解压新文件。如果你想保留服务器目录里额外文件，可以加这个参数：

```bash
sh deploy/upload-frontend.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin \
  --keep-extra
```

```bash
--client-dir /var/www/mallbase/client
```

如果 H5 目录不是后台目录的同级 `client`，可以显式指定：

```bash
sh deploy/upload-frontend.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin \
  --client-dir /data/www/mallbase-h5
```

## 推荐用法

### Nginx 直接托管静态文件

```bash
sh deploy/upload-frontend.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

对应现有部署文档中的 Nginx 配置，后台静态目录通常是 `/var/www/mallbase/admin`，H5 静态目录通常是 `/var/www/mallbase/client`。

### Docker 生产环境，代码目录里托管后台静态文件

```bash
sh deploy/upload-frontend.sh \
  --host root@server \
  --remote-root /www/wwwroot/mallbase.gosowong.cn/mall-base
```

如果这台服务器只接受密钥登录，补上 `--identity`：

```bash
sh deploy/upload-frontend.sh \
  --host root@your-server \
  --identity ~/.ssh/id_ed25519 \
  --remote-root /www/wwwroot/mallbase.gosowong.cn/mall-base
```

## 注意事项

- 本地源目录固定是 `backend/public/admin`
- 如果 `backend/public/client/index.html` 存在，脚本会同时上传 H5
- `--remote-dir` 和 `--remote-root` 必须二选一
- `--client-dir` 可覆盖 H5 的远端目录
- 默认会清空服务器目标目录内容，避免旧版本资源残留
- 如果你确认服务器目录里还有其他需要保留的文件，再使用 `--keep-extra`
- 如果你没有 root 密码、只持有私钥，请使用 `--identity`

## 常见问题

### 服务器目录上传后还是旧页面

先确认脚本上传到了你实际在用的目录，再检查：

- Nginx 静态目录是否就是这个路径
- 浏览器是否缓存了旧资源
- `_app.config.js` 是否还是旧配置

### 域名打开不是 H5 页面

按当前 Nginx 示例，域名根路径应该直接进入 H5，`/admin` 才进入后台。请检查：

- H5 目录是否存在 `index.html`
- Nginx 的 `root` 是否指向 H5 目录
- `/client/api/` 是否仍然代理到后端，而不是被当成静态文件

### 为什么脚本默认先清空目标目录

前端静态资源通常带 hash 文件名。如果只做追加上传，旧版本文件可能残留，出现资源引用混用或页面异常。默认清空再解压更稳妥。
