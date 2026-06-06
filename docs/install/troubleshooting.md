# 安装与部署故障排查

本页收录安装、部署、前端静态资源与 Docker 运行时的常见问题。  
如果是方式三首次启动时序与密码错位问题，优先再看专题文档 [docker-fullstack-first-run.md](./issues/docker-fullstack-first-run.md)。

## 安装向导与 `install.lock`

### `/install` 页面报 500 或白屏

原因：

- 已经安装过，`backend/runtime/install/install.lock` 已存在
- 浏览器缓存了旧页面

处理：

```bash
rm -f backend/runtime/install/install.lock
docker compose -f docker-compose.dev.yml restart backend
docker compose restart
```

如果只是页面缓存问题，先强制刷新浏览器。

### 安装完成后页面仍像没生效

原因：

- Swoole 是常驻进程，`.env` 更新后没有重启

处理：

```bash
docker compose -f docker-compose.dev.yml restart backend
docker compose restart
```

### 同步系统设置菜单时报 Redis read error

错误示例：

```text
系统设置菜单同步失败：read error on connection to redis:6379
```

原因：

- 安装流程已经按表单校验过 Redis，但当前 Swoole 进程内的缓存连接可能仍持有旧连接。
- 该错误通常发生在重建设置菜单权限后的缓存清理阶段；权限数据以数据库写入为准，缓存清理失败不应阻断首装。

处理：

- 更新到包含该修复的版本后重新构建并启动后端容器。
- 如果本次首装数据可以丢弃，按清理文档清空本次数据库、Redis DB 和安装锁后重新安装，这是最稳妥的处理方式。
- 如果必须保留已经导入的数据，不要直接重复点击安装；此时数据库已不再是空库，需要按实际完成步骤补执行后续安装收尾。

## Docker 启动与依赖服务

### 本地手动执行 `install:auto` 失败

先看日志：

```bash
(cd backend && php think install:auto)
```

常见原因：

- 项目根 `.env` 缺少关键变量
- MySQL 账号密码不匹配
- 目标数据库不是空库
- Redis DB 不是空 DB
- `SITE_URL` 未配置
- SQL 导入失败

修复后在项目根目录重跑：

```bash
(cd backend && php think install:auto)
```

完整步骤见 [cli-install.md](./cli-install.md)。

### `frontend-build` 看起来像卡住

原因：

- 首次 `pnpm install` 和打包本身耗时较长

