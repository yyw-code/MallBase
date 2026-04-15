# build-list-query-only

## 适用范围

- MallBase 项目内所有后台/客户端列表查询
- `Service::getList()`、`getAll()`、`getPage()`、`getTotal()`、导出前筛选等场景

## 强制规则

1. 列表查询统一在 Service 中定义 `buildListQuery(array $where)`。
2. `list`、`total`、`all`、`export` 等共享同一套筛选条件时，必须复用同一个 `buildListQuery()`。
3. 禁止新增 `withSearch()`。
4. 禁止新增模型 `search*Attr()` 作为列表筛选入口。
5. 新增筛选条件时，只改 `buildListQuery()`，不允许在其它地方复制一套查询条件。

## 实现要求

- `buildListQuery()` 只负责筛选条件构建，不夹杂分页、排序、关联补数。
- 对可能出现 `0` 的筛选值，必须使用显式判断：

```php
->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($query) use ($where) {
    $query->where('status', $where['status']);
})
```

- 关键词搜索统一用显式 `when()`，不要隐藏在模型搜索器中。
- 列表关联查询统一按下面规则选型：
  - 只为展示关联对象或补充结果字段，优先定义模型关系并使用 `with`
  - 关联字段参与 `where`、`whereLike`、`order`、`field alias` 或需要返回扁平列表字段时，优先使用 `join`
  - 不允许因为只是展示字段就默认写 `join`
  - 也不允许在需要关联字段参与主查询条件时硬套 `with`

## 禁止事项

- 禁止 `withSearch([...], $where)`
- 禁止 `searchKeywordAttr/searchStatusAttr/...`
- 禁止 `list` 和 `total` 各自维护不同筛选条件
- 禁止把“关系读取策略”和“列表筛选策略”拆成两套各自维护

## 迁移原则

- 旧代码发现模型搜索器后，优先迁移为 `buildListQuery()`。
- 迁移完成后删除对应 `search*Attr()`，不保留双轨兼容。
- 旧代码如果只是展示关联数据，优先补模型关系并收口为 `with`。
- 旧代码如果需要按关联表字段搜索/排序，保留或迁移为 `join`。
