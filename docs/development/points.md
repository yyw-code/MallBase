# 积分开发文档

本文面向二次开发和维护人员，说明积分模块的表、服务、接口、前端入口、调用链、扩展点和测试入口。

## 数据表

| 表 | 用途 | 来源 |
|----|------|------|
| `mb_user_points` | 用户积分账户聚合值 | `backend/install/data/schema/16_mb_user_points.sql` |
| `mb_user_points_log` | 用户积分流水 | `backend/install/data/schema/16_mb_user_points.sql` |
| `mb_points_rule` | 积分规则 | `backend/install/data/schema/16_mb_user_points.sql` |
| `mb_order_points_reward` | 订单积分赠送快照 | `backend/install/data/schema/16_mb_user_points.sql` |
| `mb_order_points_reward_item` | 订单项积分赠送快照 | `backend/install/data/schema/16_mb_user_points.sql` |
| `mb_order_points_deduction` | 订单积分抵扣记录 | `backend/install/data/schema/16_mb_user_points.sql` |
| `mb_points_goods` | 积分商城商品 | `backend/install/data/schema/18_mb_points_exchange.sql` |
| `mb_points_exchange_order` | 积分兑换单 | `backend/install/data/schema/18_mb_points_exchange.sql` |
| `mb_points_exchange_order_log` | 积分兑换单操作日志 | `backend/install/data/schema/18_mb_points_exchange.sql` |

相关升级 SQL：

- `backend/install/data/upgrade/2026_06_30_user_points.sql`
- `backend/install/data/upgrade/2026_07_01_marketing_points_member_config.sql`
- `backend/install/data/upgrade/2026_07_01_goods_marketing_modes.sql`
- `backend/install/data/upgrade/2026_07_01_points_exchange.sql`
- `backend/install/data/upgrade/2026_07_05_delivery_type.sql`

## 后端入口

| 类型 | 文件 |
|------|------|
| 积分账户服务 | `backend/app/service/user/UserPointsAccountService.php` |
| 积分功能开关服务 | `backend/app/service/marketing/PointsFeatureService.php` |
| 前台积分服务 | `backend/app/service/client/user/UserPointsService.php` |
| 后台用户积分服务 | `backend/app/service/admin/user/UserPointsService.php` |
| 前台积分商城服务 | `backend/app/service/client/points/PointsMallService.php` |
| 后台积分规则服务 | `backend/app/service/admin/marketing/PointsRuleService.php` |
| 后台积分商品服务 | `backend/app/service/admin/marketing/PointsGoodsService.php` |
| 后台兑换单服务 | `backend/app/service/admin/marketing/PointsExchangeOrderService.php` |
| 兑换单生命周期服务 | `backend/app/service/marketing/PointsExchangeOrderLifecycleService.php` |
| 兑换单日志服务 | `backend/app/service/marketing/PointsExchangeOrderLogService.php` |
| 积分释放命令 | `backend/app/command/PointsReleaseCommand.php` |
| 冻结积分释放 Job | `backend/app/job/ReleaseFrozenPointsJob.php` |

## 后台 API

| 功能 | 方法和路径 | 控制器 |
|------|------------|--------|
| 积分规则列表 | `GET /admin/api/points/rule/list` | `PointsRuleController::list()` |
| 积分规则详情 | `GET /admin/api/points/rule/info/:id` | `PointsRuleController::info()` |
| 积分规则场景 | `GET /admin/api/points/rule/scenes` | `PointsRuleController::scenes()` |
| 新增积分规则 | `POST /admin/api/points/rule/create` | `PointsRuleController::create()` |
| 更新积分规则 | `PUT /admin/api/points/rule/update/:id` | `PointsRuleController::update()` |
| 删除积分规则 | `DELETE /admin/api/points/rule/delete/:id` | `PointsRuleController::delete()` |
| 积分规则状态 | `PUT /admin/api/points/rule/updateStatus/:id` | `PointsRuleController::updateStatus()` |
| 积分商品列表 | `GET /admin/api/points/goods/list` | `PointsGoodsController::list()` |
| 新增积分商品 | `POST /admin/api/points/goods/create` | `PointsGoodsController::create()` |
| 更新积分商品 | `PUT /admin/api/points/goods/update/:id` | `PointsGoodsController::update()` |
| 删除积分商品 | `DELETE /admin/api/points/goods/delete/:id` | `PointsGoodsController::delete()` |
| 积分商品状态 | `PUT /admin/api/points/goods/updateStatus/:id` | `PointsGoodsController::updateStatus()` |
| 兑换单列表 | `GET /admin/api/points/exchange-order/list` | `PointsExchangeOrderController::list()` |
| 兑换单详情 | `GET /admin/api/points/exchange-order/info/:id` | `PointsExchangeOrderController::info()` |
| 兑换单发货 | `POST /admin/api/points/exchange-order/ship/:id` | `PointsExchangeOrderController::ship()` |
| 完成兑换单 | `POST /admin/api/points/exchange-order/complete/:id` | `PointsExchangeOrderController::complete()` |
| 关闭兑换单 | `POST /admin/api/points/exchange-order/close/:id` | `PointsExchangeOrderController::close()` |
| 积分流水 | `GET /admin/api/points/log/list` | `UserPointsController::logs()` |
| 用户积分流水 | `GET /admin/api/user/points/logs` | `UserPointsController::logs()` |
| 调整用户积分 | `POST /admin/api/user/points/adjust` | `UserPointsController::adjust()` |

