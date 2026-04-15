# VbenAdmin 规则：商品规格切换状态保真

## 适用范围

- `frontend/admin/apps/web-antd/src/views/goods/goods/goods-edit.vue`
- `frontend/admin/apps/web-antd/src/views/goods/goods/composables/useGoodsEdit.ts`

## 强制规则

1. 规格类型切换遵循“可逆”原则：
   - `multi -> single`：仅隐藏多规格编辑态，但要缓存 `attrs + skuRows` 草稿；
   - `single -> multi`：优先恢复草稿，不能让用户已编辑数据丢失。
2. 提交语义必须明确：
   - 多规格提交 `skus[]`；
   - 单规格提交 `skus: []`（显式清空旧 SKU，避免后端残留）。
3. 编辑回填时若后端有 SKU，需自动初始化为多规格并同步写入草稿缓存。
4. 媒体字段（主图/主视频/SKU 图）必须统一用 Upload 组件返回的 `url/full_url` 对象格式回填，避免回显不一致。

## 自检清单

- [ ] 编辑商品：多规格 -> 单规格 -> 多规格，规格名/规格值/SKU 数据不丢。
- [ ] 单规格保存后，接口查询无历史多规格残留。
- [ ] 编辑回填后，主图/主视频/SKU 图均可直接预览。
