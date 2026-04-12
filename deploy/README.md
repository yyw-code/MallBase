# MallBase Iteration-3 配置基线（最小部署模板）

本模板只覆盖本次迭代涉及的配置：缓存、Swoole、CORS、日志轮转。

## 1. 环境变量基线

建议基于 `backend/.example.env` 生成 `backend/.env`，并至少确认以下变量：

```dotenv
# Cache / Redis
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_CACHE_DB=0

# Swoole 安全默认
SWOOLE_MAX_REQUEST=2000
SWOOLE_RELOAD_ASYNC=true
SWOOLE_MAX_WAIT_TIME=60

# CORS 白名单（逗号分隔）
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000

# Log 轮转保留
LOG_SINGLE=false
LOG_MAX_FILES=30
```

本地开发无 Redis 时，可临时设置 `CACHE_DRIVER=file`。

## 2. 启动前检查

```bash
cd backend
php -v
php think | head
```

## 3. 运行与验证（最小）

```bash
# 启动（按项目现有方式）
cd backend
php think swoole
```

```bash
# 预检请求：白名单 Origin（应返回 204 且含 Access-Control-Allow-Origin）
curl -i -X OPTIONS 'http://127.0.0.1:8080/' \
  -H 'Origin: http://localhost:3000' \
  -H 'Access-Control-Request-Method: GET'

# 预检请求：非白名单 Origin（应返回 403，且含 Vary: Origin）
curl -i -X OPTIONS 'http://127.0.0.1:8080/' \
  -H 'Origin: http://evil.example.com' \
  -H 'Access-Control-Request-Method: GET'
```
