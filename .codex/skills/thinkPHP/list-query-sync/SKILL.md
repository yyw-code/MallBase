# ThinkPHP 规则：分页 list/total 条件同源

## 适用范围

适用于 Service 层分页查询方法（例如 `getList`）。

## Problem

`list` 查询用 `if/else` 手动堆叠条件，`total` 计数单独重写一遍，两套条件不同步：

```php
// WRONG：list 和 total 是两套独立条件，极易漏加新筛选项
$query = $this->model()->order('id', 'desc');
if (!empty($where['keyword'])) {
    $query->whereLike('mobile|email|nickname', "%{$where['keyword']}%");
}
$list = $query->page($page, $limit)->select();

// ← total 单独重写，新增条件忘记加就静默出错
$total = $this->model()
    ->when(!empty($where['keyword']), ...)
    ->count();
```

**后果**：新增筛选项（如 group_ids、tag_ids）时，只加到 `$list` 忘记加到 `$total`，
导致搜索结果 5 条但总数显示 100——分页与前端均异常。

## 强制规则

1. `list` 与 `total` 必须复用同一个查询构建方法（如 `buildListQuery`）。
2. 不得维护两套筛选条件。
3. `status` 条件必须兼容 `0` 值。

## 推荐写法

```php
public function getList(array $where = [], int $page = 1, int $limit = 15): array
{
    $list  = $this->buildListQuery($where)->order('id', 'desc')->page($page, $limit)->select();
    $total = $this->buildListQuery($where)->count();  // ← 复用，不重写

    $list = $list->toArray();
    return compact('total', 'list');
}

protected function buildListQuery(array $where)
{
    return $this->model()
        ->when(!empty($where['keyword']), function ($q) use ($where) {
            $q->whereLike('mobile|email|nickname', "%{$where['keyword']}%");
        })
        ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
            $q->where('status', $where['status']);
        });
}
```

### status 筛选的特殊处理

`status` 值为 `0`（禁用）时 `!empty()` 会误判为空，必须用：

```php
// 正确：0 值也能通过筛选
($where['status'] ?? null) !== null && $where['status'] !== ''
```

### 关联表筛选兼容 ThinkPHP 8 / Swoole

`whereHas` 在 Swoole 模式下有已知问题，改用「先查关联 ID → 再 whereIn」：

```php
$groupUserIds = $this->model(UserGroupRelation::class)
    ->whereIn('group_id', $where['group_ids'])
    ->column('user_id');
$query->whereIn('id', array_unique($groupUserIds) ?: [0]);
//                                              ↑ 空数组时用 [0]，避免 whereIn([]) 返回全量
```

## 特殊注意

- 关联筛选优先"先查关联 ID，再 whereIn 主表"。
- `whereIn` 为空时需兜底（如 `[0]`），避免误查全量。
- 关联字段若直接参与主查询条件，优先 `join`；若仅做结果展示，优先 `with`。

## 自检清单

- [ ] 新增筛选项时，`list` 与 `total` 同步生效。
- [ ] `status=0` 时筛选结果正确。

## When to Use

在以下场景触发此模式：
- 编写 Service 层分页列表方法（`getList` / `index`）
- 方法同时返回 `list` 和 `total`（分页结构）
- 支持多个搜索条件动态过滤
- 新增筛选条件时，检查 `total` 是否也覆盖

## Related

- `backend/app/admin/service/user/UserService.php` — 项目中的修复范例（`buildListQuery`）
- CLAUDE.md「四、Service 强制无状态」
- `thinkPHP/list-return-compact` — 配套的返回格式规范
