# Docker 命令

本页收录 Docker 启停、日志、容器内依赖和连接服务命令。完整安装流程请看对应方式文档：

- [docker-backend-only.md](./docker-backend-only.md)
- [docker-fullstack.md](./docker-fullstack.md)
- [docker-production.md](./docker-production.md)

## 只启动后端容器

适用：方式二。

```bash
docker compose -f docker-compose.dev.yml up -d --no-deps backend
```

## 启动 Docker 开发全套

适用：方式三。

```bash
docker compose -f docker-compose.dev.yml up -d
```

## 启动 Docker 生产

适用：方式四。

```bash
docker compose up -d --build
```

## 启动 Docker 开发全套并单独执行前端打包

适用：方式三。

```bash
docker compose -f docker-compose.dev.yml up -d
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

## 查看后端日志

适用：方式二、方式三、方式四。

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker logs ${PREFIX}-dev
docker logs ${PREFIX}
```

## 查看 `frontend-build` 日志

适用：方式三。

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker logs ${PREFIX}-frontend-build
```

## 启动前初始化后端 `vendor`

适用：方式二、方式三。

这是 Docker 开发模式命令，使用普通 `composer install`，保留测试和调试依赖。

```bash
docker compose -f docker-compose.dev.yml run --rm --no-deps backend composer install
```

## 已启动容器里重新安装后端依赖

适用：方式二、方式三。

同样保持普通安装；生产 Docker 镜像不需要在这里手动加 `--no-dev --optimize-autoloader`，镜像构建阶段已经处理。

```bash
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker exec ${PREFIX}-dev composer install
```

## 连接 Docker 全套模式的 MySQL / Redis

适用：方式三。

```bash
mysql -h 127.0.0.1 -P 3306 -u <DB_USER> -p
redis-cli -h 127.0.0.1 -p 6379
```

## 重启服务以加载新的 `.env`

开发全套：

```bash
docker compose -f docker-compose.dev.yml restart backend
```

生产：

```bash
docker compose restart
```
