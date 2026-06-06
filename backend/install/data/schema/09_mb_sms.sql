-- ============================================
-- 短信模块数据库表结构
-- 表前缀：mb_
-- ============================================
-- 设计说明：
--   1. 服务商 / 签名 / 模板三张表按 driver 抽象，预留腾讯云接入。
--   2. 场景绑定表把内置 SmsScene 与模板 + 签名挂钩，发送时按 scene_code 反查。
--   3. 全局频控配置走单行表 mb_sms_config，替代旧 setting 项 sms_code_ttl/sms_rate_*。
-- ============================================

-- -----------------------------
-- 短信服务商表
-- -----------------------------
DROP TABLE IF EXISTS `mb_sms_provider`;
CREATE TABLE `mb_sms_provider` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '服务商ID',
  `name` varchar(60) NOT NULL COMMENT '服务商显示名（如：阿里云短信-生产）',
  `driver` varchar(20) NOT NULL COMMENT '驱动类型：aliyun/aliyun_pnvs/tencent/mock',
  `access_key_id` varchar(128) NOT NULL DEFAULT '' COMMENT 'AccessKeyId',
  `access_key_secret` varchar(255) NOT NULL DEFAULT '' COMMENT 'AccessKeySecret（AES-256-CBC 密文，密钥从 APP_KEY 派生）',
  `region` varchar(40) NOT NULL DEFAULT 'cn-hangzhou' COMMENT '区域（阿里云：cn-hangzhou）',
  `scheme_name` varchar(60) NOT NULL DEFAULT '' COMMENT '认证方案名称（PNVS 专用，可选）',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否默认服务商：0=否 1=是（全表唯一）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0=禁用 1=启用',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_driver` (`driver`),
  KEY `idx_is_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短信服务商';

-- -----------------------------
-- 短信签名表
-- -----------------------------
DROP TABLE IF EXISTS `mb_sms_sign`;
CREATE TABLE `mb_sms_sign` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '签名ID',
  `provider_id` int(11) unsigned NOT NULL COMMENT '所属服务商ID',
  `sign_name` varchar(100) NOT NULL COMMENT '签名名称（与阿里云控制台一致）',
  `sign_source` tinyint(4) NOT NULL DEFAULT 0 COMMENT '签名来源（阿里云：0=企事业单位的全称或简称，1=工信部备案网站全称或简称，2=App应用全称，3=公众号或小程序，4=电商平台店铺名，5=商标名）',
  `sign_type` tinyint(1) NOT NULL DEFAULT 1 COMMENT '签名类型（阿里云：0=验证码 1=通用）',
  `remark` varchar(200) DEFAULT NULL COMMENT '申请说明',
  `qualification_id` int(11) unsigned DEFAULT NULL COMMENT '关联资质ID（阿里云独立维护）',
  `audit_status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT '审核状态：pending/passed/rejected/local_only',
  `audit_reason` varchar(500) DEFAULT NULL COMMENT '审核失败原因',
  `last_synced_at` datetime DEFAULT NULL COMMENT '最近一次同步状态时间',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_sign_name` (`provider_id`, `sign_name`),
  KEY `idx_audit_status` (`audit_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短信签名';

-- -----------------------------
-- 短信模板表
-- -----------------------------
DROP TABLE IF EXISTS `mb_sms_template`;
CREATE TABLE `mb_sms_template` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '模板ID',
  `provider_id` int(11) unsigned NOT NULL COMMENT '所属服务商ID',
  `sign_id` int(11) unsigned DEFAULT NULL COMMENT '关联签名ID（阿里云 CreateSmsTemplate 必需的 RelatedSignName 来源；PNVS 模板可为空）',
  `template_name` varchar(100) NOT NULL COMMENT '模板名称',
  `template_code` varchar(80) DEFAULT NULL COMMENT '模板编码（阿里云返回的 SMS_xxx，本地新建/提交中尚未分配时为 NULL；NULL 不参与唯一键冲突）',
  `template_type` tinyint(4) NOT NULL DEFAULT 0 COMMENT '模板类型（阿里云：0=验证码 1=通知 2=推广 3=国际/港澳台）',
  `template_content` text NOT NULL COMMENT '模板内容（含 ${code} 等占位符）',
  `audit_status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT '审核状态：submitting/pending/passed/rejected/local_only',
  `audit_reason` varchar(500) DEFAULT NULL COMMENT '审核失败原因',
  `remark` varchar(255) DEFAULT NULL COMMENT '申请说明',
  `last_synced_at` datetime DEFAULT NULL COMMENT '最近一次同步状态时间',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_template_code` (`provider_id`, `template_code`),
  KEY `idx_audit_status` (`audit_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短信模板';

-- -----------------------------
-- 短信场景绑定表
-- -----------------------------
DROP TABLE IF EXISTS `mb_sms_scene_binding`;
CREATE TABLE `mb_sms_scene_binding` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `scene_code` varchar(40) NOT NULL COMMENT '场景编码（login/register/reset_password/bind_mobile/wechat_official_bind）',
  `provider_id` int(11) unsigned NOT NULL COMMENT '服务商ID',
  `template_id` int(11) unsigned DEFAULT NULL COMMENT '模板ID（PNVS 驱动可为空）',
  `sign_id` int(11) unsigned DEFAULT NULL COMMENT '签名ID（PNVS 驱动可为空）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0=禁用 1=启用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scene_code` (`scene_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短信场景模板绑定';

-- -----------------------------
-- 短信全局配置表（单行）
-- -----------------------------
DROP TABLE IF EXISTS `mb_sms_config`;
CREATE TABLE `mb_sms_config` (
  `id` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '主键（固定 = 1，全表单行）',
  `code_ttl` int(11) unsigned NOT NULL DEFAULT 300 COMMENT '验证码有效期（秒）',
  `rate_mobile_daily` int(11) unsigned NOT NULL DEFAULT 5 COMMENT '同手机号 24 小时发送上限',
  `rate_ip_minute` int(11) unsigned NOT NULL DEFAULT 3 COMMENT '同 IP 每分钟发送上限',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短信全局配置（单行）';

-- ============================================
-- seed 数据
-- ============================================

-- 全局配置默认行
INSERT INTO `mb_sms_config` (`id`, `code_ttl`, `rate_mobile_daily`, `rate_ip_minute`) VALUES
(1, 300, 5, 3);
