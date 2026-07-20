# 会员开发文档

本文面向二次开发和维护人员，说明会员模块的表、配置、服务、接口、前端入口、调用链、扩展点和测试入口。

## 数据表

| 表 | 用途 | 来源 |
|----|------|------|
| `mb_member_level` | 会员等级配置 | `backend/install/data/schema/17_mb_user_member.sql` |
| `mb_user_member` | 用户会员账户 | `backend/install/data/schema/17_mb_user_member.sql` |
| `mb_user_member_growth_log` | 用户成长值流水 | `backend/install/data/schema/17_mb_user_member.sql` |
| `mb_order_member_discount` | 订单会员优惠快照 | `backend/install/data/schema/17_mb_user_member.sql` |

数据库结构和初始数据以 `backend/install/data/schema/` 为唯一真相源。当前阶段不维护模块级增量 SQL 列表；需要同步数据库结构时，请使用当前版本重新部署，并按安装流程基于 schema 初始化数据库。

## 配置项

| 配置项 | 含义 | 来源 |
|--------|------|------|
| `member_enabled` | 会员总开关 | `backend/install/data/schema/03_mb_setting.sql` |
| `member_growth_points_per_yuan` | 每实付 1 元累计的成长值 | `backend/install/data/schema/03_mb_setting.sql` |

会员等级、会员价、成长值能力当前都由 `member_enabled` 控制。

## 后端入口

| 类型 | 文件 |
|------|------|
| 用户会员服务 | `backend/app/service/user/UserMemberService.php` |
| 后台等级服务 | `backend/app/service/admin/marketing/MemberLevelService.php` |
| 后台用户服务 | `backend/app/service/admin/user/UserService.php` |
| 前台用户服务 | `backend/app/service/client/UserService.php` |
| 订单服务 | `backend/app/service/client/order/OrderService.php` |
| 订单状态机 | `backend/app/service/order/OrderStatusMachine.php` |
| 商品详情服务 | `backend/app/service/client/goods/ClientGoodsService.php` |
| 会员等级控制器 | `backend/app/controller/admin/marketing/MemberLevelController.php` |
| 后台用户控制器 | `backend/app/controller/admin/user/UserController.php` |
| 会员等级模型 | `backend/app/model/user/MemberLevel.php` |
| 用户会员模型 | `backend/app/model/user/UserMember.php` |
| 成长值流水模型 | `backend/app/model/user/UserMemberGrowthLog.php` |
| 订单会员快照模型 | `backend/app/model/order/OrderMemberDiscount.php` |

## 后台 API

| 功能 | 方法和路径 | 控制器 |
|------|------------|--------|
| 会员等级列表 | `GET /admin/api/member/level/list` | `MemberLevelController::list()` |
| 会员等级详情 | `GET /admin/api/member/level/info/:id` | `MemberLevelController::info()` |
| 新增会员等级 | `POST /admin/api/member/level/create` | `MemberLevelController::create()` |
| 更新会员等级 | `PUT /admin/api/member/level/update/:id` | `MemberLevelController::update()` |
| 删除会员等级 | `DELETE /admin/api/member/level/delete/:id` | `MemberLevelController::delete()` |
| 更新等级状态 | `PUT /admin/api/member/level/updateStatus/:id` | `MemberLevelController::updateStatus()` |
| 可设置等级选项 | `GET /admin/api/user/memberLevels` | `UserController::memberLevelOptions()` |
| 设置用户会员等级 | `PUT /admin/api/user/member/:id` | `UserController::setMember()` |
| 用户详情 | `GET /admin/api/user/info/:id` | `UserController::info()` |

后台前端 API：

- `frontend/admin/apps/web-antd/src/api/member/level.ts`
- `frontend/admin/apps/web-antd/src/api/member/index.ts`
- `frontend/admin/apps/web-antd/src/api/user/index.ts`

后台页面：

- `frontend/admin/apps/web-antd/src/views/member/level/`
- `frontend/admin/apps/web-antd/src/views/user/index.vue`
- `frontend/admin/apps/web-antd/src/views/goods/goods/goods-edit.vue`

## 前台 API

会员前台摘要当前不走独立会员接口，而是在用户信息接口中返回。

