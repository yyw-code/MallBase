-- ============================================
-- 短信模块数据库表结构
-- 表前缀：mb_
-- ============================================
SET NAMES utf8mb4;

-- 设计说明：
--   1. 服务商 / 签名 / 模板三张表按 driver 抽象，当前短信服务仅保留阿里云。
--   2. 场景绑定表把内置 SmsScene 与模板 + 签名挂钩，并保存场景侧模板草稿。
--   3. 全局频控配置走系统表单 SmsRateLimit 分组，由短信配置菜单专属入口展示。
-- ============================================

-- -----------------------------
-- 短信服务商表
-- -----------------------------
DROP TABLE IF EXISTS `mb_sms_provider`;
CREATE TABLE `mb_sms_provider` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '服务商ID',
  `name` varchar(60) NOT NULL COMMENT '服务商显示名（如：阿里云短信-生产）',
  `driver` varchar(20) NOT NULL COMMENT '驱动类型：aliyun/mock',
  `access_key_id` varchar(128) NOT NULL DEFAULT '' COMMENT 'AccessKeyId',
  `access_key_secret` varchar(255) NOT NULL DEFAULT '' COMMENT 'AccessKeySecret（AES-256-CBC 密文，密钥从 APP_KEY 派生）',
  `region` varchar(40) NOT NULL DEFAULT 'cn-hangzhou' COMMENT '区域（阿里云：cn-hangzhou）',
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
  `sign_id` int(11) unsigned DEFAULT NULL COMMENT '关联签名ID（阿里云 CreateSmsTemplate 必需的 RelatedSignName 来源）',
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
  `template_id` int(11) unsigned DEFAULT NULL COMMENT '模板ID',
  `sign_id` int(11) unsigned DEFAULT NULL COMMENT '签名ID',
  `draft_template_name` varchar(100) NOT NULL DEFAULT '' COMMENT '场景侧模板草稿名称',
  `draft_template_content` text COMMENT '场景侧模板草稿内容',
  `draft_template_type` tinyint(4) NOT NULL DEFAULT 0 COMMENT '场景侧模板草稿类型（阿里云：0=验证码 1=通知 2=推广 3=国际/港澳台）',
  `draft_template_remark` varchar(255) DEFAULT NULL COMMENT '场景侧模板草稿申请说明',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0=禁用 1=启用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scene_code` (`scene_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短信场景模板绑定';

-- ============================================
-- seed 数据
-- ============================================

-- 场景模板草稿默认行（未绑定模板，首次安装即可在库中看到 5 个内置场景）
INSERT INTO `mb_sms_scene_binding`
(`scene_code`, `provider_id`, `template_id`, `sign_id`, `draft_template_name`, `draft_template_content`, `draft_template_type`, `draft_template_remark`, `status`) VALUES
('login', 0, NULL, NULL, '登录验证码', '您的登录验证码是 ${code},5 分钟内有效,请勿泄露。', 0, '登录场景验证码短信,用于用户登录身份校验', 0),
('register', 0, NULL, NULL, '注册验证码', '您的注册验证码是 ${code},5 分钟内有效,请勿泄露。', 0, '注册场景验证码短信,用于新用户注册校验', 0),
('reset_password', 0, NULL, NULL, '找回密码验证码', '您正在找回密码,验证码是 ${code},5 分钟内有效。', 0, '找回密码场景验证码短信,用于账号安全校验', 0),
('bind_mobile', 0, NULL, NULL, '绑定手机号验证码', '您正在绑定手机号,验证码是 ${code},5 分钟内有效。', 0, '绑定手机号场景验证码短信,用于手机号绑定校验', 0),
('wechat_official_bind', 0, NULL, NULL, '公众号绑定验证码', '您正在绑定公众号账号,验证码是 ${code},5 分钟内有效。', 0, '公众号绑定场景验证码短信,用于公众号账号绑定校验', 0)
ON DUPLICATE KEY UPDATE
  `draft_template_name` = IF(
    `draft_template_name` = ''
      OR CONVERT(BINARY CONVERT(`draft_template_name` USING latin1) USING utf8mb4) = VALUES(`draft_template_name`),
    VALUES(`draft_template_name`),
    `draft_template_name`
  ),
  `draft_template_content` = IF(
    `draft_template_content` IS NULL
      OR `draft_template_content` = ''
      OR CONVERT(BINARY CONVERT(`draft_template_content` USING latin1) USING utf8mb4) = VALUES(`draft_template_content`),
    VALUES(`draft_template_content`),
    `draft_template_content`
  ),
  `draft_template_remark` = IF(
    `draft_template_remark` IS NULL
      OR `draft_template_remark` = ''
      OR CONVERT(BINARY CONVERT(`draft_template_remark` USING latin1) USING utf8mb4) = VALUES(`draft_template_remark`),
    VALUES(`draft_template_remark`),
    `draft_template_remark`
  );
