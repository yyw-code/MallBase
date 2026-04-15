# Vben 规则：API 路径参数一致性

## 适用范围

`frontend/admin/apps/web-antd/src/api/**` 的接口定义。

## 强制规则

1. 对应后端 `/:id` 路由时，前端必须拼接路径参数。
2. 不用 query/body 传递本应放在路径中的 `id`。

## 标准写法

```typescript
// 详情
requestClient.get(`/module/info/${id}`);

// 更新（id 在路径中，data 在请求体中）
requestClient.put(`/module/update/${id}`, data);

// 删除
requestClient.delete(`/module/delete/${id}`);

// 状态更新
requestClient.put(`/module/updateStatus/${id}`, { status });
```

## 禁止

- ❌ 使用查询参数传递 ID：`requestClient.delete('/module/delete', { params: { id } })`
- ❌ 在请求体中混入 ID：`requestClient.put('/module/update', { id, ...data })`

## 自检清单

- [ ] CRUD 路径与后端路由完全对齐。
- [ ] 无 `?id=` 形式替代路径参数。
- [ ] 更新/删除操作中 ID 在路径而非请求体。
