# 余额开发文档

本文面向二次开发和维护人员，说明余额模块的表、配置、服务、接口、前端入口、调用链、扩展点和测试入口。

## 数据表

| 表 | 用途 | 来源 |
|----|------|------|
| `mb_user_wallet` | 用户余额账户聚合值 | `backend/install/data/schema/11_mb_user_wallet.sql` |
| `mb_user_wallet_log` | 用户余额流水 | `backend/install/data/schema/11_mb_user_wallet.sql` |
| `mb_recharge_package` | 充值套餐 | `backend/install/data/schema/12_mb_recharge.sql` |

相关升级 SQL：

- `backend/install/data/upgrade/2026_05_31_balance_payment.sql`

## 配置项

| 配置项 | 含义 | 来源 |
|--------|------|------|
| `payment_balance_enabled` | 余额支付开关 | `backend/install/data/schema/03_mb_setting.sql` |
| `payment_wechat_enabled` | 微信支付开关，也是当前充值方式展示依据 | `backend/install/data/schema/03_mb_setting.sql` |

## 后端入口

| 类型 | 文件 |
|------|------|
| 余额支付服务 | `backend/app/service/client/payment/BalancePayService.php` |
| 前台余额服务 | `backend/app/service/client/user/UserWalletService.php` |
| 后台余额服务 | `backend/app/service/admin/user/UserWalletService.php` |
| 充值套餐后台服务 | `backend/app/service/admin/marketing/RechargePackageService.php` |
| 充值套餐前台服务 | `backend/app/service/client/recharge/RechargePackageService.php` |
| 售后退款服务 | `backend/app/service/admin/order/RefundOrderAdminService.php` |
| 支付方式配置服务 | `backend/app/service/client/ConfigService.php` |
| 前台余额控制器 | `backend/app/controller/client/user/UserWalletController.php` |
| 后台余额控制器 | `backend/app/controller/admin/user/UserWalletController.php` |
| 充值套餐控制器 | `backend/app/controller/admin/marketing/RechargePackageController.php` |
| 前台充值套餐控制器 | `backend/app/controller/client/recharge/RechargePackageController.php` |
| 钱包模型 | `backend/app/model/user/UserWallet.php` |
| 钱包流水模型 | `backend/app/model/user/UserWalletLog.php` |
| 充值套餐模型 | `backend/app/model/marketing/RechargePackage.php` |

## 后台 API

| 功能 | 方法和路径 | 控制器 |
|------|------------|--------|
| 用户余额流水 | `GET /admin/api/user/wallet/logs` | `UserWalletController::logs()` |
| 调整用户余额 | `POST /admin/api/user/wallet/adjust` | `UserWalletController::adjust()` |
| 充值套餐列表 | `GET /admin/api/marketing/recharge-package/list` | `RechargePackageController::list()` |
| 充值套餐详情 | `GET /admin/api/marketing/recharge-package/info/:id` | `RechargePackageController::info()` |
| 新增充值套餐 | `POST /admin/api/marketing/recharge-package/create` | `RechargePackageController::create()` |
| 更新充值套餐 | `PUT /admin/api/marketing/recharge-package/update/:id` | `RechargePackageController::update()` |
| 删除充值套餐 | `DELETE /admin/api/marketing/recharge-package/delete/:id` | `RechargePackageController::delete()` |
| 更新套餐状态 | `PUT /admin/api/marketing/recharge-package/updateStatus/:id` | `RechargePackageController::updateStatus()` |

后台前端 API：

- `frontend/admin/apps/web-antd/src/api/marketing/recharge-package.ts`
- `frontend/admin/apps/web-antd/src/api/user/index.ts`

后台页面：

- `frontend/admin/apps/web-antd/src/views/marketing/recharge-package/`
- `frontend/admin/apps/web-antd/src/views/user/index.vue`

## 前台 API

