# 常用命令速查

本页只放日常最常用的命令。更完整的命令按场景拆在：

- [commands-local.md](./commands-local.md)
- [commands-docker.md](./commands-docker.md)
- [commands-frontend.md](./commands-frontend.md)
- [commands-cleanup.md](./commands-cleanup.md)
- [commands-maintenance.md](./commands-maintenance.md)

## 本地命令行安装

适用：方式一、本地 PHP 直跑，或本地安装页面提交失败后的补救。

```bash
(cd backend && php think install:auto)
```

完整流程见 [cli-install.md](./cli-install.md)。

## 查看安装锁

```bash
test -f backend/runtime/install/install.lock && cat backend/runtime/install/install.lock
```

如果输出 `install.lock 已存在，跳过`，说明当前环境已经安装过。重新测试首装前请先看 [commands-cleanup.md](./commands-cleanup.md#重新测试首装前的清理边界)。

## 重启本地 Swoole

适用：方式一、本地 PHP 直跑。

```bash
lsof -ti :8080 | xargs kill
(cd backend && php think swoole)
```

## 启动 Docker 开发全套

适用：方式三。

```bash
docker compose -f docker-compose.dev.yml up -d
```

## 查看后端日志

适用：方式二、方式三、方式四。

```bash
docker compose -f docker-compose.dev.yml logs -f backend
docker compose logs -f backend queue cron
```

## 后台前端 Admin 构建

本地构建：

```bash
cd frontend/admin
pnpm install
pnpm run build --filter=@vben/web-antd
```

Docker 全套模式单独构建：

```bash
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

完整说明见 [admin-build.md](./admin-build.md)。

## 上传前端静态资源

上传到服务器项目目录下的 `backend/public/admin` 和 `backend/public/client`：

```bash
sh deploy/upload-frontend.sh \
  --host root@server \
  --remote-root /www/wwwroot/example.com/mall-base
```

完整说明见 [upload-frontend.md](./upload-frontend.md)。

## 基础清理

适用：方式一、方式二、方式三、本地构建后的方式四。

```bash
sh deploy/docker/cleanup-dev.sh
```

如需继续清理前端文件、Docker 开发状态或镜像，见 [cleanup-dev.md](./cleanup-dev.md)。

## 检查后端 HTTP

适用：方式一、方式二、方式三、方式四。

```bash
curl -I http://127.0.0.1:8080/
```
