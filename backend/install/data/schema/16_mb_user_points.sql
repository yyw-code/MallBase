-- ============================================
-- 用户积分模块数据库表结构
-- 表前缀：mb_
-- 包含：用户积分账户、积分流水、积分规则
-- 设计要点：
--   1. 积分统一使用整数存储，不允许小数
--   2. mb_user_points 存可用、冻结与欠账聚合，所有变动写 mb_user_points_log
--   3. 订单完成先冻结，售后结束前不释放；释放/回收/抵扣均通过业务单号保证幂等
-- ============================================

DROP TABLE IF EXISTS `mb_user_points`;
CREATE TABLE `mb_user_points` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户 ID（关联 mb_user.id）',
  `balance_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '可用积分',
  `frozen_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '冻结积分',
  `debt_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '欠账积分（退款回收时可用积分不足形成）',
  `total_income_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '累计获得积分',
  `total_expense_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '累计扣减积分',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户积分账户表';

DROP TABLE IF EXISTS `mb_user_points_log`;
CREATE TABLE `mb_user_points_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户 ID（关联 mb_user.id）',
  `points_id` bigint(20) unsigned NOT NULL COMMENT '积分账户 ID（关联 mb_user_points.id）',
  `biz_type` varchar(32) NOT NULL COMMENT '业务类型（order_complete/refund/admin_adjust）',
  `biz_id` varchar(64) NOT NULL COMMENT '业务单号或主键',
  `direction` varchar(16) NOT NULL COMMENT '方向（income/expense）',
  `account_type` varchar(16) NOT NULL DEFAULT 'balance' COMMENT '账户类型（balance可用 frozen冻结 debt欠账）',
  `change_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '变动积分（始终为非负整数）',
  `before_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '变动前积分',
  `after_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '变动后积分',
  `operator_type` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '操作者类型（0系统 1买家 2管理员）',
  `operator_id` int(11) unsigned DEFAULT NULL COMMENT '操作者 ID',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_biz_direction_account` (`biz_type`, `biz_id`, `direction`, `account_type`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_user_account_type` (`user_id`, `account_type`),
  KEY `idx_points_id` (`points_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户积分流水表';

DROP TABLE IF EXISTS `mb_order_points_reward`;
CREATE TABLE `mb_order_points_reward` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) unsigned NOT NULL COMMENT '订单 ID',
  `order_sn` varchar(32) NOT NULL COMMENT '订单号',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户 ID',
  `reward_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '应赠送积分',
  `frozen_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '当前冻结积分',
  `released_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '已释放积分',
  `recovered_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '已回收积分',
  `debt_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '退款回收形成欠账积分',
  `release_time` datetime NOT NULL COMMENT '可释放时间',
  `released_at` datetime DEFAULT NULL COMMENT '释放时间',
  `status` varchar(16) NOT NULL DEFAULT 'frozen' COMMENT '状态（frozen/released/recovered）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_release_time` (`status`, `release_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单积分赠送快照表';

DROP TABLE IF EXISTS `mb_order_points_reward_item`;
CREATE TABLE `mb_order_points_reward_item` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reward_id` bigint(20) unsigned NOT NULL COMMENT '订单积分赠送记录 ID',
  `order_id` int(11) unsigned NOT NULL COMMENT '订单 ID',
  `order_item_id` int(11) unsigned NOT NULL COMMENT '订单项 ID',
  `goods_id` int(11) unsigned NOT NULL COMMENT '商品 ID',
  `sku_id` int(11) unsigned NOT NULL COMMENT 'SKU ID',
  `pay_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '订单项实付金额快照',
  `quantity` int(11) unsigned NOT NULL DEFAULT 1 COMMENT '购买数量快照',
  `reward_mode` varchar(16) NOT NULL DEFAULT 'global' COMMENT '命中规则模式（global/disabled/ratio/fixed）',
  `reward_ratio` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '每消费 1 元赠送积分',
  `reward_fixed` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '每件固定赠送积分',
  `reward_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '该订单项赠送积分',
  `recovered_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '该订单项已回收积分',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_item_id` (`order_item_id`),
  KEY `idx_reward_id` (`reward_id`),
  KEY `idx_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单项积分赠送快照表';

DROP TABLE IF EXISTS `mb_order_points_deduction`;
CREATE TABLE `mb_order_points_deduction` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) unsigned NOT NULL COMMENT '订单 ID',
  `order_sn` varchar(32) NOT NULL COMMENT '订单号',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户 ID',
  `used_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '下单抵扣使用积分',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '积分抵扣金额',
  `returned_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '已返还积分',
  `status` varchar(16) NOT NULL DEFAULT 'used' COMMENT '状态（used/returned/partial）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单积分抵扣记录表';

DROP TABLE IF EXISTS `mb_points_rule`;
CREATE TABLE `mb_points_rule` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `scene` varchar(32) NOT NULL COMMENT '规则场景（order_complete/register/review）',
  `name` varchar(50) NOT NULL COMMENT '规则名称',
  `description` varchar(255) DEFAULT NULL COMMENT '规则说明',
  `points_per_yuan` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '每消费 1 元奖励积分，仅订单场景使用',
  `fixed_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '固定奖励积分，注册/评价等场景使用',
  `max_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '单次最大奖励积分，0 表示不限制',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '状态（0禁用 1启用）',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scene` (`scene`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分规则表';

INSERT INTO `mb_points_rule`
(`id`, `scene`, `name`, `description`, `points_per_yuan`, `fixed_points`, `max_points`, `sort`, `status`, `remark`)
VALUES
(1, 'order_complete', '消费返积分', '订单完成后按实付金额发放积分', 1, 0, 0, 10, 1, '默认每消费 1 元返 1 积分')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `points_per_yuan` = VALUES(`points_per_yuan`),
  `fixed_points` = VALUES(`fixed_points`),
  `max_points` = VALUES(`max_points`),
  `sort` = VALUES(`sort`),
  `status` = VALUES(`status`),
  `remark` = VALUES(`remark`);
