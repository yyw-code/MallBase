# ThinkPHP 规则：分页返回 compact

## 适用范围

适用于返回 `{ total, list }` 的 Service 分页方法。

## Problem

手动构建关联数组写法冗长且风格不统一：

```php
// WRONG：手动构建，冗余且风格不一致
return [
    'list'  => $listArray,
    'total' => $total,
];
```

## 强制规则

1. 返回统一使用 `compact('total', 'list')`。
2. 变量名必须与 compact 参数一致（必须是 `$list` 和 `$total`）。

## 推荐写法

```php
// CORRECT：简洁、统一
$list = $list->toArray();
return compact('total', 'list');
```

### 变量命名约定

```php
// 正确：变量名 $list 对应 compact 中的 'list'
$list = $query->page($page, $limit)->select();
$list = $list->toArray();  // 先转为数组
return compact('total', 'list');

// 错误：变量名不匹配，compact 捕获不到
$listArray = $list->toArray();  // ← $listArray 无法被 compact('list') 捕获
return compact('total', 'list');
```

## 自检清单

- [ ] 未手写 `['total' => ..., 'list' => ...]`。
- [ ] 无变量命名不一致问题（如 `$listArray`）。

## When to Use

- 所有 Service 层 `getList` / 分页列表方法的 return 语句
- 返回结构包含 `list` + `total` 的场景
- 新增分页接口时检查 return 写法

## Related

- `backend/app/admin/service/user/UserService.php`
- `backend/app/admin/service/user/UserTagService.php`
- `thinkPHP/list-query-sync` — 分页查询条件同步规范
