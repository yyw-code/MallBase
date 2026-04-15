# Vben 规则：后端驱动路由

## 适用范围

`frontend/admin/apps/web-antd/src/router` 及页面路由接入场景。

## 机制说明

前端路由**不需要**在 `frontend/admin/apps/web-antd/src/router/routes/modules/` 下手动创建文件。
路由配置由后端 API 动态返回，前端根据后端返回的菜单/权限数据自动注册路由。

## 强制规则

1. 业务菜单路由由后端接口动态下发。
2. 前端避免为业务模块手写静态路由文件。
3. 后端路由 `_component` 必须与 `views` 文件路径一致。

## 禁止

- ❌ 在 `router/routes/modules/` 下为每个功能模块创建独立路由文件

## 正确做法

- ✅ 后端路由文件 `route/admin/xxx.php` 中通过 `_path`、`_component`、`_parent` 等配置项定义前端路由信息
- ✅ 前端通过 API 获取菜单数据后动态注册路由

## 自检清单

- [ ] 页面可由后端菜单正确访问。
- [ ] 未在 `router/routes/modules` 添加冗余业务路由。