后台前端 API：

- `frontend/admin/apps/web-antd/src/api/points/rule.ts`
- `frontend/admin/apps/web-antd/src/api/points/goods.ts`
- `frontend/admin/apps/web-antd/src/api/points/exchange-order.ts`
- `frontend/admin/apps/web-antd/src/api/points/log.ts`
- `frontend/admin/apps/web-antd/src/api/user/index.ts`

后台页面：

- `frontend/admin/apps/web-antd/src/views/points/rule/`
- `frontend/admin/apps/web-antd/src/views/points/goods/`
- `frontend/admin/apps/web-antd/src/views/points/exchange-order/`
- `frontend/admin/apps/web-antd/src/views/points/log/`
- `frontend/admin/apps/web-antd/src/views/user/index.vue`
- `frontend/admin/apps/web-antd/src/views/goods/goods/goods-edit.vue`

## 前台 API

| 功能 | 方法和路径 | 控制器 |
|------|------------|--------|
| 积分商城列表 | `GET /client/api/points/mall/list` | `PointsMallController::list()` |
| 积分商品详情 | `GET /client/api/points/mall/detail/:id` | `PointsMallController::detail()` |
| 提交积分兑换 | `POST /client/api/points/mall/exchange` | `PointsMallController::exchange()` |
| 我的兑换单 | `GET /client/api/points/mall/orders` | `PointsMallController::orders()` |
| 我的兑换单详情 | `GET /client/api/points/mall/order/:id` | `PointsMallController::order()` |
| 取消兑换单 | `POST /client/api/points/mall/order/:id/cancel` | `PointsMallController::cancel()` |
| 我的积分 | `GET /client/api/points/info` | `UserPointsController::info()` |
| 我的积分流水 | `GET /client/api/points/logs` | `UserPointsController::logs()` |

前台 API 文件：

- `frontend/uniapp/api/points/points.js`
- `frontend/uniapp/api/points/mall.js`

前台页面：

- `frontend/uniapp/pages-sub/points/index.vue`
- `frontend/uniapp/pages-sub/points/records.vue`
- `frontend/uniapp/pages-sub/points/mall.vue`
- `frontend/uniapp/pages-sub/points/mall-detail.vue`
- `frontend/uniapp/pages-sub/points/exchange-confirm.vue`
- `frontend/uniapp/pages-sub/points/exchange-orders.vue`
- `frontend/uniapp/pages-sub/points/exchange-detail.vue`
- `frontend/uniapp/pages-sub/order/confirm.vue`

## 核心调用链

### 订单积分抵扣

```text
OrderController::preview/create
-> OrderService::calcAmounts()
-> UserPointsAccountService::deductionQuote()
-> OrderService::create()
-> UserPointsAccountService::deductForOrder()
-> mb_user_points / mb_user_points_log / mb_order_points_deduction
```

### 订单完成返积分

```text
OrderStatusMachine
-> UserPointsAccountService::rewardOrderCompleted()
-> buildOrderRewardItems()
-> mb_order_points_reward / mb_order_points_reward_item
-> mb_user_points.frozen_points
-> mb_user_points_log(order_complete)
```

### 冻结积分释放

```text
php think points:release
-> PointsReleaseCommand
-> UserPointsAccountService::releaseDueRewards()
-> releaseReward()
-> frozen_points 减少
-> balance_points 增加或 debt_points 抵扣
-> mb_user_points_log(order_reward_release_frozen / order_reward_release)
```

