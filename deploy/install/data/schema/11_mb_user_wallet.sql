-- ============================================
-- 用户余额钱包模块数据库表结构
-- 表前缀：mb_
-- 包含：用户余额钱包、余额流水
-- 设计要点：
--   1. 钱包金额统一用分（int）存储，避免浮点误差
--   2. mb_user_wallet 只存当前聚合余额，所有变动写 mb_user_wallet_log
--   3. 支付、退款、后台调整等业务通过 biz_type + biz_id 关联
-- ============================================

DROP TABLE IF EXISTS `mb_user_wallet`;
CREATE TABLE `mb_user_wallet` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户 ID（关联 mb_user.id）',
  `balance_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '可用余额（分）',
  `frozen_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '冻结金额（分）',
  `total_recharge_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '累计充值金额（分）',
  `total_consume_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '累计消费金额（分）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户余额钱包表';

DROP TABLE IF EXISTS `mb_user_wallet_log`;
CREATE TABLE `mb_user_wallet_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户 ID（关联 mb_user.id）',
  `wallet_id` bigint(20) unsigned NOT NULL COMMENT '钱包 ID（关联 mb_user_wallet.id）',
  `biz_type` varchar(32) NOT NULL COMMENT '业务类型（order_pay/refund/recharge/admin_adjust）',
  `biz_id` varchar(64) NOT NULL COMMENT '业务单号或主键',
  `direction` varchar(16) NOT NULL COMMENT '方向（income/expense）',
  `change_cents` int(10) unsigned NOT NULL COMMENT '变动金额（分，始终为正数）',
  `before_cents` int(10) unsigned NOT NULL COMMENT '变动前余额（分）',
  `after_cents` int(10) unsigned NOT NULL COMMENT '变动后余额（分）',
  `operator_type` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '操作者类型（0系统 1买家 2管理员）',
  `operator_id` int(11) unsigned DEFAULT NULL COMMENT '操作者 ID',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_wallet_id` (`wallet_id`),
  KEY `idx_biz` (`biz_type`, `biz_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户余额流水表';
