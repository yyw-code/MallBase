-- ============================================
-- 用户会员系统数据库表结构
-- 表前缀：mb_
-- 包含：会员等级、用户会员账户、成长值流水
-- ============================================

-- -----------------------------
-- 一、会员等级表
-- -----------------------------
DROP TABLE IF EXISTS `mb_member_level`;
CREATE TABLE `mb_member_level` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '会员等级ID',
  `name` varchar(50) NOT NULL COMMENT '等级名称',
  `growth_min` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '成长值门槛',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 100.00 COMMENT '等级折扣百分比（100不打折，95表示95折）',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态（0禁用 1启用）',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_growth` (`status`, `growth_min`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员等级表';

INSERT INTO `mb_member_level` (`id`, `name`, `growth_min`, `discount_percent`, `sort`, `status`, `remark`) VALUES
(1, '普通会员', 0, 100.00, 10, 1, '系统默认会员等级'),
(2, '银卡会员', 1000, 98.00, 20, 1, '系统默认会员等级'),
(3, '金卡会员', 5000, 95.00, 30, 1, '系统默认会员等级')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `growth_min` = VALUES(`growth_min`),
  `discount_percent` = VALUES(`discount_percent`),
  `sort` = VALUES(`sort`),
  `status` = VALUES(`status`),
  `remark` = VALUES(`remark`);

-- -----------------------------
-- 二、用户会员账户表
-- -----------------------------
DROP TABLE IF EXISTS `mb_user_member`;
CREATE TABLE `mb_user_member` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '会员账户ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `growth_value` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '当前成长值',
  `total_growth_value` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '累计获得成长值',
  `level_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '当前会员等级ID',
  `level_name` varchar(50) NOT NULL DEFAULT '' COMMENT '当前会员等级名称快照',
  `level_source` varchar(16) NOT NULL DEFAULT 'auto' COMMENT '等级来源（auto自动 manual手动）',
  `level_lock_until` datetime DEFAULT NULL COMMENT '手动等级锁定到期时间，空表示永久锁定',
  `level_remark` varchar(255) DEFAULT NULL COMMENT '后台设置会员等级原因',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  KEY `idx_level_id` (`level_id`),
  KEY `idx_level_source` (`level_source`, `level_lock_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户会员账户表';

-- -----------------------------
-- 三、用户成长值流水表
-- -----------------------------
DROP TABLE IF EXISTS `mb_user_member_growth_log`;
CREATE TABLE `mb_user_member_growth_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '流水ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `biz_type` varchar(50) NOT NULL COMMENT '业务类型',
  `biz_id` varchar(64) NOT NULL COMMENT '业务单号或业务ID',
  `direction` varchar(16) NOT NULL DEFAULT 'income' COMMENT '方向（income增加 expense减少）',
  `change_growth` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '变动成长值',
  `before_growth` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '变动前成长值',
  `after_growth` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '变动后成长值',
  `before_level_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '变动前等级ID',
  `after_level_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '变动后等级ID',
  `operator_type` tinyint(1) NOT NULL DEFAULT 0 COMMENT '操作者类型（0系统 1买家 2管理员）',
  `operator_id` int(11) unsigned DEFAULT NULL COMMENT '操作者ID',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_biz` (`biz_type`, `biz_id`),
  KEY `idx_user_created` (`user_id`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户会员成长值流水表';

-- -----------------------------
-- 四、订单会员优惠快照表
-- -----------------------------
DROP TABLE IF EXISTS `mb_order_member_discount`;
CREATE TABLE `mb_order_member_discount` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `order_id` int(11) unsigned NOT NULL COMMENT '订单ID',
  `order_sn` varchar(32) NOT NULL COMMENT '订单号',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `level_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '下单时会员等级ID',
  `level_name` varchar(50) NOT NULL DEFAULT '' COMMENT '下单时会员等级名称',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '会员优惠金额',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单会员优惠快照表';
