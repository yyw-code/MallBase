-- ============================================
-- 支付流水模块数据库表结构
-- 表前缀：mb_
-- 包含：支付流水主表（覆盖 prepay → notify → 关单全生命周期）
-- 设计要点：
--   1. 一笔订单可对应多条 prepay 记录（场景切换 / 超时重发），但只有一条 PAID 终态
--   2. 双唯一索引兜底幂等：
--      - out_trade_no 全局唯一，杜绝重复创建 prepay
--      - (transaction_id, event_type) 在 transaction_id 非空时唯一，杜绝回调重放落库
--   3. raw_notify 仅在验签 + 解密成功后写入明文，便于排障；不存签名前密文
--   4. amount_cents 用「分」存储，规避浮点误差，与 notify 报文 amount.total 等价
--   5. 退款单独建表（二期），本表只覆盖出账链路
-- ============================================

DROP TABLE IF EXISTS `mb_payment_log`;
CREATE TABLE `mb_payment_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单 ID（关联 mb_order.id）',
  `order_sn` varchar(32) NOT NULL COMMENT '订单号（冗余 mb_order.sn 便于检索）',
  `out_trade_no` varchar(32) NOT NULL COMMENT '商户单号（sn + 6 位随机后缀）',
  `transaction_id` varchar(64) DEFAULT NULL COMMENT '微信交易流水号（回调成功后填）',
  `pay_method` tinyint(1) unsigned NOT NULL COMMENT '支付方式（1微信 3余额）',
  `scene` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '支付场景（0无 1小程序 2公众号 3H5）',
  `event_type` varchar(16) NOT NULL COMMENT '事件类型（PREPAY/PAID/CLOSED/SUPERSEDED）',
  `trade_state` varchar(16) DEFAULT NULL COMMENT '微信侧交易状态（SUCCESS/REFUND/NOTPAY/CLOSED/USERPAYING/PAYERROR）',
  `amount_cents` int(10) unsigned NOT NULL COMMENT '金额（分），等价 notify 报文 amount.total',
  `prepay_id` varchar(64) DEFAULT NULL COMMENT 'JSAPI 预下单 ID',
  `mweb_url` varchar(512) DEFAULT NULL COMMENT 'MWEB 跳转地址（H5 渠道）',
  `payer_openid` varchar(64) DEFAULT NULL COMMENT '付款人 openid（mini/offi 必填，h5 为空）',
  `client_ip` varchar(45) DEFAULT NULL COMMENT '客户端 IP（IPv6 兼容，h5 必填）',
  `raw_notify` json DEFAULT NULL COMMENT '回调原始报文（解密后明文，便于排障）',
  `error_msg` varchar(255) DEFAULT NULL COMMENT '失败原因（验签 / 金额校验 / 重放命中等）',
  `expire_at` datetime DEFAULT NULL COMMENT 'prepay 过期时间（默认 2 小时）',
  `paid_at` datetime DEFAULT NULL COMMENT '支付完成时间（回调写入）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_out_trade_no` (`out_trade_no`),
  UNIQUE KEY `uk_txn_event` (`transaction_id`, `event_type`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_order_sn` (`order_sn`),
  KEY `idx_paid_at` (`paid_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付流水表（出账链路）';
