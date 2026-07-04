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
(7, 0, '文章素材', 'article', 60, 1, 1),
(8, 0, '装修素材', 'client_decorate', 70, 1, 1);

-- -----------------------------
-- 系统默认装修素材
-- -----------------------------
INSERT INTO `mb_upload_asset`
  (`id`, `category_id`, `type`, `name`, `original_name`, `mime`, `ext`, `size`, `hash`, `width`, `height`, `module`, `uploader_type`, `uploader_id`, `visibility`, `status`, `meta`)
VALUES
  (1001, 8, 'image', 'decorate-banner-market.png', 'decorate-banner-market.png', 'image/png', 'png', 47442, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1002, 8, 'image', 'decorate-banner-member.png', 'decorate-banner-member.png', 'image/png', 'png', 42492, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1003, 8, 'image', 'decorate-banner-home.png', 'decorate-banner-home.png', 'image/png', 'png', 53517, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1004, 8, 'image', 'decorate-nav-digital.png', 'decorate-nav-digital.png', 'image/png', 'png', 8182, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1005, 8, 'image', 'decorate-nav-beauty.png', 'decorate-nav-beauty.png', 'image/png', 'png', 8549, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1006, 8, 'image', 'decorate-nav-fashion.png', 'decorate-nav-fashion.png', 'image/png', 'png', 9301, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1007, 8, 'image', 'decorate-nav-home.png', 'decorate-nav-home.png', 'image/png', 'png', 8027, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1008, 8, 'image', 'decorate-nav-food.png', 'decorate-nav-food.png', 'image/png', 'png', 8527, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1009, 8, 'image', 'decorate-nav-sport.png', 'decorate-nav-sport.png', 'image/png', 'png', 8678, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1010, 8, 'image', 'decorate-cube-new.png', 'decorate-cube-new.png', 'image/png', 'png', 24726, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1011, 8, 'image', 'decorate-cube-picks.png', 'decorate-cube-picks.png', 'image/png', 'png', 24816, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1012, 8, 'image', 'decorate-cube-member.png', 'decorate-cube-member.png', 'image/png', 'png', 27068, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1013, 8, 'image', 'decorate-cube-sale.png', 'decorate-cube-sale.png', 'image/png', 'png', 25609, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1014, 8, 'image', 'decorate-entry-category.png', 'decorate-entry-category.png', 'image/png', 'png', 48075, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1015, 8, 'image', 'profile-order-pay.svg', 'profile-order-pay.svg', 'image/svg+xml', 'svg', 468, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1016, 8, 'image', 'profile-order-ship.svg', 'profile-order-ship.svg', 'image/svg+xml', 'svg', 464, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1017, 8, 'image', 'profile-order-receive.svg', 'profile-order-receive.svg', 'image/svg+xml', 'svg', 519, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1018, 8, 'image', 'profile-order-refund.svg', 'profile-order-refund.svg', 'image/svg+xml', 'svg', 504, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1019, 8, 'image', 'profile-service-address.svg', 'profile-service-address.svg', 'image/svg+xml', 'svg', 341, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1020, 8, 'image', 'profile-service-settings.svg', 'profile-service-settings.svg', 'image/svg+xml', 'svg', 380, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1021, 8, 'image', 'profile-service-support.svg', 'profile-service-support.svg', 'image/svg+xml', 'svg', 545, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1022, 8, 'image', 'service.png', 'service.png', 'image/png', 'png', 1112, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1023, 8, 'image', 'cart.png', 'cart.png', 'image/png', 'png', 876, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1024, 8, 'image', 'home.png', 'home.png', 'image/png', 'png', 837, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1025, 8, 'image', 'collapse-left.png', 'collapse-left.png', 'image/png', 'png', 848, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}'),
  (1026, 8, 'image', 'collapse-right.png', 'collapse-right.png', 'image/png', 'png', 818, '', 0, 0, 'client_decorate', 'system', 0, 'public', 1, '{"source":"system","scope":"decorate"}');

INSERT INTO `mb_upload_asset_location`
  (`asset_id`, `driver`, `path`, `url_prefix`, `bucket`, `region`, `endpoint`, `is_primary`, `status`, `etag`, `size`, `meta`)
