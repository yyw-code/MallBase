# 客户端装修开发文档

本文面向二次开发和维护人员，说明客户端装修模块的表、服务、接口、前端入口、运行时调用链、扩展点和测试入口。

## 数据表

| 表 | 用途 | 来源 |
|----|------|------|
| `mb_client_page` | 客户端页面库 | `backend/install/data/schema/13_mb_client_diy.sql` |
| `mb_client_decoration_scheme` | 首页、个人中心、底部导航、悬浮按钮方案 | `backend/install/data/schema/13_mb_client_diy.sql` |
| `mb_client_decoration_snapshot` | 装修方案启用快照 | `backend/install/data/schema/13_mb_client_diy.sql` |
| `mb_client_theme` | 客户端主题方案 | `backend/install/data/schema/13_mb_client_diy.sql` |
| `mb_client_theme_policy` | 主题策略历史表 | `backend/install/data/schema/13_mb_client_diy.sql` |

主题策略当前会优先读写 `mb_setting` 中的 `client_theme_*` 配置项，`mb_client_theme_policy` 主要作为历史兼容来源。

相关升级 SQL：

- `backend/install/data/upgrade/2026_06_03_client_diy.sql`
- `backend/install/data/upgrade/2026_06_26_client_config_settings.sql`
- `backend/install/data/upgrade/2026_06_26_client_theme_setting.sql`
- `backend/install/data/upgrade/2026_07_05_client_decoration_system_assets.sql`

## 后端入口

| 类型 | 文件 |
|------|------|
| 页面库服务 | `backend/app/service/admin/client/ClientPageService.php` |
| 装修方案后台服务 | `backend/app/service/admin/client/ClientDecorationSchemeService.php` |
| 主题后台服务 | `backend/app/service/admin/client/ClientThemeService.php` |
| 装修运行时服务 | `backend/app/service/client/DecorationService.php` |
| 页面库控制器 | `backend/app/controller/admin/client/PageController.php` |
| 装修方案控制器 | `backend/app/controller/admin/client/DecorationSchemeController.php` |
| 主题控制器 | `backend/app/controller/admin/client/ThemeController.php` |
| 前台装修控制器 | `backend/app/controller/client/DecorationController.php` |
| 页面库模型 | `backend/app/model/client/ClientPage.php` |
| 装修方案模型 | `backend/app/model/client/ClientDecorationScheme.php` |
| 装修快照模型 | `backend/app/model/client/ClientDecorationSnapshot.php` |
| 主题模型 | `backend/app/model/client/ClientTheme.php` |

## 后台 API

### 页面库

| 功能 | 方法和路径 |
|------|------------|
| 页面列表 | `GET /admin/api/client/page/list` |
| 页面详情 | `GET /admin/api/client/page/info/:id` |
| 页面选择器 | `GET /admin/api/client/page/picker` |
| 创建页面 | `POST /admin/api/client/page/create` |
| 更新页面 | `PUT /admin/api/client/page/update/:id` |
| 删除页面 | `DELETE /admin/api/client/page/delete/:id` |
| 导入页面 | `POST /admin/api/client/page/import` |

### 装修方案

| 功能 | 方法和路径 |
|------|------------|
| 方案列表 | `GET /admin/api/client/decorate/scheme/list` |
| 方案详情 | `GET /admin/api/client/decorate/scheme/info/:id` |
| 商品来源选择器 | `GET /admin/api/client/decorate/scheme/product-sources` |
| 跳转目标选择器 | `GET /admin/api/client/decorate/scheme/target-picker` |
| 创建方案 | `POST /admin/api/client/decorate/scheme/create` |
| 更新方案 | `PUT /admin/api/client/decorate/scheme/update/:id` |
| 复制方案 | `POST /admin/api/client/decorate/scheme/copy/:id` |
| 启用方案 | `PUT /admin/api/client/decorate/scheme/activate/:id` |
| 删除方案 | `DELETE /admin/api/client/decorate/scheme/delete/:id` |

### 主题

| 功能 | 方法和路径 |
|------|------------|
| 主题列表 | `GET /admin/api/client/theme/list` |
| 主题详情 | `GET /admin/api/client/theme/info/:id` |
| 创建主题 | `POST /admin/api/client/theme/create` |
| 更新主题 | `PUT /admin/api/client/theme/update/:id` |
| 复制主题 | `POST /admin/api/client/theme/copy/:id` |
| 发布主题 | `PUT /admin/api/client/theme/publish/:id` |
| 删除主题 | `DELETE /admin/api/client/theme/delete/:id` |
| 主题设置 | `GET /admin/api/client/theme/setting` |
| 保存主题设置 | `PUT /admin/api/client/theme/setting` |
| 主题策略 | `GET /admin/api/client/theme/policy` |
| 保存主题策略 | `PUT /admin/api/client/theme/policy` |

