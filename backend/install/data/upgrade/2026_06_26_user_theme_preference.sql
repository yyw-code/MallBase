SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `mb_user_theme_preference` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '偏好ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `theme_mode` varchar(20) NOT NULL DEFAULT 'system' COMMENT '主题模式：system/light/dark/custom',
  `theme_id` int(11) unsigned DEFAULT NULL COMMENT '自定义主题ID，仅 theme_mode=custom 有效',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  KEY `idx_theme` (`theme_mode`, `theme_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户客户端主题偏好表';