### 售后退款回收

```text
退款完成
-> UserPointsAccountService::rollbackRefundCompleted()
-> recoverRewardByRefund()
-> returnDeductionByRefund()
-> 回收奖励积分
-> 返还已抵扣积分
-> mb_user_points_log(refund / refund_frozen / refund_debt / refund_deduction_return)
```

### 积分商城兑换

```text
PointsMallController::exchange()
-> PointsMallService::exchange()
-> lockedExchangeSnapshot()
-> assertLimit()
-> StockService::decrease()
-> UserPointsAccountService::deductForExchange()
-> mb_points_exchange_order
-> PointsExchangeOrderLogService::record()
```

### 关闭待发货兑换单

```text
PointsExchangeOrderController::close()
或 PointsMallController::cancel()
-> PointsExchangeOrderLifecycleService::closePending()
-> StockService::restore()
-> UserPointsAccountService::returnExchangeByOperator()
-> 兑换单状态改为已关闭
-> 记录兑换单日志
```

## 关键业务类型

积分流水 `biz_type`：

| 常量 | 值 | 说明 |
|------|----|------|
| `BIZ_ORDER_COMPLETE` | `order_complete` | 订单奖励冻结 |
| `BIZ_ORDER_REWARD_RELEASE` | `order_reward_release` | 积分释放 |
| `BIZ_ORDER_REWARD_RELEASE_FROZEN` | `order_reward_release_frozen` | 冻结释放 |
| `BIZ_REFUND` | `refund` | 退款回收 |
| `BIZ_REFUND_FROZEN` | `refund_frozen` | 退款回收冻结 |
| `BIZ_REFUND_DEBT` | `refund_debt` | 退款欠账 |
| `BIZ_ORDER_DEDUCTION` | `order_deduction` | 订单积分抵扣 |
| `BIZ_ORDER_DEDUCTION_RETURN` | `order_deduction_return` | 订单取消返还 |
| `BIZ_REFUND_DEDUCTION_RETURN` | `refund_deduction_return` | 退款返还抵扣积分 |
| `BIZ_POINTS_EXCHANGE` | `points_exchange` | 积分商品兑换 |
| `BIZ_POINTS_EXCHANGE_RETURN` | `points_exchange_return` | 兑换关闭返还 |
| `BIZ_ADMIN_ADJUST` | `admin_adjust` | 后台调整 |

积分账户类型：

| 值 | 说明 |
|----|------|
| `balance` | 可用积分 |
| `frozen` | 冻结积分 |
| `debt` | 欠账积分 |

## 命令

```bash
php think points:release
```

用途：释放到期冻结积分。生产环境可通过计划任务或队列定期执行。

## 新增积分场景开发步骤

1. 明确积分变动方向：增加、扣减、冻结、释放、欠账。
2. 定义新的 `biz_type` 常量和展示文案。
3. 设计幂等键，通常使用业务单号。
4. 在 Service 中复用 `changeAvailable()` 或新增明确的账户变动方法。
5. 如果涉及冻结、库存、订单状态，必须在事务内处理。
6. 同步后台流水筛选选项和前端展示文案。
7. 补充测试，覆盖重复调用、余额不足、状态不允许、回滚等边界。
8. 更新 [../operation/points.md](../operation/points.md) 和 [../logic/points.md](../logic/points.md)。

## 开发注意事项

- 不要直接更新 `mb_user_points` 聚合值，必须同时写 `mb_user_points_log`。
- 订单奖励、积分抵扣、积分兑换都需要幂等保护。
- 积分商城兑换同时影响积分、兑换库存、SKU 库存和兑换单，必须放在同一事务中。
- 待发货兑换单关闭时要同时返还积分和恢复库存。
- 已发货或已完成兑换单不能走关闭返还流程。
- 商品 / SKU 积分规则变更不回算历史订单，历史订单以快照为准。

## 测试入口

| 测试 | 用途 |
|------|------|
| `backend/tests/Feature/Points/PointsAccountServiceContractTest.php` | 积分账户、抵扣、赠送、释放、退款回收契约 |
| `backend/tests/Feature/Points/PointsMallServiceContractTest.php` | 积分商城兑换、幂等、关闭、发货、完成契约 |

建议执行：

```bash
composer --working-dir backend test -- --filter Points
```
