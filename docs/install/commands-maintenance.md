# 验证与维护命令

本页收录运行状态检查、安装后补同步和测试准备命令。

## 检查后台前端产物是否存在

适用：方式三、方式四。

```bash
ls backend/public/admin/index.html
```

## 检查后端 HTTP 是否可访问

适用：方式一、方式二、方式三、方式四。

```bash
curl -I http://127.0.0.1:8080/
```

## 导入地区数据

适用：方式一、方式二、方式三、方式四。

说明：统一安装流程已经默认导入地区数据。以下命令主要用于补同步或手工修复。

本地执行：

```bash
(cd backend && php think region:import)
```

Docker 执行：

```bash
docker compose -f docker-compose.dev.yml exec -T backend php think region:import
docker compose exec -T backend php think region:import
```

## 初始化 Playwright 浏览器

适用：前端 E2E 测试。

```bash
pnpm --dir frontend/admin run test:e2e:install
```

## 重启服务以加载新的 `.env`

本地 Swoole：

```bash
lsof -ti :8080 | xargs kill
(cd backend && php think swoole)
```

Docker 开发全套：

```bash
docker compose -f docker-compose.dev.yml restart backend
```

Docker 生产：

```bash
docker compose restart
```