后台前端 API：

- `frontend/admin/apps/web-antd/src/api/client/decorate.ts`
- `frontend/admin/apps/web-antd/src/api/client/theme.ts`

后台页面：

- `frontend/admin/apps/web-antd/src/views/client/decorate/`
- `frontend/admin/apps/web-antd/src/views/client/theme/index.vue`
- 页面库相关页面和组件。

## 前台 API

| 功能 | 方法和路径 | 控制器 |
|------|------------|--------|
| 客户端装修总配置 | `GET /client/api/decorate/config` | `DecorationController::config()` |
| 客户端主题配置 | `GET /client/api/decorate/themes` | `DecorationController::themes()` |
| 我的主题偏好 | `GET /client/api/user/my/theme` | `UserController::getMyTheme()` |
| 保存主题偏好 | `PUT /client/api/user/my/theme` | `UserController::saveMyTheme()` |

前台文件：

- `frontend/uniapp/api/decorate/decorate.js`
- `frontend/uniapp/config/decorate.js`
- `frontend/uniapp/config/theme.js`
- `frontend/uniapp/store/decorate.js`
- `frontend/uniapp/utils/decorate.js`
- `frontend/uniapp/components/mb-decorate-renderer/`
- `frontend/uniapp/components/mb-custom-tabbar/`
- `frontend/uniapp/pages/profile/index.vue`

## 核心调用链

### 客户端读取装修配置

```text
DecorationController::config()
-> DecorationService::config()
-> getActiveOrSystemScheme(home/profile/tabbar/floating)
-> normalizeClientSchema()
-> filterProfilePointsSchema()
-> themes()
-> 返回 home/profile/tabbar/floating/theme
```

### 启用装修方案

```text
DecorationSchemeController::activate()
-> ClientDecorationSchemeService::activate()
-> normalizeSchemaByType()
-> validateSchemaByType()
-> 同类型方案 is_active 全部置 0
-> 当前方案 is_active 置 1
-> 写 mb_client_decoration_snapshot
```

### 页面库导入

```text
PageController::import()
-> ClientPageService::importFromUniappPages()
-> 解析 UniApp pages.json
-> 识别主包、分包、tab 页面
-> 创建或更新 system 来源页面
```

### 主题发布和读取

```text
ThemeController::publish()
-> ClientThemeService::publish()
-> validateTokens()
-> status = published

DecorationService::themes()
-> 读取主题设置 client_theme_*
-> 读取已发布主题
-> 无主题时使用 fallbackThemes()
```

## Schema 约定

方案类型和 schema 主体：

| 类型 | Schema |
|------|--------|
| `home` | `pageStyle`、`components`、`modules` |
| `profile` | `pageStyle`、`modules` |
| `tabbar` | `items` |
| `floating` | `enabled`、`mode`、`position`、`items`、`hiddenPages`、`style` |

兼容规则：

- 首页历史 list schema 会规范化为 `components` 和 `modules`。
- 个人中心历史 list schema 会规范化为 `modules`。
- TabBar 历史 list schema 会规范化为 `items`。
- 图片资源会做历史素材映射和上传 URL 归一化。

## 新增装修组件开发步骤

1. 明确组件属于首页、个人中心、底部导航还是悬浮按钮。
2. 定义 schema 字段和默认值。
3. 在后台编辑器中新增组件面板和字段控件。
4. 在后台预览中渲染组件。
5. 在 `ClientDecorationSchemeService` 和 `DecorationService` 中补 schema 规范化。
6. 在 UniApp 渲染组件中补运行时渲染。
7. 如涉及跳转目标，优先接入页面库或 target picker。
8. 补充测试和文档。

## 开发注意事项

- 系统默认方案和系统主题不能被编辑或删除。
- 启用方案要写快照，便于追踪发布历史。
- 装修运行时接口无鉴权，不要返回管理字段或敏感配置。
- 图片资源返回前要做归一化，避免前端拿到不可访问路径。
- 新增页面路径时优先写入页面库，再通过选择器引用。
- 业务入口展示要和业务开关联动，避免展示不可用功能。

## 测试入口

| 测试 | 用途 |
|------|------|
| `backend/tests/Feature/Client/ClientDecorationServiceContractTest.php` | 页面库、方案保护、schema、主题和运行时契约 |
| `frontend/admin/apps/web-antd/src/views/client/decorate/utils/useModuleSpacing.test.ts` | 装修间距工具测试 |

建议执行：

```bash
composer --working-dir backend test -- --filter ClientDecoration
```

如修改后台装修编辑器，另执行前端相关测试：

```bash
corepack pnpm@10.28.2 --dir frontend/admin run test:unit -- apps/web-antd/src/views/client/decorate/utils/useModuleSpacing.test.ts
```
