---
name: backend-driven-routing
description: MallBase Vben Admin 后端驱动路由与 API 契约规则；新增或调整后台菜单、权限路由、页面路由及前端接口定义时使用。
---

# Vben 规则：后端驱动路由与 API 契约

## 适用范围

`backend/route/api/admin`、`frontend/admin/apps/web-antd/src/router` 和 `frontend/admin/apps/web-antd/src/api`。

## 机制说明

后台业务菜单默认由后端权限路由元数据动态下发，前端根据菜单/权限数据注册页面。核心路由、认证页、维护页及经过明确设计的本地功能可以保留静态路由；静态路由是受控例外，不得与后端动态菜单重复注册。

## 强制规则

1. 后台业务菜单优先在 `backend/route/api/admin/*.php` 中通过 `_path`、`_component`、`_parent`、`_icon` 等元数据定义。
2. `_component` 必须与 `frontend/admin/apps/web-antd/src/views` 下的页面路径一致。
3. 核心、认证、维护等不依赖权限菜单的页面可定义静态路由；新增其他静态路由前必须确认不能由后端菜单表达，并避免路径或名称冲突。
4. 前端 API 方法必须逐项匹配真实后端的 HTTP 方法、完整路径和参数位置。
5. 后端声明 `/:id` 时前端拼接路径参数；后端使用 query 或 body 时按其真实契约传递，禁止把所有接口一律改成 `/:id`。
6. 修改接口前先检查对应 `backend/route/api/admin/*.php`，必要时继续核对 Controller/Service 的参数读取与验证。

## 禁止

- ❌ 为每个业务菜单机械创建静态路由文件
- ❌ 同一路径同时存在静态路由和后端动态菜单
- ❌ 仅凭 CRUD 命名猜测 ID 应放在 path、query 或 body

## 正确做法

- 后端路由：`backend/route/api/admin/goods.php`
- 前端核心静态路由：`frontend/admin/apps/web-antd/src/router/routes/core.ts`
- 前端按后端 `info/:id` 定义：``requestClient.get(`/module/info/${id}`)``
- 若后端真实定义为 `info` 并从 query 读取：`requestClient.get('/module/info', { params: { id } })`

## 自检清单

- [ ] 页面可由后端菜单正确访问。
- [ ] `_component` 与实际 `views` 页面匹配。
- [ ] 静态路由属于明确例外，且未与动态菜单重复。
- [ ] API 的 method、path、参数位置与后端路由和参数读取完全一致。
