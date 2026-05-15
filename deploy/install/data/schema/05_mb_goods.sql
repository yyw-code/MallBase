-- ============================================
-- 商品管理系统数据库表结构
-- 表前缀：mb_
-- 包含：分类表、品牌表、规格表、规格模板表、商品表、SKU表、标签表、评论表及关联表
-- ============================================

-- -----------------------------
-- 一、商品分类表（两级分类，pid=0 为一级分类）
-- -----------------------------
DROP TABLE IF EXISTS `mb_goods_category`;
CREATE TABLE `mb_goods_category` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '分类ID',
  `pid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '父级分类ID（0=一级分类）',
  `name` varchar(50) NOT NULL COMMENT '分类名称',
  `icon` varchar(255) DEFAULT NULL COMMENT '分类图标',
  `image` varchar(255) DEFAULT NULL COMMENT '分类图片',
  `description` varchar(255) DEFAULT NULL COMMENT '分类描述',
  `sort` int(11) DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态（0禁用，1启用）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品分类表';

-- -----------------------------
-- 二、商品品牌表
-- -----------------------------
DROP TABLE IF EXISTS `mb_goods_brand`;
CREATE TABLE `mb_goods_brand` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '品牌ID',
  `name` varchar(100) NOT NULL COMMENT '品牌名称',
  `logo` varchar(255) DEFAULT NULL COMMENT '品牌LOGO',
  `description` varchar(500) DEFAULT NULL COMMENT '品牌描述',
  `sort` int(11) DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态（0禁用，1启用）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品品牌表';

-- -----------------------------
-- 三、商品规格组表（如：颜色、尺码、内存）
-- -----------------------------
DROP TABLE IF EXISTS `mb_goods_spec`;
CREATE TABLE `mb_goods_spec` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '规格ID',
  `name` varchar(50) NOT NULL COMMENT '规格名称（如：颜色、尺码）',
  `description` varchar(255) DEFAULT NULL COMMENT '规格描述',
  `sort` int(11) DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态（0禁用，1启用）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品规格组表';

-- -----------------------------
-- 四、商品规格值表（如：红色、XL、128G）
-- -----------------------------
DROP TABLE IF EXISTS `mb_goods_spec_value`;
CREATE TABLE `mb_goods_spec_value` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '规格值ID',
  `spec_id` int(11) unsigned NOT NULL COMMENT '所属规格ID',
  `value` varchar(100) NOT NULL COMMENT '规格值（如：红色、XL）',
  `sort` int(11) DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_spec_id` (`spec_id`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品规格值表';

-- -----------------------------
-- 五、商品规格模板表
-- -----------------------------
DROP TABLE IF EXISTS `mb_goods_spec_template`;
CREATE TABLE `mb_goods_spec_template` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '模板ID',
  `name` varchar(100) NOT NULL COMMENT '模板名称',
  `detail` json NOT NULL COMMENT '规格详情 JSON: [{name, add_pic, values:[{value, pic}]}]',
  `sort` int(11) DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态（0禁用，1启用）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品规格模板表';

-- -----------------------------
-- 六、商品主表（SPU）
-- -----------------------------
DROP TABLE IF EXISTS `mb_goods`;
CREATE TABLE `mb_goods` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '商品ID',
  `category_id` int(11) unsigned NOT NULL COMMENT '分类ID',
  `brand_id` int(11) unsigned DEFAULT NULL COMMENT '品牌ID',
  `name` varchar(200) NOT NULL COMMENT '商品名称',
  `subtitle` varchar(255) DEFAULT NULL COMMENT '商品副标题',
  `main_image` varchar(255) DEFAULT NULL COMMENT '主图URL',
  `main_video` varchar(255) DEFAULT NULL COMMENT '主视频URL',
  `images` json DEFAULT NULL COMMENT '商品轮播图 JSON',
  `spec_type` tinyint(1) NOT NULL DEFAULT 1 COMMENT '规格类型（1单规格，2多规格）',
  `spec_meta` json DEFAULT NULL COMMENT '规格设计器元数据 JSON',
  `description` text DEFAULT NULL COMMENT '商品详情（富文本）',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '最低价格（SKU最小价格）',
  `market_price` decimal(10,2) DEFAULT NULL COMMENT '市场价（划线价）',
  `stock` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '总库存（所有SKU库存之和）',
  `sales` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '销量',
  `unit` varchar(20) DEFAULT '件' COMMENT '计量单位',
  `is_on_sale` tinyint(1) DEFAULT 0 COMMENT '是否上架（0下架，1上架）',
  `is_recommend` tinyint(1) DEFAULT 0 COMMENT '是否推荐（0否，1是）',
  `is_new` tinyint(1) DEFAULT 0 COMMENT '是否新品（0否，1是）',
  `is_hot` tinyint(1) DEFAULT 0 COMMENT '是否热卖（0否，1是）',
  `sort` int(11) DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态（0禁用，1启用）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_brand_id` (`brand_id`),
  KEY `idx_is_on_sale` (`is_on_sale`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品主表（SPU）';

-- -----------------------------
-- 七、商品SKU表
-- -----------------------------
DROP TABLE IF EXISTS `mb_goods_sku`;
CREATE TABLE `mb_goods_sku` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'SKU ID',
  `goods_id` int(11) unsigned NOT NULL COMMENT '商品ID（SPU）',
  `spec_values` varchar(500) NOT NULL COMMENT '规格值组合，单规格固定为空字符串',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '销售价',
  `market_price` decimal(10,2) DEFAULT NULL COMMENT '市场价（划线价）',
  `cost_price` decimal(10,2) DEFAULT NULL COMMENT '成本价',
  `stock` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '库存',
  `sku_code` varchar(100) DEFAULT NULL COMMENT 'SKU编码',
  `image` varchar(255) DEFAULT NULL COMMENT 'SKU图片',
  `weight` decimal(10,2) DEFAULT NULL COMMENT '重量（克）',
  `volume` decimal(10,2) DEFAULT NULL COMMENT '体积（立方厘米）',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态（0禁用，1启用）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_goods_id` (`goods_id`),
  KEY `idx_sku_code` (`sku_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品SKU表';

-- -----------------------------
-- 八、商品标签表
-- -----------------------------
DROP TABLE IF EXISTS `mb_goods_tag`;
CREATE TABLE `mb_goods_tag` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '标签ID',
  `name` varchar(50) NOT NULL COMMENT '标签名称',
  `color` varchar(20) DEFAULT NULL COMMENT '显示颜色',
  `sort` int(11) DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态（0禁用，1启用）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品标签表';

-- -----------------------------
-- 九、商品-标签关联表
-- -----------------------------
DROP TABLE IF EXISTS `mb_goods_tag_relation`;
CREATE TABLE `mb_goods_tag_relation` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `goods_id` int(11) unsigned NOT NULL COMMENT '商品ID',
  `tag_id` int(11) unsigned NOT NULL COMMENT '标签ID',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_goods_tag` (`goods_id`, `tag_id`),
  KEY `idx_goods_id` (`goods_id`),
  KEY `idx_tag_id` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品标签关联表';

-- -----------------------------
-- 十、商品评论表
-- -----------------------------
DROP TABLE IF EXISTS `mb_goods_comment`;
CREATE TABLE `mb_goods_comment` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '评论ID',
  `goods_id` int(11) unsigned NOT NULL COMMENT '商品ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `order_id` int(11) unsigned DEFAULT NULL COMMENT '订单ID',
  `sku_id` int(11) unsigned DEFAULT NULL COMMENT 'SKU ID',
  `content` text NOT NULL COMMENT '评论内容',
  `images` varchar(1000) DEFAULT NULL COMMENT '评论图片JSON数组',
  `rating` tinyint(1) NOT NULL DEFAULT 5 COMMENT '评分（1-5）',
  `is_anonymous` tinyint(1) DEFAULT 0 COMMENT '是否匿名（0否，1是）',
  `reply_content` text DEFAULT NULL COMMENT '商家回复内容',
  `reply_time` datetime DEFAULT NULL COMMENT '商家回复时间',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态（0隐藏，1显示）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_goods_id` (`goods_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品评论表';