处理：

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker logs ${PREFIX}-frontend-build
```

脚本每 15 秒会输出一次“进行中，请稍候”，有日志就说明没卡死。

### `backend` 日志报 `Failed opening required '/app/vendor/autoload.php'`

原因：

- 宿主机挂载了 `./backend:/app`
- 这会覆盖镜像层里原本已经安装好的 `vendor`
- 首次启动时宿主机 `backend/vendor` 还不存在

处理：

```bash
docker compose -f docker-compose.dev.yml run --rm --no-deps backend composer install
docker compose -f docker-compose.dev.yml up -d --no-deps backend
```

这是开发 Compose 场景，使用普通 `composer install` 即可；不要在这里加 `--no-dev`，否则容器内会缺少本地测试和调试依赖。生产 Docker 镜像构建时已经自动安装生产依赖并优化自动加载。

如果你直接执行 `up -d --no-deps backend`，当前开发镜像入口脚本也会自动补一次 `composer install`。首次启动时间较长属于正常现象。

### `data/mysql` 或 `data/redis` 权限异常

适用场景：

- Docker 启动 MySQL / Redis 时提示宿主机挂载目录不可写
- `prepare-data-dirs` 日志提示 `data/mysql` 或 `data/redis` 不可读写
- 手动复制、迁移或恢复过 `data/` 目录，导致目录属主不是容器内运行用户

处理：

```bash
# 在项目根目录执行
mkdir -p data/mysql data/redis
sudo chown -R 999:999 data/mysql data/redis
sudo chmod -R u+rwX,g+rwX data/mysql data/redis
```

说明：

- `999:999` 是 MySQL / Redis 容器常见的运行用户 UID/GID，本项目的 `prepare-data-dirs` 也按这个用户修复目录权限。
- 这条命令只修复目录权限，不会解决“`.env` 密码和旧 `data/mysql` 真实密码不一致”的问题；那种情况请看下方 `Access denied for user`。
- 如果不需要保留现有数据，应走全量清理流程，而不是只修权限。

## MySQL / Redis 连接

### `Connection refused` 连不上 MySQL

原因：

- 在容器里把 `DB_HOST` 写成了 `127.0.0.1`
- `127.0.0.1` 在容器里只代表容器自身

处理：

- 方式三：改成 `mysql`
- 方式二：改成 `host.docker.internal` 或宿主机实际 IP

### `Access denied for user`

原因：

- 根 `.env` 的 `DB_PASS` 改了
- 但旧 `data/mysql` 里的真实业务账号密码没变

处理：

```bash
grep '^DB_PASS=' .env
docker compose -f docker-compose.dev.yml --profile tools up rotate-db-password
```

如果不需要保留旧数据，可按全量清理流程重来。

### Docker 容器里如何连接宿主机 MySQL / Redis

| 平台 | 地址 |
|------|------|
| Docker Desktop (Mac/Win) | `host.docker.internal` |
| Linux | `172.17.0.1` 或宿主机实际 IP |

## 前端静态资源与 `/admin`

### `/admin/` 404 或白屏

原因：

- `backend/public/admin` 或 Nginx 静态目录没有正确产物

处理：

```bash
docker compose -f docker-compose.frontend-build.yml up frontend-build
ls backend/public/admin/index.html
```

如果是生产环境，还要确认服务器静态目录和 Nginx 配置一致，详见 [nginx-reverse-proxy.md](./nginx-reverse-proxy.md)。

### 上传后还是旧页面

原因：

- 上传到了错误目录
- 浏览器缓存了旧资源
- `_app.config.js` 仍是旧配置

处理：

- 核对服务器静态目录
- 清缓存后重试
- 检查 `_app.config.js`

如果你使用上传脚本，参见 [upload-frontend.md](./upload-frontend.md)。

### 前端构建内存不足

处理：

```bash
export NODE_OPTIONS=--max-old-space-size=4096
pnpm run build --filter=@vben/web-antd
```

## 权限菜单与首次改密

### 打开 `/admin/` 不跳改密页

原因：

- 旧环境数据库中 `mb_admin.password_changed_at` 已有值

处理：

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker exec -it ${PREFIX}-mysql sh -c 'mysql -u root -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" -e "UPDATE mb_admin SET password_changed_at=NULL WHERE id=1"'
```

### 登录后菜单是空的

原因：

- 权限数据未同步

处理：

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker exec -it ${PREFIX}-dev php think sync:permissions
```

## 端口、CORS、缓存与重启

### 改了端口后不生效

原因：

- 只改了一份 `.env`
- 没有重建容器

处理：

```bash
grep '^SWOOLE_HTTP_PORT=' .env backend/.env
docker compose -f docker-compose.dev.yml down
docker compose -f docker-compose.dev.yml up -d
```

### 修改前端 API 地址后页面请求还是旧值

生产构建场景可以直接编辑：

```bash
vim /var/www/mallbase/admin/_app.config.js
```

修改后刷新浏览器即可。

### 验证 CORS 是否正确

```bash
curl -i -X OPTIONS 'http://127.0.0.1:8080/' \
  -H 'Origin: https://mall.example.com' \
  -H 'Access-Control-Request-Method: GET'
```

预期返回 `204` 并带有 `Access-Control-Allow-Origin`、`Access-Control-Allow-Credentials`、`Access-Control-Allow-Methods` / `Headers` 等头。CORS 不通过环境变量配置；如需收紧策略，请直接修改 `backend/app/middleware/CorsMiddleware.php`。

### Swoole 进程杀不掉

```bash
lsof -ti :8080 | xargs kill -9
```
