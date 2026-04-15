# ThinkPHP 规则：商品统一售卖单元必须落 SKU

## 适用范围

- 商品模块：`backend/app/admin/controller/goods/**`
- 商品模块：`backend/app/admin/service/goods/**`
- 商品模块：`backend/app/admin/model/goods/**`
- 商品编辑页：`frontend/admin/apps/web-antd/src/views/goods/goods/**`

## 强制规则

1. 商品底层售卖单元统一为 SKU，单规格和多规格都必须落 `mb_goods_sku`。
2. `mb_goods` 只作为商品主表和汇总表，不再作为“单规格唯一库存真相来源”。
3. `mb_goods.spec_type` 是正式字段，固定语义：
   - `1 = 单规格`
   - `2 = 多规格`
4. 禁止再通过 `skus.length`、`spec_meta` 是否为空推断商品规格形态。
5. 单规格商品必须生成 1 条默认 SKU，固定规则：
   - `spec_values = ''`
   - `spec_meta = []`
   - `price/market_price/stock/image/status` 与商品主表保持同步
6. 多规格商品继续使用组合 SKU，`spec_meta` 只用于规格设计器回显，不替代 SKU。

## 推荐做法

1. 前端提交时始终带 `spec_type`。
2. 后端保存前先按 `spec_type` 归一化 `spec_meta` 和 `skus`。
3. 读取详情和列表时直接返回 `spec_type`，前端不要猜测。
4. 历史数据迁移时，先补 `spec_type`，再为单规格商品补默认 SKU。

## 反例

- 单规格只存 `mb_goods.price/stock`，不落 SKU
- 用 `detail.skus.length > 0` 判断多规格
- 用 `spec_meta` 是否为空判断单规格

## 自检清单

- [ ] `mb_goods` 已存在正式字段 `spec_type`
- [ ] 单规格商品保存后存在 1 条默认 SKU
- [ ] 多规格商品保存后保持组合 SKU
- [ ] 编辑页以 `spec_type` 判断规格形态
- [ ] 订单/客户端后续可统一按 SKU 建模
