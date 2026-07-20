---
name: route-permission-system
description: MallBase ThinkPHP 后台路由与权限元数据规则；新增或调整 backend/route/api/admin 路由、System 权限码、菜单元数据、/:id 路径参数、共享 _permission 或 sync:permissions 同步时使用。
---

# 后台路由与权限元数据

## 当前入口

后台业务路由放在 `backend/route/api/admin/*.php`。它们由 `backend/route/admin.php` 加载，并继承后台 API 组的鉴权、权限、请求锁和操作日志中间件。

新增路由前先查看同领域文件；通用 CRUD 可参考 `backend/route/api/admin/admin.php`、`goods.php`。

## 路由契约

1. 需要 ID 的资源路由使用 `info/:id`、`update/:id`、`delete/:id`，Controller 从路径参数读取 `id`。
2. 参与后台授权的路由名使用 `System{Domain}{Action}`；分组 `_group_code` 使用稳定的 `System{Domain}`。
3. 分组通过 `_group_name`、`_group_code`、`_path`、`_component`、`_parent` 等元数据生成后端驱动菜单。
4. 写操作显式使用 `Permission::TYPE_BUTTON`；独立的列表、详情等读权限使用 `Permission::TYPE_MENU`。
5. 不参与授权的路由显式设置 `_auth => false`，并按现有路由组规则移除不需要的中间件。
6. `prefix()` 使用当前 Controller 命名空间，例如 `admin.goods.GoodsController/`。

```php
Route::group('goods/brand', function () {
    Route::get('list', 'list')
        ->name('SystemGoodsBrandList')
        ->option([
            '_alias' => '品牌列表',
            '_auth' => true,
            '_type' => Permission::TYPE_MENU,
        ]);
    Route::put('update/:id', 'update')
        ->name('SystemGoodsBrandUpdate')
        ->option([
            '_alias' => '更新品牌',
            '_auth' => true,
            '_type' => Permission::TYPE_BUTTON,
        ]);
})->prefix('admin.goods.GoodsBrandController/')
    ->option([
        '_group_name' => '商品品牌',
        '_group_code' => 'SystemGoodsBrand',
        '_path' => '/goods/brand',
        '_component' => '/goods/brand/index',
        '_parent' => 'SystemGoodsManagement',
        '_auth' => true,
    ]);
```

## 共享权限

多个接口共用一个权限码时使用 `_permission`。代表路由保留 `_alias` 并生成权限节点；仅复用该权限的辅助路由可省略 `_alias`，避免同步出重复节点。不要用 `_permission` 掩盖本应独立授权的写操作。

## 同步与自检

从 `backend/` 目录先预览，再同步：

```bash
php think sync:permissions --preview
php think sync:permissions
```

- [ ] 文件位于 `backend/route/api/admin/`。
- [ ] 路由名、分组码和父级权限码稳定且唯一。
- [ ] ID 使用 `/:id`，前后端 API 契约一致。
- [ ] `--preview` 输出符合预期后再执行同步。
