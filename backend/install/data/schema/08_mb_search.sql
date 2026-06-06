-- -----------------------------
-- 客户端搜索日志表
-- -----------------------------
DROP TABLE IF EXISTS `mb_search_log`;
CREATE TABLE `mb_search_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `keyword` varchar(50) NOT NULL COMMENT '搜索关键词',
  `normalized_keyword` varchar(50) NOT NULL COMMENT '归一化关键词',
  `user_id` int(11) unsigned DEFAULT NULL COMMENT '用户ID',
  `platform` varchar(30) NOT NULL DEFAULT 'h5' COMMENT '来源平台',
  `ip_hash` char(64) NOT NULL COMMENT '匿名 IP 哈希',
  `search_count` int(11) unsigned NOT NULL DEFAULT 1 COMMENT '搜索次数',
  `last_search_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '最后搜索时间',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_normalized_keyword` (`normalized_keyword`),
  KEY `idx_last_search_time` (`last_search_time`),
  KEY `idx_user_platform` (`user_id`, `platform`),
  KEY `idx_ip_platform` (`ip_hash`, `platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户端搜索日志表';
