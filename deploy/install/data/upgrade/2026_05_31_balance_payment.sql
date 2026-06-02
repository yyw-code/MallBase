-- 余额支付开关与用户钱包表
-- 本文件为幂等升级 SQL，可在已部署环境重复执行。

CREATE TABLE IF NOT EXISTS `mb_user_wallet` (
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

CREATE TABLE IF NOT EXISTS `mb_user_wallet_log` (
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

SET @wallet_log_operator_type_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mb_user_wallet_log'
    AND COLUMN_NAME = 'operator_type'
);
SET @wallet_log_operator_type_sql := IF(
  @wallet_log_operator_type_exists = 0,
  'ALTER TABLE `mb_user_wallet_log` ADD COLUMN `operator_type` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT ''操作者类型（0系统 1买家 2管理员）'' AFTER `after_cents`',
  'SELECT 1'
);
PREPARE wallet_log_operator_type_stmt FROM @wallet_log_operator_type_sql;
EXECUTE wallet_log_operator_type_stmt;
DEALLOCATE PREPARE wallet_log_operator_type_stmt;

SET @wallet_log_operator_id_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mb_user_wallet_log'
    AND COLUMN_NAME = 'operator_id'
);
SET @wallet_log_operator_id_sql := IF(
  @wallet_log_operator_id_exists = 0,
  'ALTER TABLE `mb_user_wallet_log` ADD COLUMN `operator_id` int(11) unsigned DEFAULT NULL COMMENT ''操作者 ID'' AFTER `operator_type`',
  'SELECT 1'
);
PREPARE wallet_log_operator_id_stmt FROM @wallet_log_operator_id_sql;
EXECUTE wallet_log_operator_id_stmt;
DEALLOCATE PREPARE wallet_log_operator_id_stmt;

INSERT INTO `mb_setting`
  (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`)
VALUES
  (1041, '余额支付状态', 'payment_balance_enabled', '0', 'switch', NULL, NULL, NULL, '开启后客户端可选择余额支付，订单支付时会扣减用户余额。', 30)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `type` = VALUES(`type`),
  `remark` = VALUES(`remark`),
  `sort` = VALUES(`sort`);

DELETE FROM `mb_setting`
WHERE `group_id` = 1041
  AND `code` = 'payment_mock_enabled';

-- 余额记录/调整余额是用户列表内的按钮权限，不作为独立菜单展示。
UPDATE `mb_permission` AS wallet_button
INNER JOIN `mb_permission` AS user_menu
  ON user_menu.`code` = 'SystemClientUserList'
SET wallet_button.`parent_id` = user_menu.`id`,
    wallet_button.`type` = 2,
    wallet_button.`is_show` = 1
WHERE wallet_button.`code` IN ('SystemUserWalletLog', 'SystemUserWalletAdjust');

DELETE FROM `mb_permission`
WHERE `code` = 'SystemClientUserWallet';
