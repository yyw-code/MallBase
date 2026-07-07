-- ============================================
-- 分销模块数据库表结构
-- 表前缀：mb_
-- 设计要点：
--   1. 作为伪插件独立建表，不侵入用户、订单主表结构
--   2. 佣金金额统一用分（int）存储，避免浮点误差
--   3. 基础配置归档在系统设置 DistributionConfig，分销业务表只保存业务数据
--   4. 订单支付生成佣金快照，订单完成后按配置进入结算
--   5. 售后退款按订单项实付金额比例扣回佣金
-- ============================================

DROP TABLE IF EXISTS `mb_distribution_level`;
CREATE TABLE `mb_distribution_level` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL COMMENT '分销员等级名称',
  `first_rate` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '一级佣金比例',
  `second_rate` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '二级佣金比例',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '状态（0禁用 1启用）',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分销员等级表';

INSERT INTO `mb_distribution_level` (`id`, `name`, `first_rate`, `second_rate`, `sort`, `status`, `remark`) VALUES
(1, '默认分销员', 5.00, 0.00, 10, 1, '系统默认分销员等级')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `first_rate` = VALUES(`first_rate`),
  `second_rate` = VALUES(`second_rate`),
  `sort` = VALUES(`sort`),
  `status` = VALUES(`status`),
  `remark` = VALUES(`remark`);

DROP TABLE IF EXISTS `mb_distribution_distributor`;
CREATE TABLE `mb_distribution_distributor` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `level_id` int(11) unsigned NOT NULL DEFAULT 1 COMMENT '分销员等级ID',
  `invite_code` varchar(32) NOT NULL COMMENT '邀请码',
  `status` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '状态（0禁用 1启用）',
  `available_commission_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '可提现佣金（分）',
  `frozen_commission_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '冻结佣金（分）',
  `pending_withdraw_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '提现中金额（分）',
  `withdrawn_commission_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '已提现佣金（分）',
  `debt_commission_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '待扣回佣金（分）',
  `total_commission_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '累计净佣金（分）',
  `direct_user_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '一级团队人数',
  `indirect_user_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '二级团队人数',
  `order_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '产生佣金订单数',
  `open_source` varchar(32) NOT NULL DEFAULT 'admin' COMMENT '开通来源（admin/apply/everyone/amount）',
  `opened_by` int(11) unsigned DEFAULT NULL COMMENT '开通管理员ID',
  `opened_at` datetime DEFAULT NULL COMMENT '开通时间',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  UNIQUE KEY `uk_invite_code` (`invite_code`),
  KEY `idx_status_level` (`status`, `level_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分销员账户表';

