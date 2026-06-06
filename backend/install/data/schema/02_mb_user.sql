-- ============================================
-- 前台用户管理系统数据库表结构
-- 表前缀：mb_
-- 包含：用户表、分组表、标签表及关联表
-- ============================================

-- -----------------------------
-- 一、前台用户表
-- -----------------------------
DROP TABLE IF EXISTS `mb_user`;
CREATE TABLE `mb_user` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  -- 核心账号信息
  `username` varchar(60) DEFAULT NULL COMMENT '用户名（账号密码登录使用，可选）',
  `mobile` varchar(20) DEFAULT NULL COMMENT '手机号',
  `email` varchar(100) DEFAULT NULL COMMENT '邮箱（仅后台 admin 可填，C 端注册不再写入）',
  `password` varchar(255) DEFAULT NULL COMMENT '密码（加密）',
  `nickname` varchar(50) DEFAULT NULL COMMENT '昵称',
  `avatar` varchar(255) DEFAULT NULL COMMENT '头像URL',

  -- 微信相关字段（多端独立 openid，unionid 跨主体共享）
  `wx_miniapp_openid` varchar(100) DEFAULT NULL COMMENT '微信小程序 openid',
  `wx_official_openid` varchar(100) DEFAULT NULL COMMENT '微信公众号 openid',
  `wx_unionid` varchar(100) DEFAULT NULL COMMENT '微信 unionid（开放平台主体下跨 AppID 共享）',
  `session_key` varchar(100) DEFAULT NULL COMMENT '微信小程序会话密钥',

  -- 个人资料
  `real_name` varchar(50) DEFAULT NULL COMMENT '真实姓名',
  `gender` tinyint(1) DEFAULT 0 COMMENT '性别（0未知，1男，2女）',
  `birthday` date DEFAULT NULL COMMENT '生日',
  `province` varchar(50) DEFAULT NULL COMMENT '省份',
  `city` varchar(50) DEFAULT NULL COMMENT '城市',
  `district` varchar(50) DEFAULT NULL COMMENT '区县',
  `bio` varchar(500) DEFAULT NULL COMMENT '个人简介',

  -- 安全字段
  `mobile_verified` tinyint(1) DEFAULT 0 COMMENT '手机已验证（0否，1是）',
  `last_login_time` datetime DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip` varchar(45) DEFAULT NULL COMMENT '最后登录IP',

  -- 状态管理
  `status` tinyint(1) DEFAULT 1 COMMENT '状态（0禁用，1启用）',
  `register_type` varchar(20) DEFAULT 'mobile' COMMENT '注册来源（mobile手机/wechat_miniapp微信小程序/wechat_official微信公众号/h5网页）',
  `register_ip` varchar(45) DEFAULT NULL COMMENT '注册IP',

  -- 通用字段
  `remark` varchar(500) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_mobile` (`mobile`),
  UNIQUE KEY `uk_wx_miniapp_openid` (`wx_miniapp_openid`),
  UNIQUE KEY `uk_wx_official_openid` (`wx_official_openid`),
  KEY `idx_email` (`email`),
  KEY `idx_wx_unionid` (`wx_unionid`),
  KEY `idx_status` (`status`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台用户表';

-- -----------------------------
-- 二、用户分组表
-- -----------------------------
DROP TABLE IF EXISTS `mb_user_group`;
CREATE TABLE `mb_user_group` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '分组名称',
  `code` varchar(50) NOT NULL COMMENT '分组编码',
  `description` varchar(255) DEFAULT NULL COMMENT '分组描述',
  `color` varchar(20) DEFAULT NULL COMMENT '显示颜色',
  `sort` int(11) DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态（0禁用，1启用）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户分组表';

-- -----------------------------
-- 三、用户标签表
-- -----------------------------
DROP TABLE IF EXISTS `mb_user_tag`;
CREATE TABLE `mb_user_tag` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '标签名称',
  `color` varchar(20) DEFAULT NULL COMMENT '显示颜色',
  `sort` int(11) DEFAULT 0 COMMENT '排序（数字越小越靠前）',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态（0禁用，1启用）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户标签表';

-- -----------------------------
-- 四、用户-分组关联表
-- -----------------------------
DROP TABLE IF EXISTS `mb_user_group_relation`;
CREATE TABLE `mb_user_group_relation` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `group_id` int(11) unsigned NOT NULL COMMENT '分组ID',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_group` (`user_id`, `group_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户分组关联表';

-- -----------------------------
-- 五、用户-标签关联表
-- -----------------------------
DROP TABLE IF EXISTS `mb_user_tag_relation`;
CREATE TABLE `mb_user_tag_relation` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `tag_id` int(11) unsigned NOT NULL COMMENT '标签ID',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_tag` (`user_id`, `tag_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_tag_id` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户标签关联表';
