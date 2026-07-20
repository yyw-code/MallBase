---
name: list-query-sync
description: MallBase ThinkPHP 列表查询与分页返回规则；实现或调整 Service 的 buildListQuery、分页 list/total、动态筛选、关联查询、统计、导出或 compact('total', 'list') 返回时使用。
---

# ThinkPHP 列表查询同源规则

## 查询构建

当列表、总数、统计或导出共享筛选条件时，在 Service 中集中定义 `buildListQuery(array $where)`：

```php
protected function buildListQuery(array $where)
{
    return $this->model()
        ->when(!empty($where['keyword']), function ($query) use ($where) {
            $query->whereLike('name|subtitle', "%{$where['keyword']}%");
        })
        ->when(
            ($where['status'] ?? null) !== null && $where['status'] !== '',
            function ($query) use ($where) {
                $query->where('status', $where['status']);
            }
        );
}
```

1. 只在构建方法中维护共享筛选条件，分页、展示排序和结果补充留在调用方。
2. 用新的查询实例分别执行列表与总数，或在修改查询前显式克隆；不要让 `page/order/select` 污染计数查询。
3. 对 `0`、`false` 等有效筛选值使用显式空值判断，不使用 `empty()`。
4. 新增列表筛选不要再引入模型 `withSearch()` 或 `search*Attr()` 双轨入口。
5. 统计、导出需要与页面列表同口径时复用同一构建方法，不复制条件。

## 关联选择

- 关联数据只用于展示时，优先模型关系和 `with()`。
- 关联字段参与筛选、排序、聚合或扁平字段输出时，使用 `join`、`exists` 或明确的关联 ID 查询。
- 先查关联 ID 再 `whereIn` 时，空结果必须显式返回空集，不能让空数组退化为全量查询。

## 分页返回

返回契约正好是 `{total, list}` 时，变量统一命名为 `$total`、`$list`，并返回：

```php
$list = $this->buildListQuery($where)
    ->order('id', 'desc')
    ->page($page, $limit)
    ->select()
    ->toArray();
$total = $this->buildListQuery($where)->count();

return compact('total', 'list');
```

接口还包含 tabs、stats 或其它字段时，按真实契约返回，不为了使用 `compact()` 隐藏额外字段。

## 自检

- [ ] 列表、总数、统计和导出的共享筛选只有一份。
- [ ] `status=0` 等值能正确命中。
- [ ] 计数查询未携带分页或展示排序副作用。
- [ ] 关联策略与筛选、展示用途一致。
