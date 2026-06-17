-- ============================================
-- 后台权限管理系统数据库表结构
-- 表前缀：mb_
-- ============================================

-- -----------------------------
-- 管理员表
-- -----------------------------
DROP TABLE IF EXISTS `mb_admin`;
CREATE TABLE `mb_admin` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '管理员ID',
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `password` varchar(255) NOT NULL COMMENT '密码（加密）',
  `nickname` varchar(50) DEFAULT NULL COMMENT '昵称',
  `avatar` varchar(255) DEFAULT NULL COMMENT '头像',
  `email` varchar(100) DEFAULT NULL COMMENT '邮箱',
  `mobile` varchar(20) DEFAULT NULL COMMENT '手机号',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0=禁用 1=启用',
  `last_login_time` datetime DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip` varchar(50) DEFAULT NULL COMMENT '最后登录IP',
  `password_changed_at` datetime DEFAULT NULL COMMENT '最近改密时间，NULL=未知或从未改过',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表';

-- -----------------------------
-- 管理员角色表
-- -----------------------------
DROP TABLE IF EXISTS `mb_admin_role`;
CREATE TABLE `mb_admin_role` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '角色ID',
  `admin_id` int(11) unsigned NOT NULL COMMENT '管理员ID',
  `role_id` int(11) unsigned NOT NULL COMMENT '角色ID',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_role` (`admin_id`, `role_id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员角色关联表';

-- -----------------------------
-- 角色表
-- -----------------------------
DROP TABLE IF EXISTS `mb_role`;
CREATE TABLE `mb_role` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '角色ID',
  `name` varchar(50) NOT NULL COMMENT '角色名称',
  `code` varchar(50) NOT NULL COMMENT '角色编码',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0=禁用 1=启用',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色表';

-- -----------------------------
-- 权限表
-- -----------------------------
DROP TABLE IF EXISTS `mb_permission`;
CREATE TABLE `mb_permission` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '权限ID',
  `parent_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '父级权限ID',
  `name` varchar(100) NOT NULL COMMENT '权限名称',
  `code` varchar(100) NOT NULL COMMENT '权限编码',
  `type` tinyint(1) NOT NULL DEFAULT 1 COMMENT '类型：1=菜单 2=按钮 3=接口',
  `path` varchar(255) DEFAULT NULL COMMENT '路由路径',
  `icon` varchar(100) DEFAULT NULL COMMENT '图标',
  `component` varchar(100) DEFAULT NULL COMMENT '页面路径',
  `redirect` varchar(255) DEFAULT NULL COMMENT '重定向路径',
  `affix_tab` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否固定标签页：0=不固定 1=固定（不可关闭）',
  `no_basic_layout` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否需要基础布局：0=需要 1=不需要',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0=禁用 1=启用',
  `is_show` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否显示：0=隐藏 1=显示',
  `source` tinyint(1) NOT NULL DEFAULT 1 COMMENT '来源：1=手动添加 2=路由同步 3=设置模块同步',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_type` (`type`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='权限表';

-- -----------------------------
-- 角色权限关联表
-- -----------------------------
DROP TABLE IF EXISTS `mb_role_permission`;
CREATE TABLE `mb_role_permission` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `role_id` int(11) unsigned NOT NULL COMMENT '角色ID',
  `permission_id` int(11) unsigned NOT NULL COMMENT '权限ID',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_permission` (`role_id`, `permission_id`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_permission_id` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色权限关联表';

-- -----------------------------
-- 管理员操作日志表
-- -----------------------------
DROP TABLE IF EXISTS `mb_admin_operation_log`;
CREATE TABLE `mb_admin_operation_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `admin_id` int(11) unsigned NOT NULL COMMENT '管理员ID',
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `nickname` varchar(50) DEFAULT NULL COMMENT '昵称',
  `path` varchar(255) NOT NULL COMMENT '请求路径',
  `method` varchar(10) NOT NULL COMMENT '请求方法',
  `params` json DEFAULT NULL COMMENT '请求参数',
  `response` json DEFAULT NULL COMMENT '响应数据',
  `status` int(11) NOT NULL COMMENT '响应状态码',
  `ip` varchar(50) NOT NULL COMMENT 'IP地址',
  `user_agent` varchar(500) DEFAULT NULL COMMENT 'User-Agent',
  `duration` float(10,2) NOT NULL COMMENT '执行时间（毫秒）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员操作日志表';

-- -----------------------------
-- 插入初始数据
-- -----------------------------

-- 插入超级管理员（默认密码：admin123，需要在生产环境修改）
INSERT INTO `mb_admin` (`id`, `username`, `password`, `nickname`, `avatar`, `status`, `password_changed_at`, `remark`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '超级管理员', '/static/admin/logo.png', 1, CURRENT_TIMESTAMP, '系统默认管理员');

-- 插入默认角色
INSERT INTO `mb_role` (`id`, `name`, `code`, `remark`, `status`, `sort`) VALUES
(1, '超级管理员', 'super_admin', '拥有所有权限', 1, 1),
(2, '普通管理员', 'admin', '普通管理员权限', 1, 2);
