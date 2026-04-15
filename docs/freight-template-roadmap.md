# 运费模板落地路线图

> 本文档记录运费模板“层级化规则匹配 + 运费计算器”能力落地后的后续接入方向。  
> 当前阶段（Phase 1–4）已完成基建，但尚未接入订单/购物车链路，订单侧运费仍走旧的历史逻辑。  
> 本文件为下一阶段（订单接入）预留对齐入口，避免后续实施时缺少上下文。

## 已完成范围

| 阶段 | 能力 | 关键位置 |
|---|---|---|
| Phase 1 | 区域规则表新增 `match_level`；`RegionResolverService` 支持任意层级解析/重匹配 | `backend/app/admin/model/setting/FreightTemplateRule.php`、`backend/app/service/RegionResolverService.php`、`install/database/upgrade/2026xxxx_freight_template_rule_match_level.sql` |
| Phase 2 | `FreightTemplateService` 校验改为“按层级桶去重”，允许跨层级覆盖；失效回写同步 | `backend/app/admin/service/setting/FreightTemplateService.php` |
| Phase 3 | 运费计算服务 + DTO，纯算法路径已可单测覆盖 | `backend/app/service/FreightCalculatorService.php`、`backend/app/service/dto/{RegionPathDto,FreightCalculationResult}.php`、`backend/tests/Unit/Service/FreightCalculatorServiceTest.php` |
| Phase 4 | 运费模板弹窗支持任意层级地区，允许无规则（= 纯全国默认运费），增加优先级提示 | `frontend/admin/apps/web-antd/src/components/region-picker/index.vue`、`frontend/admin/apps/web-antd/src/views/settings/freight-template/freight-template-modal.vue` |

## 尚未接入的链路（Phase 5+）

`FreightCalculatorService::calculate()` 已可独立使用，但以下业务入口尚未调用它：

- 购物车结算预览（`cart preview` / `cart settlement`）
- 下单提交（`order create`）
- 订单详情回显（`order detail` 展示的“运费”应来自计算结果）
- 后台订单/导出

## 订单接入需要补齐的字段与决策

### 1. 商品侧：`template_id` 归属

- SKU 还是 SPU 持有运费模板 ID，需要产品决策。  
  初步倾向：**SPU 层挂 `freight_template_id`，SKU 不单独覆盖**；特殊 SKU 如需差异运费，用独立 SPU 处理。
- 历史数据迁移策略：`freight_template_id = 0` 视为“全店包邮”，保持与计算器 `templateId=0 → FreightCalculationResult::free()` 一致。

### 2. 多店铺 / 多模板订单合并策略

一次下单可能包含多家店铺/多个模板的商品，需要约定：

- **默认策略**：按 `freight_template_id` 分组，分别调用 `calculate()`，结果求和。
- **同模板多 SKU**：按模板 `charge_type` 聚合：
  - `piece`：件数 = 选购总件数
  - `weight`：重量 = `sku_weight * 件数` 累加
- **包邮阈值**：独立存储于模板外（例如 `shop_config.free_shipping_amount`），在计算器输出 `fee > 0` 之后再做阈值覆盖，不要塞进计算器本体——保持计算器为纯规则执行器。

### 3. 地址 → RegionPathDto

收货地址表 `mb_user_address` 已有 `province_id / city_id / district_id / street_id` 四级字段，直接组装 `RegionPathDto::fromArray([...])` 即可。

- 地址缺街道时：`street_id=0`，计算器在查 level=4 时会落空，自动回落到区→市→省→默认，不需要额外兼容。
- 地区失效（被行政区划调整下线）：已有 `FreightTemplateService::refreshInvalidData` 负责把失效规则标记为 `region_status=0`，计算器只读 `region_status=1` 的规则，天然跳过失效规则。

### 4. 购物车预览接口预留

结算页需要在不创建订单的情况下拿到运费预估，建议新增/扩展接口：

```
POST /api/shop/cart/preview
req:
  address_id: int
  items: [{ sku_id, quantity }]
resp:
  goods_amount: decimal
  freight_fee: decimal          # 新增：来自 FreightCalculatorService
  freight_breakdown: [          # 新增：多模板明细，前端可展示“XX店铺运费 ¥X.XX”
    { shop_id, template_id, fee, matched_level, matched_rule_id, source }
  ]
  total_amount: decimal
```

对应后端需要一个薄服务（暂命名 `OrderFreightAggregator`）负责：

1. 按模板分组
2. 聚合件数/重量
3. 循环调用 `FreightCalculatorService::calculate()`
4. 汇总并输出 breakdown

不要把聚合逻辑塞进 `FreightCalculatorService`——保持它“单模板 × 单地址 × 单计量”的纯签名。

### 5. 订单创建与落库

- 创建订单时把计算结果落 `mb_order.freight_fee` 以及（建议）`freight_snapshot` JSON 字段，包含：
  - `template_id / matched_rule_id / matched_level / source`  
  以便后续对账、客诉回查、以及规则变更后仍能还原当时计费依据。
- 订单创建后若模板规则变更，不回溯修改已创建订单；仅影响后续订单。

## 风险与注意事项

- **Swoole 无状态**：`FreightCalculatorService` 目前没有类级状态，订单聚合器亦应保持无状态，避免共享缓存污染。
- **规则数量上限**：单模板规则建议 < 200；若真出现超大模板，可在 `mb_freight_template_rule` 增加 `(template_id, match_level)` 联合索引（已在 Phase 1 预留空间）。
- **精度**：所有金额字段统一按两位小数 `PHP_ROUND_HALF_UP`，订单聚合时分步计算、最后一次性取整，以避免“分组求和后误差”。
- **测试基线**：订单接入时优先补三类测试：
  - 多模板分组汇总（`OrderFreightAggregatorTest`）
  - 购物车预览接口契约（基于项目既有 HTTP smoke test 模式）
  - 地址切换重新计算（e2e 回归）

## 相关引用

- `backend/app/service/FreightCalculatorService.php:22` 已预留 `@todo Phase 2: OrderService::calcFreight` 锚点
- 计算器单元测试示例：`backend/tests/Unit/Service/FreightCalculatorServiceTest.php`
- 规则校验/失效回写：`backend/app/admin/service/setting/FreightTemplateService.php`
