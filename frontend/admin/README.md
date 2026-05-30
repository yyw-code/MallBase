# MallBase 后台管理前端（@vben/web-antd）

本目录是 MallBase 项目的后台管理前端，基于 [Vben Admin](https://github.com/vbenjs/vue-vben-admin)（Vue 3 + Vite + TypeScript + Ant Design Vue）构建。

> 整个目录是一个 **pnpm + turbo 的 monorepo**，当前 MallBase 只使用其中的 `apps/web-antd` 应用。其他 `apps/web-ele`、`apps/web-naive`、`apps/web-antd-ele` 保留上游模板结构，**本项目不使用，也不会部署**。

## 目录结构

```
frontend/admin/                  ← 你现在的位置
├── apps/
│   ├── web-antd/                ← ✅ 本项目实际使用的应用
│   │   ├── src/                 ← 页面 / 布局 / store / API
│   │   ├── .env.development     ← 开发环境变量
│   │   ├── .env.production      ← 生产环境变量（VITE_BASE=/admin/）
│   │   └── vite.config.mts
│   ├── web-ele/                 ← ⚠️ 上游模板，MallBase 未使用
│   ├── web-naive/               ← ⚠️ 上游模板，MallBase 未使用
│   └── ...
├── packages/                    ← @vben 组件库、工具、hooks
├── internal/                    ← 内部构建工具
├── package.json                 ← workspace root，脚本入口
└── pnpm-workspace.yaml
```

## 前置要求

- Node.js ≥ 20
- pnpm（由 corepack 自动接管，无需手工安装）
- Chrome / Edge（开发推荐）

**验证：**
```bash
# 在任意目录执行，确认 Node 版本
node -v
# 应输出 v20.x 或更高
```

## 一、开发模式

### 第 1 步：进入目录、启用 pnpm

```bash
# 在项目根目录执行（如 /Users/you/code/mall-base）
cd frontend/admin
```
```bash
# 在 frontend/admin 目录执行：启用 corepack 接管 pnpm
corepack enable
```

### 第 2 步：安装依赖

```bash
# 在 frontend/admin 目录执行
pnpm install
```
首次安装大约 3～5 分钟。

### 第 3 步：启动 @vben/web-antd 开发服务器

```bash
# 在 frontend/admin 目录执行
pnpm dev:antd
```

**预期看到：**
- 终端打印 `VITE ready in xxx ms` 及访问地址 `http://localhost:5173`
- 浏览器访问 `http://localhost:5173` 可见登录页
- API 会请求 `http://127.0.0.1:8080/admin/api`（由 `.env.development` 配置）

### 第 4 步：确认后端正在运行

前端开发模式需要后端 Swoole 服务可访问。如果后端在 Docker：
```bash
# 在项目根目录执行
docker ps
# 应看到 ${MALLBASE_CONTAINER_PREFIX:-mallbase}-dev 容器在运行，8080 端口已暴露
```

如果后端是原生运行：
```bash
# 在 backend 目录执行
php think swoole
```

## 二、生产构建

### 第 1 步：构建 @vben/web-antd

```bash
# 在 frontend/admin 目录执行
pnpm build:antd
```

**预期看到：**
- 终端末尾输出 `DONE Build complete...`
- 产物生成在 `frontend/admin/apps/web-antd/dist/`
- `dist/` 下包含 `index.html`、`assets/`、`static/` 等文件

**验证：**
```bash
# 在 frontend/admin 目录执行
ls apps/web-antd/dist/index.html
# 应打印文件路径，不报错
```

### 第 2 步：部署到后端 `public/admin`

MallBase 的 Swoole 服务直接托管 `backend/public/admin` 静态文件。前端产物需要复制到该目录。

#### 方式 A：Docker 一键打包（推荐）

```bash
# 在项目根目录执行
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

**预期看到：**
```bash
# 在项目根目录执行
PREFIX=${MALLBASE_CONTAINER_PREFIX:-mallbase}
docker logs ${PREFIX}-frontend-build
# 末尾应看到 [frontend-build] done
```
```bash
# 在项目根目录执行
ls backend/public/admin/index.html
# 应打印文件路径，不报错
```

#### 方式 B：手动打包与复制

```bash
# 在 frontend/admin 目录执行
pnpm build:antd
```
```bash
# 在 frontend/admin 目录执行
mkdir -p ../../backend/public/admin
```
```bash
# 在 frontend/admin 目录执行：清空旧产物
rm -rf ../../backend/public/admin/*
```
```bash
# 在 frontend/admin 目录执行：复制新产物
cp -r apps/web-antd/dist/. ../../backend/public/admin/
```

**验证：**
```bash
# 在项目根目录执行
ls backend/public/admin/index.html
# 应打印文件路径，不报错
```

## 三、环境变量

### 开发环境：`apps/web-antd/.env.development`

```env
VITE_PORT=5173
VITE_BASE=/
VITE_GLOB_API_URL=http://127.0.0.1:8080/admin/api
```
- `VITE_GLOB_API_URL`：后端 admin 接口前缀，默认指向本地 Swoole 8080
- 如果后端部署在其他地址，改这里即可（比如 `http://192.168.1.10:8080/admin/api`）

### 生产环境：`apps/web-antd/.env.production`

```env
VITE_BASE=/admin/
VITE_GLOB_API_URL=/admin/api
```
- `VITE_BASE=/admin/`：告诉 Vite 产物资源都放在 `/admin/` 路径下（与部署位置一致）
- `VITE_GLOB_API_URL=/admin/api`：运行时走**同源**请求，无需跨域配置

## 四、常见错误

### ❌ `pnpm: command not found`
**原因**：没启用 corepack，或 Node < 16.9。
**解决**：
```bash
# 在任意目录执行
corepack enable
```
```bash
# 验证
pnpm -v
# 应打印版本号
```

### ❌ `pnpm install` 中途报 `ERR_PNPM_PEER_DEP_ISSUES`
**原因**：工作区依赖冲突，多由 Node 版本过低导致。
**解决**：升级 Node 到 20+，或使用 nvm 切换：
```bash
# 在任意目录执行
nvm install 20
```
```bash
# 在任意目录执行
nvm use 20
```

### ❌ 开发模式下登录请求 404 或 CORS 被拦
**原因**：后端未启动，或 `.env.development` 的 `VITE_GLOB_API_URL` 指向错误。
**解决步骤**：
1. 确认后端 8080 可访问：
   ```bash
   # 在任意目录执行
   curl -I http://127.0.0.1:8080/admin/api
   # 应返回 HTTP 响应（404 也可以，说明服务活着）
   ```
2. 如果后端不在 127.0.0.1，改 `apps/web-antd/.env.development` 的 `VITE_GLOB_API_URL`。

### ❌ 构建后访问 `/admin/` 白屏
**原因**：资源路径不匹配，通常是 `VITE_BASE` 配置与实际部署位置不一致。
**解决**：
- 确认产物拷贝到了 `backend/public/admin/`（不是 `backend/public/` 根下）
- 确认 `.env.production` 里 `VITE_BASE=/admin/`（前后都要有斜杠）
- 强制刷新浏览器（Cmd+Shift+R / Ctrl+F5）清缓存

### ❌ 重新打包后浏览器仍加载旧版本
**原因**：浏览器缓存了 index.html。
**解决**：强制刷新（Cmd+Shift+R / Ctrl+F5）；产物文件本身带 hash，无需担心 JS/CSS 缓存。

## 五、从头重新打包

如果遇到产物状态混乱，用以下序列清理重来：

```bash
# 在 frontend/admin 目录执行：清除构建产物
rm -rf apps/web-antd/dist
```
```bash
# 在 frontend/admin 目录执行：重新打包
pnpm build:antd
```
```bash
# 在项目根目录执行：清空后端 admin 目录
rm -rf backend/public/admin/*
```
```bash
# 在 frontend/admin 目录执行：复制新产物
cp -r apps/web-antd/dist/. ../../backend/public/admin/
```

## 六、未来扩展

MallBase 后续会新增以下前端端，同样约定产物落到 `backend/public/` 下对应子目录：

| 前端端 | 产物位置 | 访问路径 |
|---|---|---|
| 后台管理（当前） | `backend/public/admin/` | `/admin/` |
| H5 商城（预留） | `backend/public/h5/` | `/h5/` |
| UniApp 多端（预留） | `backend/public/uniapp/` | `/uniapp/` |

所有前端端共用一个 Swoole 服务伺服，无需额外 Nginx。

## 相关文档

- 项目总览：`../../README.md`
- 安装与部署导航：`../../docs/install/index.md`
- 后台前端打包：`../../docs/install/admin-build.md`
- `.env` 文件职责：`../../docs/install/env-files.md`
- 后端驱动路由规范：`../../.codex/skills/vbenAdmin/backend-driven-routing/SKILL.md`

## 上游参考

本项目基于 Vben Admin 5.x，感谢上游作者的开源贡献：
- 官方仓库：https://github.com/vbenjs/vue-vben-admin
- 官方文档：https://doc.vben.pro/
- 官方演示：https://vben.pro/

## 许可证

MIT License，详见 [LICENSE](./LICENSE)。
