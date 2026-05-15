---
name: payment-notify-idempotency
description: MallBase 支付回调（notify）验签、幂等、防重放与金额校验规则；处理任意支付渠道（微信 / 支付宝 / 银联）回调链路时使用。
---

# ThinkPHP 规则：支付回调幂等与验签

## 适用范围

- `backend/app/service/client/payment/NotifyService.php`
- `backend/app/controller/client/order/PayNotifyController.php`
- 任何接收外部支付平台 webhook 的入口

跨渠道通用，新增支付宝 / 银联回调时同样适用。

## 强制规则

### 1. 路由层

- notify 入口必须放在**白名单路由**（无 JWT / 无登录态校验），位于 `backend/route/api/notify.php`
- 不同渠道**不复用**同一回调入口；按渠道独立路由（如 `/api/notify/wechat/pay`、`/api/notify/wechat/refund`），避免单点故障扩散
- nginx / OpenResty 必须保留原始请求头：`Wechatpay-Signature / Wechatpay-Serial / Wechatpay-Timestamp / Wechatpay-Nonce` 等签名相关头不能被滤掉

### 2. 验签前置（顺序敏感）

处理顺序**严格**按下面来，不能调换：

1. **验签**：用 SDK 内置（如 EasyWeChat `ServerRequest::handlePaid()`），禁止自己写签名验证
2. **解密**：V3 `resource.ciphertext` 用 `api_v3_key` AES-GCM 解密
3. **防重放**：Redis `setNX wxpay:nonce:{nonce} TTL=300`，已存在即拒
4. **金额校验**：`amount.total`（分）逐分比对 `mb_order.pay_amount * 100`，不等即拒并 dispatch 告警事件
5. **幂等落库**：写 `mb_payment_log`，唯一索引兜底
6. **业务转单**：调 `OrderService::confirmPaid()` 转 PAID

### 3. 幂等键设计

- `mb_payment_log` 必须建唯一索引：`uk_txn_event(transaction_id, event_type) WHERE transaction_id IS NOT NULL`
- 同一订单允许多条 `prepay` 记录（场景切换 / 超时重发），但只能有一条 `paid` 终态
- INSERT 失败（违反唯一键）即视为重复回调，**直接返回成功应答**，不抛异常

### 4. 金额校验（防篡改主战场）

- 用分（int）比对，不用元（float / string）
- 比对源：`amount.total`（回调报文）vs `mb_order.pay_amount`（库内订单）
- 不等：先 `Logger::critical` 落地完整报文 → dispatch `payment.amount_mismatch` 事件 → 返回 FAIL 应答给微信触发重试 → **不转单**

### 5. HTTP 应答

| 场景 | HTTP | Body |
|---|---|---|
| 处理成功 | 200 | `{"code":"SUCCESS","message":"成功"}` |
| 验签失败 | 401 | `{"code":"FAIL","message":"签名错误"}` |
| 金额不一致 | 500 | `{"code":"FAIL","message":"金额校验失败"}` |
| 业务异常 | 500 | `{"code":"FAIL","message":"..."}` |

- 验签失败必须返回 401 + V3 JSON 体，**纯 401 不带 body** 微信会一直重投
- 业务异常返回 5xx 让微信走指数退避重试（最多 15 次）

### 6. 事务边界

- 写 `mb_payment_log` + 转单状态可以同事务，但**任何外部调用禁止入事务**
- 短信通知、推送、积分、邮件等副作用走 ThinkPHP `event()` 派发，监听器入队列异步处理
- `Logger::critical` 可以同步（写文件不阻塞）

### 7. 防重放双保险

- DB 唯一索引：兜底 + 持久化
- Redis nonce：5min 短期窗口，应对高频回调打穿 DB

两者**都要有**，不能只靠其一。

### 8. 告警钩子

通过 `event()` 派发（在 `backend/app/event.php` 注册）：

- `payment.verify_failed` — 验签失败
- `payment.amount_mismatch` — 金额不一致
- `payment.replay_attack` — Redis nonce 命中
- 监听器 `PaymentAlertListener` 入队，调 `SmsProviderService` 通知运维

## 禁止项

- ❌ 在 notify 入口加 JWT / CSRF 中间件
- ❌ 自己用 openssl 实现验签
- ❌ 仅靠 DB 唯一索引做幂等（必须加 Redis nonce）
- ❌ 用元（float）比对金额
- ❌ 在事务内发短信 / 推送 / 调外部 API
- ❌ 验签失败返回 200（微信认为已处理，不会重试 → 资金漏单）
- ❌ 业务异常返回 200（同上）
- ❌ 把支付回调和退款回调合并到同一入口靠 event_type 分发

## 自检清单

- [ ] 路由在白名单，无鉴权中间件
- [ ] 验签调用 SDK 内置方法，未自实现
- [ ] Redis nonce 防重放已加，TTL 300s
- [ ] 金额用分（int）比对，源是 `mb_order.pay_amount`
- [ ] `mb_payment_log` 有 `(transaction_id, event_type)` 唯一索引
- [ ] 外部调用全部走 event 异步派发
- [ ] HTTP 应答严格匹配状态码与 JSON 体
- [ ] 支付与退款回调路由独立

## When to Use

在以下场景触发此模式：
- 编写或修改支付回调 Controller / Service
- 排查「订单未转 PAID」「重复扣款 / 重复发货」「金额对不上」类事故
- 新增支付宝 / 银联回调链路
- 接入退款回调

## Related

- `.codex/skills/thinkPHP/wechat-pay-stateless/SKILL.md` — 支付 SDK 无状态姊妹规则
- `.codex/skills/thinkPHP/validate-then-transact/SKILL.md` — 事务边界上位规则
- `backend/app/service/order/OrderStatusMachine.php` — `confirmPaid` 状态机入口
- `backend/app/event.php` — 告警事件注册点
