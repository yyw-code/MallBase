# ThinkPHP 规则：后台路由与权限元数据

## 适用范围

`backend/route/admin/*.php` 的新增与修改。

## 标准模板

```php
<?php

use think\facade\Route;
use app\admin\model\auth\Permission;

Route::group('module', function () {
    // 列表（菜单类型）
    Route::get('list', 'getList')->name('SystemModuleList')
        ->option([
            '_alias' => '模块列表',
            '_desc'  => '获取模块列表',
            '_auth'  => true,
            '_type'  => Permission::TYPE_MENU,
        ]);

    // 详情（菜单类型）
    Route::get('info/:id', 'getInfo')->name('SystemModuleInfo')
        ->option([
            '_alias' => '模块详情',
            '_auth'  => true,
            '_type'  => Permission::TYPE_MENU,
        ]);

    // 创建（按钮类型）
    Route::post('create', 'create')->name('SystemModuleCreate')
        ->option([
            '_alias' => '创建模块',
            '_auth'  => true,
            '_type'  => Permission::TYPE_BUTTON,
        ]);

    // 更新（按钮类型，路径参数）
    Route::put('update/:id', 'update')->name('SystemModuleUpdate')
        ->option([
            '_alias' => '更新模块',
            '_auth'  => true,
            '_type'  => Permission::TYPE_BUTTON,
        ]);

    // 删除（按钮类型，路径参数）
    Route::delete('delete/:id', 'delete')->name('SystemModuleDelete')
        ->option([
            '_alias' => '删除模块',
            '_auth'  => true,
            '_type'  => Permission::TYPE_BUTTON,
        ]);
})->prefix('module/')->option([
    '_alias'      => '模块管理',
    '_group_name' => '模块管理',
    '_group_code' => 'SystemModule',
    '_desc'       => '模块管理',
    '_auth'       => true,
    '_type'       => Permission::TYPE_MENU,
]);
```

## 强制规则

1. 路由风格对齐 `backend/route/admin/admin.php`。
2. 带 ID 的路由使用 `/:id`，不使用 query id。
3. `name()` 使用 `System{Module}{Action}` 命名（必须以 `System` 开头）。
4. 分组 `_group_code` 使用 `System{Module}`（如 `SystemAdmin`、`SystemGoodsCategory`）。
5. 写操作 `_type` 使用 `Permission::TYPE_BUTTON`，读操作使用 `Permission::TYPE_MENU`。
6. 路由与分组都要带权限元数据（至少 `_alias`、`_auth`）。
7. 必须导入：`use app\admin\model\auth\Permission;`

### 权限类型对应

| 操作 | `_type` |
|------|---------|
| `list`、`info`、`all` | `Permission::TYPE_MENU` |
| `create`、`update`、`delete`、`updateStatus` | `Permission::TYPE_BUTTON` |

### 命名示例

```
SystemAdminList          SystemAdminCreate
SystemGoodsCategoryList  SystemGoodsCategoryCreate
SystemUserGroupCreate    SystemClientUserGroupCreate
```

## 禁止项

- ❌ 缺少 `name()`。
- ❌ `_type` 使用字符串 `'api'`。
- ❌ 新路由未同步权限命令。
- ❌ 路由命名不加 `System` 前缀。
- ❌ 使用查询参数传递 ID（如 `?id=`）。

## 自检清单

- [ ] 路由命名、分组编码符合 `System` 前缀约定。
- [ ] 新增路由后执行 `php think SyncPermissions`。

## Related

- `backend/route/admin/admin.php` — 权威参考实现
- `backend/route/admin/goods.php` — 已对齐的示例