DROP TABLE IF EXISTS `mb_distribution_relation`;
CREATE TABLE `mb_distribution_relation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '绑定用户ID',
  `parent_user_id` int(11) unsigned NOT NULL COMMENT '一级上级用户ID',
  `grandparent_user_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '二级上级用户ID',
  `invite_code` varchar(32) NOT NULL DEFAULT '' COMMENT '绑定时使用的邀请码',
  `source` varchar(32) NOT NULL DEFAULT 'manual' COMMENT '来源',
  `expire_time` datetime DEFAULT NULL COMMENT '关系有效期，NULL为永久有效',
  `attribution_scene` varchar(32) NOT NULL DEFAULT '' COMMENT '归因场景（share_link/poster/manual）',
  `attribution_page` varchar(128) NOT NULL DEFAULT '' COMMENT '归因页面路径',
  `attribution_target_type` varchar(32) NOT NULL DEFAULT '' COMMENT '归因对象类型',
  `attribution_target_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '归因对象ID',
  `invite_reward_status` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '邀请奖励状态（0未发放 1已发放）',
  `invite_reward_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '邀请奖励金额（分）',
  `invite_reward_at` datetime DEFAULT NULL COMMENT '邀请奖励发放时间',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  KEY `idx_parent_user_id` (`parent_user_id`),
  KEY `idx_grandparent_user_id` (`grandparent_user_id`),
  KEY `idx_expire_time` (`expire_time`),
  KEY `idx_attribution_target` (`attribution_target_type`, `attribution_target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分销邀请关系表';

DROP TABLE IF EXISTS `mb_distribution_commission_rule`;
CREATE TABLE `mb_distribution_commission_rule` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `target_type` varchar(16) NOT NULL COMMENT '规则对象：category/goods/sku',
  `target_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '对象ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '规则名称',
  `commission_type` varchar(16) NOT NULL DEFAULT 'rate' COMMENT '计佣方式（rate比例 fixed固定金额）',
  `first_rate` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '一级佣金比例',
  `second_rate` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '二级佣金比例',
  `first_fixed_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '一级固定佣金（分）',
  `second_fixed_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '二级固定佣金（分）',
  `status` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '状态（0禁用 1启用）',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_target` (`target_type`, `target_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分销佣金规则表';

DROP TABLE IF EXISTS `mb_distribution_order_commission`;
CREATE TABLE `mb_distribution_order_commission` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) unsigned NOT NULL COMMENT '订单ID',
  `order_sn` varchar(32) NOT NULL COMMENT '订单号',
  `order_item_id` int(11) unsigned NOT NULL COMMENT '订单项ID',
  `buyer_user_id` int(11) unsigned NOT NULL COMMENT '买家用户ID',
  `distributor_user_id` int(11) unsigned NOT NULL COMMENT '分销员用户ID',
  `relation_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '邀请关系ID快照',
  `relation_level` tinyint(1) unsigned NOT NULL COMMENT '关系层级（1一级 2二级）',
  `goods_id` int(11) unsigned NOT NULL COMMENT '商品ID快照',
  `sku_id` int(11) unsigned NOT NULL COMMENT 'SKU ID快照',
  `base_amount_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '佣金计算基数（分）',
  `rate` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '佣金比例快照',
  `amount_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '佣金金额（分）',
  `recovered_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '已扣回金额（分）',
  `rule_type` varchar(16) NOT NULL DEFAULT 'global' COMMENT '命中规则类型',
  `rule_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '命中规则ID',
  `attribution_scene` varchar(32) NOT NULL DEFAULT '' COMMENT '归因场景快照',
  `attribution_target_type` varchar(32) NOT NULL DEFAULT '' COMMENT '归因对象类型快照',
  `attribution_target_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '归因对象ID快照',
  `status` tinyint(2) unsigned NOT NULL DEFAULT 10 COMMENT '状态（10冻结 20待结算 30已结算 80已扣回 90已取消）',
  `release_time` datetime DEFAULT NULL COMMENT '可结算时间',
  `settled_at` datetime DEFAULT NULL COMMENT '实际结算时间',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_distributor_level` (`order_item_id`, `distributor_user_id`, `relation_level`),
  KEY `idx_order_sn` (`order_sn`),
  KEY `idx_distributor_status` (`distributor_user_id`, `status`),
  KEY `idx_release_status` (`status`, `release_time`),
  KEY `idx_attribution_target` (`attribution_target_type`, `attribution_target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分销订单佣金快照表';

