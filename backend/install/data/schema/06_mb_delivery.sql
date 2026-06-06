-- ============================================
-- 收货地址与运费模板
-- 表前缀：mb_
-- ============================================

DROP TABLE IF EXISTS `mb_user_address`;
CREATE TABLE `mb_user_address` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '地址ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `receiver_name` varchar(50) NOT NULL COMMENT '收货人',
  `receiver_mobile` varchar(20) NOT NULL COMMENT '联系电话',
  `province_id` bigint(20) unsigned NOT NULL COMMENT '省ID',
  `province_code` varchar(20) NOT NULL COMMENT '省编码',
  `province_name` varchar(50) NOT NULL COMMENT '省名称',
  `city_id` bigint(20) unsigned NOT NULL COMMENT '市ID',
  `city_code` varchar(20) NOT NULL COMMENT '市编码',
  `city_name` varchar(50) NOT NULL COMMENT '市名称',
  `district_id` bigint(20) unsigned NOT NULL COMMENT '区县ID',
  `district_code` varchar(20) NOT NULL COMMENT '区县编码',
  `district_name` varchar(50) NOT NULL COMMENT '区县名称',
  `street_id` bigint(20) unsigned NOT NULL COMMENT '街道ID',
  `street_code` varchar(20) NOT NULL COMMENT '街道编码',
  `street_name` varchar(100) NOT NULL COMMENT '街道名称',
  `region_path_text` varchar(255) NOT NULL COMMENT '区域路径快照',
  `address_detail` varchar(255) NOT NULL COMMENT '详细地址',
  `tag` varchar(20) DEFAULT NULL COMMENT '地址标签',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT '默认地址：0否 1是',
  `region_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '区域状态：0失效 1有效',
  `region_invalid_reason` varchar(255) DEFAULT NULL COMMENT '区域失效原因',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_street_id` (`street_id`),
  KEY `idx_is_default` (`is_default`),
  KEY `idx_region_status` (`region_status`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户收货地址表';

DROP TABLE IF EXISTS `mb_freight_template`;
CREATE TABLE `mb_freight_template` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '模板ID',
  `name` varchar(100) NOT NULL COMMENT '模板名称',
  `charge_type` varchar(20) NOT NULL DEFAULT 'piece' COMMENT '计费方式：piece按件 weight按重',
  `default_first_amount` decimal(10,2) NOT NULL DEFAULT 1.00 COMMENT '默认首件/首重',
  `default_first_fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '默认首费',
  `default_continue_amount` decimal(10,2) NOT NULL DEFAULT 1.00 COMMENT '默认续件/续重',
  `default_continue_fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '默认续费',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0禁用 1启用',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='运费模板表';

DROP TABLE IF EXISTS `mb_freight_template_rule`;
CREATE TABLE `mb_freight_template_rule` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '规则ID',
  `template_id` bigint(20) unsigned NOT NULL COMMENT '模板ID',
  `region_ids` json NOT NULL COMMENT '区域ID集合（可为省/市/区/街道任意层级ID）',
  `region_codes` json NOT NULL COMMENT '区域编码集合',
  `region_names` json NOT NULL COMMENT '区域名称集合',
  `region_path_texts` json NOT NULL COMMENT '区域路径快照集合',
  `match_level` tinyint(1) NOT NULL DEFAULT 4 COMMENT '规则最精确层级：1省 2市 3区 4街道（匹配优先级 4>3>2>1）',
  `first_amount` decimal(10,2) NOT NULL DEFAULT 1.00 COMMENT '首件/首重',
  `first_fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '首费',
  `continue_amount` decimal(10,2) NOT NULL DEFAULT 1.00 COMMENT '续件/续重',
  `continue_fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '续费',
  `region_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '区域状态：0失效 1有效',
  `region_invalid_reason` varchar(255) DEFAULT NULL COMMENT '区域失效原因',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_template_id` (`template_id`),
  KEY `idx_region_status` (`region_status`),
  KEY `idx_match_level` (`match_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='运费模板区域规则表';
