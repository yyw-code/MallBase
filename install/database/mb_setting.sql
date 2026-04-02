-- ============================================
-- 系统设置模块数据库表结构
-- 表前缀：mb_
-- ============================================

-- -----------------------------
-- 设置分组表
-- -----------------------------
DROP TABLE IF EXISTS `mb_setting_group`;
CREATE TABLE `mb_setting_group` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '分组ID',
  `parent_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '父级分组ID（0=顶级分组）',
  `permission_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '关联权限ID（对应 mb_permission.id，source=3）',
  `name` varchar(100) NOT NULL COMMENT '分组名称（如：微信登录设置）',
  `code` varchar(100) NOT NULL COMMENT '分组编码（如：wechat_login）',
  `icon` varchar(100) DEFAULT NULL COMMENT '图标',
  `description` varchar(255) DEFAULT NULL COMMENT '分组描述',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0=禁用 1=启用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_permission_id` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设置分组表';

-- -----------------------------
-- 设置项表
-- -----------------------------
DROP TABLE IF EXISTS `mb_setting`;
CREATE TABLE `mb_setting` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '设置项ID',
  `group_id` int(11) unsigned NOT NULL COMMENT '分组ID',
  `name` varchar(100) NOT NULL COMMENT '设置项名称（如：AppID）',
  `code` varchar(100) NOT NULL COMMENT '设置项编码（如：wechat_appid）',
  `value` text DEFAULT NULL COMMENT '设置值',
  `type` varchar(20) NOT NULL DEFAULT 'input' COMMENT '表单类型：input/textarea/number/password/switch/radio/checkbox/select/image/images/file/files/editor/json',
  `options` json DEFAULT NULL COMMENT '选项（type=select时的可选值，如 [{"label":"启用","value":"1"}]）',
  `rules` json DEFAULT NULL COMMENT '验证规则（如 [{"type":"required","message":"不能为空"},{"type":"minLength","value":6,"message":"最少6个字符"}]）',
  `placeholder` varchar(255) DEFAULT NULL COMMENT '输入提示',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注说明',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_code` (`group_id`, `code`),
  KEY `idx_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设置项表';