-- ============================================
-- 积分商城 / 积分兑换数据库表结构
-- 表前缀：mb_
-- 设计要点：
--   1. 积分商品独立于普通商品订单，只引用现有商品 SKU 作为兑换标的
--   2. 兑换单保存商品、SKU、收货信息快照，避免后续商品变更影响历史记录
--   3. 兑换扣减积分写 mb_user_points_log，关闭待发货兑换单时返还积分并回滚库存
-- ============================================

DROP TABLE IF EXISTS `mb_points_goods`;
CREATE TABLE `mb_points_goods` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `goods_id` int(11) unsigned NOT NULL COMMENT '商品 ID',
  `sku_id` int(11) unsigned NOT NULL COMMENT 'SKU ID',
  `points_price` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '兑换所需积分（单件）',
  `exchange_stock` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '兑换库存',
  `exchanged_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '累计兑换数量',
  `limit_per_user` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '每人限兑数量，0 不限制',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '状态（0禁用 1启用）',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sku_id` (`sku_id`),
  KEY `idx_goods_id` (`goods_id`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分兑换商品表';

DROP TABLE IF EXISTS `mb_points_exchange_order`;
CREATE TABLE `mb_points_exchange_order` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sn` varchar(32) NOT NULL COMMENT '兑换单号',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户 ID',
  `points_goods_id` int(11) unsigned NOT NULL COMMENT '积分商品 ID',
  `goods_id` int(11) unsigned NOT NULL COMMENT '商品 ID 快照',
  `sku_id` int(11) unsigned NOT NULL COMMENT 'SKU ID 快照',
  `goods_name` varchar(200) NOT NULL COMMENT '商品名称快照',
  `goods_image` varchar(255) DEFAULT NULL COMMENT '商品图片快照',
  `sku_spec` varchar(500) DEFAULT NULL COMMENT 'SKU 规格快照',
  `points_price` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '单件积分价快照',
  `quantity` int(10) unsigned NOT NULL DEFAULT 1 COMMENT '兑换数量',
  `total_points` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '总消耗积分',
  `address_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '收货地址 ID',
  `receiver_name` varchar(50) NOT NULL COMMENT '收货人',
  `receiver_phone` varchar(30) NOT NULL COMMENT '收货手机号',
  `receiver_province` varchar(50) NOT NULL COMMENT '省份',
  `receiver_city` varchar(50) NOT NULL COMMENT '城市',
  `receiver_district` varchar(50) NOT NULL COMMENT '区县',
  `receiver_address` varchar(255) NOT NULL COMMENT '详细地址',
  `status` tinyint(3) unsigned NOT NULL DEFAULT 10 COMMENT '状态（10待发货 20已发货 30已完成 90已关闭）',
  `logistics_company` varchar(80) DEFAULT NULL COMMENT '物流公司',
  `logistics_no` varchar(80) DEFAULT NULL COMMENT '物流单号',
  `buyer_remark` varchar(255) DEFAULT NULL COMMENT '买家备注',
  `admin_remark` varchar(255) DEFAULT NULL COMMENT '后台备注',
  `idempotency_key` varchar(64) DEFAULT NULL COMMENT '客户端幂等键',
  `shipped_at` datetime DEFAULT NULL COMMENT '发货时间',
  `completed_at` datetime DEFAULT NULL COMMENT '完成时间',
  `closed_at` datetime DEFAULT NULL COMMENT '关闭时间',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  UNIQUE KEY `uk_user_idempotency` (`user_id`, `idempotency_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_points_goods_id` (`points_goods_id`),
  KEY `idx_status_time` (`status`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分兑换单表';

DROP TABLE IF EXISTS `mb_points_exchange_order_log`;
CREATE TABLE `mb_points_exchange_order_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `exchange_order_id` bigint(20) unsigned NOT NULL COMMENT '兑换单 ID',
  `exchange_sn` varchar(32) NOT NULL COMMENT '兑换单号快照',
  `action` varchar(32) NOT NULL COMMENT '操作动作',
  `from_status` tinyint(3) unsigned DEFAULT NULL COMMENT '变更前状态',
  `to_status` tinyint(3) unsigned NOT NULL COMMENT '变更后状态',
  `operator_type` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '操作者类型（0系统 1买家 2管理员）',
  `operator_id` int(11) unsigned DEFAULT NULL COMMENT '操作者 ID',
  `remark` varchar(255) DEFAULT NULL COMMENT '操作备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`exchange_order_id`, `id`),
  KEY `idx_exchange_sn` (`exchange_sn`),
  KEY `idx_action_time` (`action`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分兑换单操作日志表';