DROP TABLE IF EXISTS `mb_distribution_apply`;
CREATE TABLE `mb_distribution_apply` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '申请用户ID',
  `real_name` varchar(60) NOT NULL DEFAULT '' COMMENT '申请人姓名',
  `mobile` varchar(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `reason` varchar(500) NOT NULL DEFAULT '' COMMENT '申请说明',
  `proof_image` varchar(255) NOT NULL DEFAULT '' COMMENT '申请凭证图片',
  `status` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '状态（0待审核 10已通过 20已驳回 30已撤回）',
  `review_admin_id` int(11) unsigned DEFAULT NULL COMMENT '审核管理员ID',
  `review_remark` varchar(255) NOT NULL DEFAULT '' COMMENT '审核备注',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_status_time` (`status`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分销员申请表';

DROP TABLE IF EXISTS `mb_distribution_commission_log`;
CREATE TABLE `mb_distribution_commission_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '分销员用户ID',
  `commission_id` bigint(20) unsigned DEFAULT NULL COMMENT '订单佣金ID',
  `withdraw_id` bigint(20) unsigned DEFAULT NULL COMMENT '提现单ID',
  `biz_type` varchar(32) NOT NULL COMMENT '业务类型',
  `biz_id` varchar(64) NOT NULL COMMENT '业务ID',
  `account_type` varchar(24) NOT NULL COMMENT '账户类型：frozen/available/pending/debt/withdrawn',
  `direction` varchar(16) NOT NULL COMMENT '方向：income/expense',
  `change_cents` int(10) unsigned NOT NULL COMMENT '变动金额（分）',
  `before_cents` int(10) unsigned NOT NULL COMMENT '变动前金额（分）',
  `after_cents` int(10) unsigned NOT NULL COMMENT '变动后金额（分）',
  `operator_type` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '操作者类型（0系统 1买家 2管理员）',
  `operator_id` int(11) unsigned DEFAULT NULL COMMENT '操作者ID',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `create_time`),
  KEY `idx_biz` (`biz_type`, `biz_id`),
  KEY `idx_commission_id` (`commission_id`),
  KEY `idx_withdraw_id` (`withdraw_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分销佣金流水表';

DROP TABLE IF EXISTS `mb_distribution_withdraw`;
CREATE TABLE `mb_distribution_withdraw` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sn` varchar(32) NOT NULL COMMENT '提现单号',
  `user_id` int(11) unsigned NOT NULL COMMENT '分销员用户ID',
  `amount_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '提现金额（分）',
  `account_type` varchar(32) NOT NULL DEFAULT 'offline' COMMENT '收款方式',
  `account_name` varchar(80) NOT NULL DEFAULT '' COMMENT '收款人姓名',
  `account_no` varchar(120) NOT NULL DEFAULT '' COMMENT '收款账号',
  `status` tinyint(2) unsigned NOT NULL DEFAULT 0 COMMENT '状态（0待审核 10已通过 20已驳回）',
  `admin_id` int(11) unsigned DEFAULT NULL COMMENT '审核管理员ID',
  `admin_remark` varchar(255) DEFAULT NULL COMMENT '审核备注',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_status_time` (`status`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分销提现申请表';

INSERT INTO `mb_client_page_category`
(`id`, `name`, `description`, `sort`, `is_system`, `status`)
VALUES
(10, '分销页面', '分销中心、佣金明细、团队、提现等页面', 85, 1, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `sort` = VALUES(`sort`),
  `is_system` = VALUES(`is_system`),
  `status` = VALUES(`status`);

INSERT INTO `mb_client_page`
(`name`, `path`, `page_type`, `category_id`, `package_root`, `need_login`, `source`, `remark`, `sort`, `status`)
VALUES
('分销中心', '/pages-sub/distribution/index', 'subpackage', 10, 'pages-sub/distribution', 1, 'system', '系统内置分销页面', 1640, 1),
('佣金明细', '/pages-sub/distribution/records', 'subpackage', 10, 'pages-sub/distribution', 1, 'system', '系统内置分销页面', 1650, 1),
('我的团队', '/pages-sub/distribution/team', 'subpackage', 10, 'pages-sub/distribution', 1, 'system', '系统内置分销页面', 1660, 1),
('佣金提现', '/pages-sub/distribution/withdraw', 'subpackage', 10, 'pages-sub/distribution', 1, 'system', '系统内置分销页面', 1670, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `page_type` = VALUES(`page_type`),
  `category_id` = VALUES(`category_id`),
  `package_root` = VALUES(`package_root`),
  `need_login` = VALUES(`need_login`),
  `source` = VALUES(`source`),
  `remark` = VALUES(`remark`),
  `sort` = VALUES(`sort`),
  `status` = VALUES(`status`);
