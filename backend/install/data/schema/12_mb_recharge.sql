-- ============================================
-- 余额充值模块数据库表结构
-- 表前缀：mb_
-- ============================================

-- -----------------------------
-- 充值套餐表
-- -----------------------------
DROP TABLE IF EXISTS `mb_recharge_package`;
CREATE TABLE `mb_recharge_package` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '套餐ID',
  `name` varchar(50) NOT NULL COMMENT '套餐名称',
  `pay_amount_cents` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '支付金额（分）',
  `gift_amount_cents` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '赠送金额（分）',
  `balance_amount_cents` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '到账余额（分）',
  `background_image` varchar(255) DEFAULT NULL COMMENT '套餐背景图',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序（越小越靠前）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0=禁用 1=启用',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`, `sort`, `id`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值套餐表';
