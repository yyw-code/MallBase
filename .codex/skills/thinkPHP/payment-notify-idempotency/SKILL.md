---
name: payment-notify-idempotency
description: MallBase 支付与退款回调安全规则；修改 backend/route/notify.php、PayNotifyController、NotifyService、WechatPaymentResultService、mb_payment_log，或处理微信/其它渠道 webhook 的验签、解密、防重放、金额校验、幂等、事务与 HTTP 应答时使用。
---

# 支付回调验签与幂等

## 当前入口

- 白名单路由：`backend/route/notify.php`
- 支付：`POST /api/notify/wechat/pay`
- 退款：`POST /api/notify/wechat/refund`
- Controller：`backend/app/controller/client/order/PayNotifyController.php`
- Service：`NotifyService`、`WechatPaymentResultService`

两个回调入口不挂 JWT/CSRF 中间件，支付与退款保持独立路由。网关必须透传 `Wechatpay-Signature`、`Wechatpay-Serial`、`Wechatpay-Timestamp`、`Wechatpay-Nonce` 和未经改写的原始请求体。

## 支付处理顺序

1. 用原始 headers/body 构造 PSR-7 Request。
2. 用 EasyWeChat Validator 验签，不自行实现微信签名算法。
3. 通过 SDK Server 解密 `resource`，业务只使用解密后的 attributes。
4. 用微信 nonce 做 300 秒短窗口防重放。
5. 校验 `mchid`、`out_trade_no`、`transaction_id`、`trade_state` 和整数分金额。
6. 以活动 PREPAY 流水的 `amount_cents` 对比回调 `amount.total`，不使用浮点元金额。
7. 在同一事务内追加 PAID 流水并通过订单 Service/状态机确认支付。
8. 成功或已幂等处理返回微信 V3 JSON 成功应答；验签、重放和处理异常按当前协议返回失败状态。

`WechatPaymentResultService::applyVerifiedSuccess()` 同时供回调和主动查单复用。不要在另一条路径复制金额、商户号或订单状态校验。

## 持久幂等

`mb_payment_log` 使用合法的 MySQL 联合唯一索引：

```sql
UNIQUE KEY `uk_txn_event` (`transaction_id`, `event_type`)
```

不要写 PostgreSQL 风格的 `... WHERE transaction_id IS NOT NULL` 部分唯一索引。MySQL 唯一索引允许多行 `NULL`，同一非空交易号与事件类型仍会被唯一约束拦截。

应用层先查 `(transaction_id, PAID)`，数据库唯一键处理并发竞争；命中重复键时按幂等结果处理，不重复推进业务。`out_trade_no` 的唯一约束继续保护预支付流水。

Redis nonce 是短期减压与重放告警层，数据库唯一键是持久防线。当前 Redis 异常采用可用性优先的放行策略；如要改为失败关闭，必须先评估支付回调可用性，不能顺手改变。

## 应答与副作用

- 验签失败或重放：返回带 V3 JSON body 的 401。
- 金额、商户、订单或落库处理失败：返回 5xx，让微信按协议重试。
- `trade_state` 非 `SUCCESS` 且已完成审计处理：按当前实现返回成功，不推进订单。
- 成功：返回 200 和 `{"code":"SUCCESS","message":"成功"}`。

事务内只做支付流水和订单状态的原子持久化，不调用短信、Webhook 等外部服务。当前支付告警监听器只落日志并预留运维通道；新增真实通知时放到事务外并做队列、限时和异常隔离。

## 自检

- [ ] 路由无登录鉴权，支付与退款入口分离。
- [ ] 验签、解密、重放、金额和幂等顺序未被打乱。
- [ ] 金额以整数分和 PREPAY 流水为基准。
- [ ] 唯一索引是目标 MySQL 可执行语法。
- [ ] 重试不会重复写 PAID 流水或重复推进订单。
- [ ] 外部通知不会进入数据库事务。
