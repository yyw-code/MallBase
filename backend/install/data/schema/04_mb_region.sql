-- ============================================
-- 中国省市区街道地区库
-- 表前缀：mb_
-- ============================================

DROP TABLE IF EXISTS `mb_region`;
CREATE TABLE `mb_region` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '地区ID',
  `parent_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '父级ID',
  `code` varchar(20) NOT NULL COMMENT '地区编码',
  `name` varchar(100) NOT NULL COMMENT '地区名称',
  `level` tinyint(1) NOT NULL COMMENT '层级：1省 2市 3区县 4街道',
  `path_codes` varchar(255) NOT NULL DEFAULT '' COMMENT '编码路径，逗号分隔',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0停用 1启用',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_level` (`level`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='中国省市区街道地区表';
