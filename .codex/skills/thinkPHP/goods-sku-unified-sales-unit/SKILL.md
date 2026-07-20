---
name: goods-sku-unified-sales-unit
description: MallBase ThinkPHP 商品统一售卖单元规则；处理 spec_type、spec_meta、单规格默认 SKU、多规格组合 SKU、价格库存汇总、SKU 图片、订单销售单元或商品规格兼容时使用。
---

# 商品统一售卖单元

## 正式契约

1. 单规格和多规格商品都以 `mb_goods_sku` 作为售卖单元。
2. `mb_goods.spec_type` 的正式语义是 `1 = 单规格`、`2 = 多规格`；新增或修改调用方应显式提交该字段。
3. `spec_meta` 只服务规格设计器回显，不替代 SKU，也不应成为新代码判断规格类型的真相源。
4. `mb_goods.price`、`market_price`、`stock` 是从 SKU 汇总的商品级展示值，不是绕过 SKU 的独立库存来源。

当前 `GoodsService::normalizeSpecType()` 仍会对缺失 `spec_type` 的旧输入按 `spec_meta`/SKU 形态归一化。把它视为已有输入兼容边界，不要在新模块继续复制推断逻辑。

## 单规格

保存单规格商品时生成且只保留一条默认 SKU：

- `spec_values = ''`
- 价格、市场价、库存来自商品表单输入
- 图片使用归一化后的 `main_image` 素材 ID
- 状态与商品状态保持一致

保存完成后，以该 SKU 回写商品级价格和库存汇总。

## 多规格

1. 按组合 SKU 保存 `spec_values`、价格、库存、图片和状态。
2. 商品价格取可用 SKU 汇总逻辑的最低值，库存取 SKU 合计；沿用 `GoodsService::updatePriceAndStock()`，不要在旁路重写口径。
3. 订单、购物车、权益和库存的新逻辑应携带明确 SKU，不用“第一条 SKU”代替用户选择。
4. 切换规格类型时通过现有归一化链路清理不适用的 `spec_meta`、SKU 和独立详情状态。

## 自检

- [ ] 单规格保存后存在一条默认 SKU。
- [ ] 多规格没有被首条 SKU 或 `spec_meta` 冒充售卖单元。
- [ ] 商品级价格与库存由 SKU 汇总。
- [ ] 新接口直接读取和提交 `spec_type`。
- [ ] 回归覆盖 `backend/tests/Feature/Goods/GoodsSingleSpecDefaultSkuApiTest.php`。