| 功能 | 方法和路径 | 控制器 |
|------|------------|--------|
| 当前用户信息和会员摘要 | `GET /client/api/user/my/info` | `client.user.UserController::getMyInfo()` |
| 商品详情会员价和成长值预览 | `GET /client/api/goods/detail/:id` | 商品详情接口 |
| 确认订单会员优惠试算 | 订单确认接口 | 订单确认链路 |

前台 API 文件：

- `frontend/uniapp/api/user/user.js`

前台页面和组件：

- `frontend/uniapp/pages-sub/member/index.vue`
- `frontend/uniapp/pages/profile/index.vue`
- `frontend/uniapp/components/mb-member-card/`
- `frontend/uniapp/pages-sub/goods/detail.vue`
- `frontend/uniapp/pages-sub/order/confirm.vue`

## 核心调用链

### 订单会员优惠试算

```text
OrderController::preview/create
-> OrderService::calcAmounts()
-> UserMemberService::pricingQuote()
-> 按商品 member_benefit_mode 计算等级折扣或 SKU 会员价
-> 返回 member_discount 和 member_item_discounts
```

### 下单保存会员优惠快照

```text
OrderService::create()
-> 使用 calcAmounts() 结果
-> 会员优惠金额大于 0 时写入 mb_order_member_discount
```

### 订单完成累计成长值

```text
OrderStatusMachine
-> UserMemberService::rewardOrderCompleted()
-> growthForOrder()
-> mb_user_member.growth_value / total_growth_value
-> mb_user_member_growth_log(order_complete)
-> 自动匹配更高会员等级
```

### 前台会员摘要

```text
UserController::getMyInfo()
-> client\UserService::getMyInfo()
-> UserMemberService::clientSummary()
-> 返回 member.enabled / level / next_level / growth_to_next / discount_text
```

### 后台手动设置会员等级

```text
UserController::setMember()
-> admin\UserService::setMemberLevel()
-> UserMemberService::adminSetLevel()
-> mb_user_member
-> mb_user_member_growth_log(admin_adjust)
```

## 关键业务类型

成长值流水 `biz_type`：

| 常量 | 值 | 说明 |
|------|----|------|
| `BIZ_ORDER_COMPLETE` | `order_complete` | 订单完成累计成长值 |
| `BIZ_ADMIN_ADJUST` | `admin_adjust` | 后台设置会员等级 |

等级来源：

| 值 | 说明 |
|----|------|
| `auto` | 成长值自动匹配 |
| `manual` | 后台手动设置 |

商品会员权益模式：

| 值 | 说明 |
|----|------|
| `global` | 使用会员等级折扣 |
| `disabled` | 不参与会员权益 |
| `level_discount` | 使用会员等级折扣 |
| `sku_price` | 使用 SKU 会员价 |

## 新增会员权益开发步骤

1. 明确权益是否影响订单金额、商品展示、用户账户或仅展示。
2. 如果影响订单金额，优先扩展 `UserMemberService::pricingQuote()`。
3. 同步订单项折扣分摊和订单快照字段。
4. 同步商品编辑页、商品详情页和确认订单页展示。
5. 如果新增字段，同步 schema 真相源、模型字段、验证器和后台表单。
6. 补充测试，覆盖关闭会员、重复触发、手动锁定、历史快照不回算。
7. 更新 [../operation/member.md](../operation/member.md) 和 [../logic/member.md](../logic/member.md)。

## 开发注意事项

- 会员折扣计算以后端 `UserMemberService` 为准，前端只展示结果。
- 不要直接改 `mb_user_member` 绕过成长值流水。
- 订单完成成长值累计必须保持幂等。
- 历史订单使用快照，不因等级名称、折扣或会员价变更回算。
- 手动锁定等级时，成长值仍增加，但等级不被自动升级覆盖。

## 测试入口

| 测试 | 用途 |
|------|------|
| `backend/tests/Feature/Member/MemberServiceContractTest.php` | 会员折扣、成长值、自动升级、手动锁定契约 |
| `backend/tests/Unit/Service/User/UserMemberClientContractTest.php` | 前台会员摘要契约 |

建议执行：

```bash
composer --working-dir backend test -- --filter Member
```