VALUES
  (1001, 'static', 'static/decorate/decorate-banner-market.png', '', '', '', '', 1, 1, '', 47442, '{"source":"system","scope":"decorate"}'),
  (1002, 'static', 'static/decorate/decorate-banner-member.png', '', '', '', '', 1, 1, '', 42492, '{"source":"system","scope":"decorate"}'),
  (1003, 'static', 'static/decorate/decorate-banner-home.png', '', '', '', '', 1, 1, '', 53517, '{"source":"system","scope":"decorate"}'),
  (1004, 'static', 'static/decorate/decorate-nav-digital.png', '', '', '', '', 1, 1, '', 8182, '{"source":"system","scope":"decorate"}'),
  (1005, 'static', 'static/decorate/decorate-nav-beauty.png', '', '', '', '', 1, 1, '', 8549, '{"source":"system","scope":"decorate"}'),
  (1006, 'static', 'static/decorate/decorate-nav-fashion.png', '', '', '', '', 1, 1, '', 9301, '{"source":"system","scope":"decorate"}'),
  (1007, 'static', 'static/decorate/decorate-nav-home.png', '', '', '', '', 1, 1, '', 8027, '{"source":"system","scope":"decorate"}'),
  (1008, 'static', 'static/decorate/decorate-nav-food.png', '', '', '', '', 1, 1, '', 8527, '{"source":"system","scope":"decorate"}'),
  (1009, 'static', 'static/decorate/decorate-nav-sport.png', '', '', '', '', 1, 1, '', 8678, '{"source":"system","scope":"decorate"}'),
  (1010, 'static', 'static/decorate/decorate-cube-new.png', '', '', '', '', 1, 1, '', 24726, '{"source":"system","scope":"decorate"}'),
  (1011, 'static', 'static/decorate/decorate-cube-picks.png', '', '', '', '', 1, 1, '', 24816, '{"source":"system","scope":"decorate"}'),
  (1012, 'static', 'static/decorate/decorate-cube-member.png', '', '', '', '', 1, 1, '', 27068, '{"source":"system","scope":"decorate"}'),
  (1013, 'static', 'static/decorate/decorate-cube-sale.png', '', '', '', '', 1, 1, '', 25609, '{"source":"system","scope":"decorate"}'),
  (1014, 'static', 'static/decorate/decorate-entry-category.png', '', '', '', '', 1, 1, '', 48075, '{"source":"system","scope":"decorate"}'),
  (1015, 'static', 'static/decorate/profile-order-pay.svg', '', '', '', '', 1, 1, '', 468, '{"source":"system","scope":"decorate"}'),
  (1016, 'static', 'static/decorate/profile-order-ship.svg', '', '', '', '', 1, 1, '', 464, '{"source":"system","scope":"decorate"}'),
  (1017, 'static', 'static/decorate/profile-order-receive.svg', '', '', '', '', 1, 1, '', 519, '{"source":"system","scope":"decorate"}'),
  (1018, 'static', 'static/decorate/profile-order-refund.svg', '', '', '', '', 1, 1, '', 504, '{"source":"system","scope":"decorate"}'),
  (1019, 'static', 'static/decorate/profile-service-address.svg', '', '', '', '', 1, 1, '', 341, '{"source":"system","scope":"decorate"}'),
  (1020, 'static', 'static/decorate/profile-service-settings.svg', '', '', '', '', 1, 1, '', 380, '{"source":"system","scope":"decorate"}'),
  (1021, 'static', 'static/decorate/profile-service-support.svg', '', '', '', '', 1, 1, '', 545, '{"source":"system","scope":"decorate"}'),
  (1022, 'static', 'static/decorate/floating/service.png', '', '', '', '', 1, 1, '', 1112, '{"source":"system","scope":"decorate"}'),
  (1023, 'static', 'static/decorate/floating/cart.png', '', '', '', '', 1, 1, '', 876, '{"source":"system","scope":"decorate"}'),
  (1024, 'static', 'static/decorate/floating/home.png', '', '', '', '', 1, 1, '', 837, '{"source":"system","scope":"decorate"}'),
  (1025, 'static', 'static/decorate/floating/collapse-left.png', '', '', '', '', 1, 1, '', 848, '{"source":"system","scope":"decorate"}'),
  (1026, 'static', 'static/decorate/floating/collapse-right.png', '', '', '', '', 1, 1, '', 818, '{"source":"system","scope":"decorate"}');