| 功能 | 方法和路径 | 控制器 |
|------|------------|--------|
| 我的余额 | `GET /client/api/user/wallet/info` | `UserWalletController::info()` |
| 我的余额流水 | `GET /client/api/user/wallet/logs` | `UserWalletController::logs()` |
| 充值套餐列表 | `GET /client/api/recharge/package/list` | `RechargePackageController::list()` |
| 已启用支付方式 | `GET /client/api/setting/payMethods` | `ConfigController::payMethods()` |
| 已启用充值方式 | `GET /client/api/setting/rechargeMethods` | `ConfigController::rechargeMethods()` |
| 订单支付 | 订单支付接口，`pay_method = PayMethod::BALANCE` | `OrderController::pay()` |

前台 API 文件：

- `frontend/uniapp/api/user/wallet.js`
- `frontend/uniapp/api/recharge/package.js`
- `frontend/uniapp/api/config.js`

前台页面：

- `frontend/uniapp/pages-sub/wallet/index.vue`
- `frontend/uniapp/pages-sub/wallet/records.vue`
- `frontend/uniapp/pages-sub/wallet/recharge.vue`
- 订单支付相关页面

## 核心调用链

### 余额支付

```text
OrderController::pay()
-> 校验 payment_balance_enabled
-> BalancePayService::payById()
-> 锁定订单和用户钱包
-> 扣减 mb_user_wallet.balance_cents
-> 写 mb_user_wallet_log(order_pay)
-> 写 mb_payment_log
-> OrderStatusMachine 转已支付
```

### 余额退款

```text
RefundOrderAdminService
-> 判断原订单 pay_method 为 PayMethod::BALANCE
-> executeBalanceRefund()
-> 锁定售后单、订单和用户钱包
-> 增加 mb_user_wallet.balance_cents
-> 写 mb_user_wallet_log(refund)
-> 售后单进入退款完成
```

### 后台调整余额

```text
UserWalletController::adjust()
-> admin\UserWalletService::adjust()
-> 校验用户、管理员、方向、金额和备注
-> 锁定或创建钱包
-> 增加或扣减 mb_user_wallet.balance_cents
-> 写 mb_user_wallet_log(admin_adjust)
```

### 前台余额页

```text
pages-sub/wallet/index.vue
-> getWalletInfo()
-> getWalletLogs()
-> client\UserWalletService::info/logs()
```

### 充值套餐展示

```text
pages-sub/wallet/recharge.vue
-> getRechargePackages()
-> client\RechargePackageService::list()
-> 只返回启用套餐
```

## 关键业务类型

余额流水 `biz_type`：

| 常量 | 值 | 说明 |
|------|----|------|
| `BIZ_ORDER_PAY` | `order_pay` | 订单余额支付 |
| `BIZ_REFUND` | `refund` | 售后退款退回余额 |
| `BIZ_RECHARGE` | `recharge` | 余额充值 |
| `BIZ_ADMIN_ADJUST` | `admin_adjust` | 后台调整 |

方向：

| 值 | 说明 |
|----|------|
| `income` | 增加余额 |
| `expense` | 扣减余额 |

## 新增余额变动场景开发步骤

1. 明确余额方向：增加还是扣减。
2. 明确幂等业务键，通常使用业务单号。
3. 在 Service 中统一做金额元转分。
4. 在事务内锁定钱包，校验余额或容量。
5. 更新 `mb_user_wallet` 聚合值。
6. 同步写 `mb_user_wallet_log`。
7. 如果影响订单、售后或支付日志，保持同一事务或明确一致性边界。
8. 同步后台流水筛选、前台展示文案和文档。

## 开发注意事项

- 不要直接更新 `mb_user_wallet` 而不写流水。
- 金额入库前统一转分，接口展示再转元。
- 余额支付必须锁定订单和钱包。
- 后台扣减不能扣成负数。
- 充值套餐当前不是自动入账闭环，接入充值下单时需要新增充值订单、支付回调和幂等入账。

## 测试入口

| 测试 | 用途 |
|------|------|
| `backend/tests/Unit/Service/User/UserWalletServiceTest.php` | 后台余额调整金额解析和上限 |

建议执行：

```bash
composer --working-dir backend test -- --filter Wallet
```
