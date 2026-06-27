-- ============================================
-- 素材管理数据库表结构
-- 表前缀：mb_
-- ============================================

-- -----------------------------
-- 素材分类表
-- -----------------------------
DROP TABLE IF EXISTS `mb_upload_asset_category`;
CREATE TABLE `mb_upload_asset_category` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '分类ID',
  `pid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '父级分类ID',
  `name` varchar(50) NOT NULL COMMENT '分类名称',
  `code` varchar(50) NOT NULL COMMENT '分类编码',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT '系统分类：0否 1是',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0禁用 1启用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_pid` (`pid`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='素材分类表';

-- -----------------------------
-- 逻辑素材表
-- -----------------------------
DROP TABLE IF EXISTS `mb_upload_asset`;
CREATE TABLE `mb_upload_asset` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '素材ID',
  `category_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '素材分类ID',
  `type` varchar(20) NOT NULL DEFAULT 'file' COMMENT '素材类型：image/video/file',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '素材名称',
  `original_name` varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `mime` varchar(120) NOT NULL DEFAULT '' COMMENT 'MIME 类型',
  `ext` varchar(20) NOT NULL DEFAULT '' COMMENT '扩展名',
  `size` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
  `hash` char(64) NOT NULL DEFAULT '' COMMENT '文件 SHA256',
  `width` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '图片宽度',
  `height` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '图片高度',
  `module` varchar(50) NOT NULL DEFAULT '' COMMENT '上传模块',
  `uploader_type` varchar(20) NOT NULL DEFAULT 'admin' COMMENT '上传者类型：admin/user/system',
  `uploader_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '上传者ID',
  `visibility` varchar(20) NOT NULL DEFAULT 'public' COMMENT '可见性：public/private',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0回收站 1正常',
  `meta` json DEFAULT NULL COMMENT '扩展元信息',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_category_status` (`category_id`, `status`),
  KEY `idx_module_status` (`module`, `status`),
  KEY `idx_uploader` (`uploader_type`, `uploader_id`),
  KEY `idx_hash` (`hash`),
  KEY `idx_status_delete_time` (`status`, `delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='逻辑素材表';

-- -----------------------------
-- 素材存储位置表
-- -----------------------------
DROP TABLE IF EXISTS `mb_upload_asset_location`;
CREATE TABLE `mb_upload_asset_location` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '位置ID',
  `asset_id` bigint(20) unsigned NOT NULL COMMENT '素材ID',
  `driver` varchar(20) NOT NULL COMMENT '存储驱动：local/oss/cos/static/remote',
  `path` varchar(500) NOT NULL DEFAULT '' COMMENT '对象路径',
  `url_prefix` varchar(500) NOT NULL DEFAULT '' COMMENT '访问前缀',
  `bucket` varchar(120) NOT NULL DEFAULT '' COMMENT 'Bucket',
  `region` varchar(80) NOT NULL DEFAULT '' COMMENT '区域',
  `endpoint` varchar(180) NOT NULL DEFAULT '' COMMENT 'Endpoint',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否主位置',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0不可用 1可用',
  `etag` varchar(120) NOT NULL DEFAULT '' COMMENT 'ETag',
  `size` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
  `meta` json DEFAULT NULL COMMENT '扩展元信息',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_asset_primary` (`asset_id`, `is_primary`, `status`),
  KEY `idx_driver_status` (`driver`, `status`),
  UNIQUE KEY `uk_asset_driver_path` (`asset_id`, `driver`, `path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='素材存储位置表';

-- -----------------------------
-- 素材引用表
-- -----------------------------
DROP TABLE IF EXISTS `mb_upload_asset_usage`;
CREATE TABLE `mb_upload_asset_usage` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '引用ID',
  `asset_id` bigint(20) unsigned NOT NULL COMMENT '素材ID',
  `owner_type` varchar(50) NOT NULL COMMENT '引用方类型',
  `owner_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '引用方ID',
  `field` varchar(80) NOT NULL DEFAULT '' COMMENT '引用字段',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_asset_owner` (`asset_id`, `owner_type`, `owner_id`),
  KEY `idx_owner_field` (`owner_type`, `owner_id`, `field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='素材引用表';

-- -----------------------------
-- 素材迁移任务表
-- -----------------------------
DROP TABLE IF EXISTS `mb_upload_asset_migration`;
CREATE TABLE `mb_upload_asset_migration` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '迁移任务ID',
  `name` varchar(120) NOT NULL DEFAULT '' COMMENT '任务名称',
  `source_driver` varchar(20) NOT NULL DEFAULT '' COMMENT '源驱动',
  `target_driver` varchar(20) NOT NULL DEFAULT '' COMMENT '目标驱动',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '状态：0待处理 1处理中 2完成 3失败 4已取消',
  `total` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '总数',
  `success_count` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '成功数',
  `fail_count` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '失败数',
  `last_error` varchar(1000) NOT NULL DEFAULT '' COMMENT '最近错误',
  `options` json DEFAULT NULL COMMENT '任务参数',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_source_target` (`source_driver`, `target_driver`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='素材迁移任务表';

-- -----------------------------
-- 素材迁移明细日志表
-- -----------------------------
DROP TABLE IF EXISTS `mb_upload_asset_migration_log`;
CREATE TABLE `mb_upload_asset_migration_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `migration_id` bigint(20) unsigned NOT NULL COMMENT '迁移任务ID',
  `asset_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '素材ID',
  `source_driver` varchar(20) NOT NULL DEFAULT '' COMMENT '源驱动',
  `target_driver` varchar(20) NOT NULL DEFAULT '' COMMENT '目标驱动',
  `source_path` varchar(500) NOT NULL DEFAULT '' COMMENT '源对象路径',
  `target_path` varchar(500) NOT NULL DEFAULT '' COMMENT '目标对象路径',
  `stage` varchar(40) NOT NULL DEFAULT '' COMMENT '当前阶段',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '状态：0处理中 1成功 2失败',
  `delete_source` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否要求删除源文件',
  `source_deleted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '源文件是否已删除',
  `message` varchar(500) NOT NULL DEFAULT '' COMMENT '说明',
  `error_message` varchar(1000) NOT NULL DEFAULT '' COMMENT '错误信息',
  `duration_ms` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '耗时毫秒',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_migration_status` (`migration_id`, `status`),
  KEY `idx_asset` (`asset_id`),
  KEY `idx_source_target` (`source_driver`, `target_driver`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='素材迁移明细日志表';

-- -----------------------------
-- 预置素材分类
-- -----------------------------
INSERT INTO `mb_upload_asset_category` (`id`, `pid`, `name`, `code`, `sort`, `is_system`, `status`) VALUES
(1, 0, '商品素材', 'goods', 10, 1, 1),
(2, 0, '富文本素材', 'rich_text', 20, 1, 1),
(3, 0, '评价图片', 'review', 30, 1, 1),
(4, 0, '用户头像', 'avatar', 40, 1, 1),
(5, 0, '系统设置', 'setting', 50, 1, 1),
(6, 0, '其他', 'other', 100, 1, 1),
(7, 0, '文章素材', 'article', 60, 1, 1);
