-- ============================================
-- 文章内容模块数据库表结构
-- 表前缀：mb_
-- 包含：文章分类、文章、用户阅读记录
-- ============================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `mb_article_category`;
CREATE TABLE `mb_article_category` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '分类ID',
  `name` varchar(80) NOT NULL COMMENT '分类名称',
  `description` varchar(255) DEFAULT NULL COMMENT '分类描述',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0禁用，1启用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`, `sort`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章分类表';

DROP TABLE IF EXISTS `mb_article`;
CREATE TABLE `mb_article` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '文章ID',
  `category_id` int(11) unsigned NOT NULL COMMENT '分类ID',
  `title` varchar(160) NOT NULL COMMENT '文章标题',
  `cover` bigint(20) unsigned DEFAULT NULL COMMENT '封面素材ID',
  `description` varchar(500) DEFAULT NULL COMMENT '文章描述',
  `content` mediumtext COMMENT '文章内容（富文本）',
  `read_count` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '阅读量',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0禁用，1启用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_category_status` (`category_id`, `status`),
  KEY `idx_status_sort` (`status`, `sort`),
  KEY `idx_read_count` (`read_count`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章表';

DROP TABLE IF EXISTS `mb_article_read_record`;
CREATE TABLE `mb_article_read_record` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `article_id` int(11) unsigned NOT NULL COMMENT '文章ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID，0表示未登录用户',
  `read_count` int(11) unsigned NOT NULL DEFAULT 1 COMMENT '阅读次数',
  `first_read_time` datetime NOT NULL COMMENT '首次阅读时间',
  `last_read_time` datetime NOT NULL COMMENT '最近阅读时间',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_article_user` (`article_id`, `user_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_last_read_time` (`last_read_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章用户阅读记录表';
