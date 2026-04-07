-- ============================================
-- 前台用户管理系统数据库表结构
-- 表前缀：mb_
-- ============================================

-- -----------------------------
-- 前台用户表
-- -----------------------------
DROP TABLE IF EXISTS `mb_user`;
CREATE TABLE `mb_user` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  -- 核心账号信息
  `mobile` varchar(20) DEFAULT NULL COMMENT '手机号',
  `email` varchar(100) DEFAULT NULL COMMENT '邮箱',
  `password` varchar(255) DEFAULT NULL COMMENT '密码（加密）',
  `nickname` varchar(50) DEFAULT NULL COMMENT '昵称',
  `avatar` varchar(255) DEFAULT NULL COMMENT '头像URL',

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
  `register_type` varchar(20) DEFAULT 'mobile' COMMENT '注册类型（mobile手机/email邮箱）',
  `register_ip` varchar(45) DEFAULT NULL COMMENT '注册IP',

  -- 通用字段
  `remark` varchar(500) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mobile` (`mobile`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_status` (`status`),
  KEY `idx_mobile` (`mobile`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台用户表';
