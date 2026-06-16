-- ============================================
-- 物流模块数据库表结构
-- 表前缀：mb_
-- 包含：平台配置、平台物流公司目录、物流轨迹快照
-- ============================================

-- -----------------------------
-- 一、物流平台配置表
-- config 保存平台密钥等私有配置，列表接口不返回明文 key
-- -----------------------------
DROP TABLE IF EXISTS `mb_logistics_platform`;
CREATE TABLE `mb_logistics_platform` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '平台ID',
  `code` varchar(32) NOT NULL COMMENT '平台编码',
  `name` varchar(100) NOT NULL COMMENT '平台名称',
  `driver` varchar(32) NOT NULL COMMENT '物流驱动',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0禁用 1启用',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否默认平台',
  `cache_minutes` int(11) unsigned NOT NULL DEFAULT 30 COMMENT '查询缓存分钟',
  `config` json DEFAULT NULL COMMENT '平台配置',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status_default` (`status`, `is_default`, `sort`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='物流平台配置表';

INSERT INTO `mb_logistics_platform` (`code`, `name`, `driver`, `status`, `is_default`, `cache_minutes`, `config`, `sort`) VALUES
('kdniao', '快递鸟', 'kdniao', 1, 1, 30, '{"business_id":"","key":"","request_type":"8002"}', 10);

-- -----------------------------
-- 二、平台物流公司编码表
-- 同一家物流公司在不同平台是不同记录，不做跨平台统一承运商映射
-- -----------------------------
DROP TABLE IF EXISTS `mb_logistics_company`;
CREATE TABLE `mb_logistics_company` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '物流公司ID',
  `platform` varchar(32) NOT NULL COMMENT '平台编码',
  `code` varchar(64) NOT NULL COMMENT '平台内物流公司编码',
  `name` varchar(100) NOT NULL COMMENT '物流公司名称',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0禁用 1启用',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `raw_snapshot` json DEFAULT NULL COMMENT '平台原始目录项快照',
  `last_sync_at` datetime DEFAULT NULL COMMENT '最近同步时间',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_code` (`platform`, `code`),
  KEY `idx_platform_status_sort` (`platform`, `status`, `sort`, `id`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台物流公司编码表';

-- -----------------------------
-- 三、物流轨迹快照表
-- business_type/business_id 可复用于售后退货等其他业务域
-- -----------------------------
DROP TABLE IF EXISTS `mb_logistics_track`;
CREATE TABLE `mb_logistics_track` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '轨迹快照ID',
  `business_type` varchar(20) NOT NULL DEFAULT 'order' COMMENT '业务类型：order=订单',
  `business_id` int(11) unsigned NOT NULL COMMENT '业务ID',
  `order_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '订单ID冗余',
  `provider` varchar(32) NOT NULL DEFAULT 'kdniao' COMMENT '物流平台',
  `company_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '物流公司ID',
  `company_code` varchar(64) NOT NULL DEFAULT '' COMMENT '平台内物流公司编码',
  `company_name` varchar(100) NOT NULL DEFAULT '' COMMENT '物流公司名称',
  `tracking_no` varchar(64) NOT NULL DEFAULT '' COMMENT '运单号',
  `state` varchar(32) NOT NULL DEFAULT 'pending' COMMENT '平台状态码',
  `status_text` varchar(50) NOT NULL DEFAULT '待查询' COMMENT '状态文案',
  `is_signed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否签收',
  `latest_desc` varchar(255) DEFAULT NULL COMMENT '最新轨迹描述',
  `latest_time` datetime DEFAULT NULL COMMENT '最新轨迹时间',
  `tracks` json DEFAULT NULL COMMENT '标准化轨迹数组',
  `raw_snapshot` json DEFAULT NULL COMMENT '平台原始响应快照',
  `last_query_at` datetime DEFAULT NULL COMMENT '上次查询时间',
  `next_query_at` datetime DEFAULT NULL COMMENT '下次允许查询时间',
  `last_error` varchar(255) DEFAULT NULL COMMENT '最近一次查询错误',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_business` (`business_type`, `business_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_provider_company` (`provider`, `company_id`),
  KEY `idx_tracking_no` (`tracking_no`),
  KEY `idx_next_query_at` (`next_query_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='物流轨迹快照表';
