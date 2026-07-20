---
name: goods-spec-toggle-state
description: MallBase Vben Admin 商品规格切换状态规则；处理商品规格、SKU 表单、单规格与多规格切换时使用。
---

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
4. 媒体字段（主图、主视频、规格值图、SKU 图）在 UI 中统一使用 Upload 的 `FileInfo`，提交时遵循商品 `MediaValue = number | string` 契约：优先提交数值型 `asset_id`，仅在兼容旧数据或后端明确接受路径时提交字符串。
5. 后端返回素材 ID 与 `*_full_url` 时，回填对象应包含 `url: String(asset_id)`、数值型 `asset_id`、`full_url` 和可读 `name`；预览地址不能反向覆盖提交值。
6. `full_url` 只用于预览，不得提交；规格切换缓存必须保留完整 `FileInfo`，避免切换后丢失素材 ID 或预览地址。

## 自检清单

- [ ] 编辑商品：多规格 -> 单规格 -> 多规格，规格名/规格值/SKU 数据不丢。
- [ ] 单规格保存后，接口查询无历史多规格残留。
- [ ] 编辑回填后，主图/主视频/SKU 图均可直接预览。
- [ ] 规格值图和 SKU 图提交的是素材 ID 或后端明确支持的兼容路径，不包含 `full_url`。
