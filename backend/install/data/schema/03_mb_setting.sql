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
  `display_type` varchar(20) NOT NULL DEFAULT 'page' COMMENT '展示方式：category=目录 page=独立页面 tab=选项卡聚合',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0=禁用 1=启用',
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否系统内置：0=用户添加 1=系统内置',
  `permission_parent_code` varchar(100) DEFAULT NULL COMMENT '权限挂载父级编码（为空按设置分组层级）',
  `permission_path` varchar(255) DEFAULT NULL COMMENT '权限菜单路由覆盖（为空自动生成）',
  `permission_component` varchar(255) DEFAULT NULL COMMENT '权限菜单组件覆盖（为空使用系统设置表单）',
  `permission_status` tinyint(1) DEFAULT NULL COMMENT '权限状态覆盖：0=禁用 1=启用 NULL=跟随分组状态',
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
  `type` varchar(20) NOT NULL DEFAULT 'input' COMMENT '表单类型：input/textarea/number/password/switch/radio/checkbox/select/image/images/file/files/editor/json/option_list',
  `options` json DEFAULT NULL COMMENT '选项（type=select时的可选值，如 [{"label":"启用","value":"1"}]）',
  `rules` json DEFAULT NULL COMMENT '验证规则（如 [{"type":"required","message":"不能为空"},{"type":"minLength","value":6,"message":"最少6个字符"}]）',
  `ui` json DEFAULT NULL COMMENT '后台动态表单交互元数据（显示条件、交互组件、远程选项源等）',
  `placeholder` varchar(255) DEFAULT NULL COMMENT '输入提示',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注说明',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否系统内置：0=用户添加 1=系统内置',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_code` (`group_id`, `code`),
  KEY `idx_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设置项表';

-- -----------------------------
-- 设置页内分组表
-- -----------------------------
DROP TABLE IF EXISTS `mb_setting_section`;
CREATE TABLE `mb_setting_section` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '页内分组ID',
  `group_id` int(11) unsigned NOT NULL COMMENT '设置分组ID',
  `name` varchar(64) NOT NULL COMMENT '页内分组名称',
  `code` varchar(64) NOT NULL COMMENT '页内分组编码',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否系统内置：0=用户添加 1=系统内置',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_code` (`group_id`, `code`),
  KEY `idx_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设置页内分组表';

-- ============================================
-- 系统设置 - 默认分组与设置项 seed
-- permission_id 全部为 0，安装末尾由 SettingService::rebuildAllPermissions() 统一同步
-- 所有 name/remark/placeholder 均中文；code 保持英文作为内部 URL 与键
-- ID 从 100 段起，避免与未来新增分组冲突
-- ============================================

-- 一级分组：系统设置（目录）
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(100, 0, 0, '系统设置', 'SystemSetting', 'lucide:settings-2', '站点基础信息、上传、支付、微信与客户端等全局配置', 10, 'category', 1);

-- 二级分组：系统配置（选项卡）及其子页面
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(101, 100, 0, '系统配置', 'SystemConfig', 'lucide:sliders', '站点名称、域名、后台品牌与版权等系统级配置', 10, 'tab', 1),
(1011, 101, 0, '基础', 'SystemBasic', NULL, '站点名称、域名、Logo、登录页品牌等基础信息', 10, 'page', 1),
(1012, 101, 0, '系统版权', 'SystemCopyright', NULL, '后台与客户端页脚展示的版权与备案信息', 20, 'page', 1);

-- 二级分组：上传配置（选项卡）及其子页面
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(102, 100, 0, '上传配置', 'UploadConfig', 'lucide:upload-cloud', '上传驱动、MIME 白名单与各云存储参数', 20, 'tab', 1),
(1021, 102, 0, '基础', 'UploadBasic', NULL, '默认上传驱动与允许的文件 MIME 类型', 10, 'page', 1),
(1022, 102, 0, '本地存储', 'UploadLocal', NULL, '本地存储目录、URL 前缀与访问域名', 20, 'page', 1),
(1023, 102, 0, '阿里云OSS', 'UploadOss', NULL, '阿里云 OSS Bucket、Endpoint 与访问凭证', 30, 'page', 1),
(1024, 102, 0, '腾讯云COS', 'UploadCos', NULL, '腾讯云 COS Bucket、Region 与访问凭证', 40, 'page', 1);

-- 二级分组：微信配置（选项卡）及其子页面
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(103, 100, 0, '微信配置', 'WechatConfig', 'lucide:message-circle', '微信小程序与公众号接入参数', 30, 'tab', 1),
(1031, 103, 0, '微信小程序', 'WechatMiniProgram', NULL, '小程序 AppID、AppSecret 与授权品牌资源', 10, 'page', 1),
(1032, 103, 0, '微信公众号', 'WechatOffiAccount', NULL, '公众号 AppID、AppSecret 与消息加解密参数', 20, 'page', 1);

-- 二级分组：支付配置（选项卡）及其子页面
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(104, 100, 0, '支付配置', 'PaymentConfig', 'lucide:credit-card', '微信支付与余额支付渠道参数', 40, 'tab', 1),
(1041, 104, 0, '基础', 'PaymentBasic', NULL, '各支付渠道的启用状态总开关', 10, 'page', 1),
(1042, 104, 0, '微信支付V3', 'PaymentWechat', NULL, '微信支付 V3 商户号、APIv3 密钥与证书参数', 20, 'page', 1);

-- 二级分组：客户端配置（数据源保留，后台入口使用「客户端装修 -> 客户端配置」专属页面）
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`, `permission_status`) VALUES
(105, 100, 0, '客户端配置', 'ClientConfig', 'lucide:smartphone', 'App/H5 客户端品牌、启动屏、分享与协议等配置', 50, 'page', 1, 0);

-- SystemConfig 子页面：订单与售后配置（作为系统配置页签）
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(106, 101, 0, '订单配置', 'OrderConfig', NULL, '待支付超时、自动确认收货等订单流程配置', 30, 'page', 1),
(107, 101, 0, '售后配置', 'RefundConfig', NULL, '售后期限、退货收货信息与售后原因配置', 40, 'page', 1);

-- 短信频控配置使用系统表单数据源，但由「短信配置 → 短信频控」专属菜单与权限入口展示
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`, `permission_parent_code`, `permission_path`, `permission_component`, `permission_status`) VALUES
(108, 0, 0, '短信频控', 'SmsRateLimit', 'lucide:gauge', '验证码有效期与发送频控阈值', 80, 'page', 1, 'SmsConfig', '/sms/config', '/sms/config/index', 1);

-- 营销配置：积分与会员使用系统表单数据源，挂载到「营销管理」下
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`, `permission_parent_code`, `permission_path`, `permission_component`, `permission_status`) VALUES
(109, 0, 0, '积分配置', 'PointsConfig', 'lucide:badge-plus', '积分总开关、发放、冻结与订单抵扣配置', 10, 'page', 1, 'SystemPointsManagement', '/points/config', '/settings/dynamic-form/index', 1),
(110, 0, 0, '会员配置', 'MemberConfig', 'lucide:badge-check', '会员总开关与成长值比例配置', 20, 'page', 1, 'SystemMemberManagement', '/member/config', '/settings/dynamic-form/index', 1);

-- 分销配置：作为分销模块专属页面的系统表单数据源，不单独生成系统设置菜单
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`, `permission_parent_code`, `permission_path`, `permission_component`, `permission_status`) VALUES
(111, 0, 0, '分销基础设置', 'DistributionConfig', 'lucide:settings-2', '分销总开关、结算、提现与默认佣金比例配置', 30, 'page', 1, 'SystemDistributionSettings', '/distribution/settings', '/distribution/settings/index', 0);

-- 页内分组：111 DistributionConfig 分销基础设置
INSERT INTO `mb_setting_section` (`group_id`, `name`, `code`, `sort`, `is_system`) VALUES
(111, '基础开关', 'basic', 10, 1),
(111, '分销员开通', 'opening', 20, 1),
(111, '分佣规则', 'commission', 30, 1),
(111, '归因与结算', 'settlement', 40, 1),
(111, '提现设置', 'withdraw', 50, 1),
(111, '邀请奖励', 'invite', 60, 1);

-- 设置项：1011 SystemBasic 基础
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1011, '站点名称', 'site_name', 'Mall Base', 'input', NULL, '[{"type":"required","message":"不能为空"}]', NULL, NULL, 10),
(1011, '站点口号', 'site_slogan', '让每个商家都有独立店铺', 'input', NULL, NULL, NULL, NULL, 20),
(1011, '站点域名', 'site_url', '', 'input', NULL, '[{"type":"required","message":"不能为空"}]', NULL, '安装时由系统自动写入', 30),
(1011, '默认头像', 'default_avatar', '/static/admin/avatar-default.png', 'image', NULL, NULL, NULL, '推荐 1:1，建议 256×256 PNG 透明，<50KB', 40),
(1011, '后台 Logo', 'admin_logo', '/static/admin/logo.png', 'image', NULL, NULL, NULL, '推荐 1:1，建议 200×200 PNG 透明背景，<100KB。后台侧边栏顶部和登录页顶部显示', 50),
(1011, '浏览器图标', 'admin_favicon', '/static/admin/favicon.png', 'image', NULL, NULL, NULL, '推荐 1:1，建议 64×64 ICO/PNG，<32KB', 60),
(1011, '登录页装饰图', 'admin_slogan_image', '/static/admin/slogan.png', 'image', NULL, NULL, NULL, '登录页左侧展示区装饰图，推荐 1:1 或 5:4，建议 400×400 PNG 透明，<200KB。留空则使用默认 SVG', 70),
(1011, '登录页主标题', 'admin_login_title', '开箱即用的开源商城中后台', 'input', NULL, NULL, NULL, '登录页左侧展示区的主标题', 80),
(1011, '登录页副标题', 'admin_login_subtitle', '三层架构 · Swoole 高性能 · 工程化前端模板', 'input', NULL, NULL, NULL, '登录页左侧展示区的描述文字', 90),
(1011, '登录框欢迎语', 'admin_login_welcome', '欢迎回来 👋🏻', 'input', NULL, NULL, NULL, '登录表单顶部欢迎语', 100),
(1011, '登录框副标题', 'admin_login_welcome_desc', '请输入您的账户信息以开始管理您的项目', 'input', NULL, NULL, NULL, '登录表单欢迎语下方的副标题', 110);

-- 设置项：1012 SystemCopyright 系统版权（后台与 Client 共用）
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1012, '启用版权', 'copyright_enabled', '1', 'switch', NULL, NULL, NULL, '关闭后后台/Client 页脚不显示版权信息', 10),
(1012, '公司名称', 'copyright_company', 'MallBase Team', 'input', NULL, NULL, NULL, NULL, 20),
(1012, '公司主页', 'copyright_company_url', 'https://github.com/gosowong/mall-base', 'input', NULL, NULL, NULL, NULL, 30),
(1012, '版权年份', 'copyright_date', '{year}', 'input', NULL, NULL, NULL, '支持 {year} 占位符自动替换为当前年', 40),
(1012, 'ICP 备案号', 'copyright_icp', '', 'input', NULL, NULL, '例：京ICP备12345678号', '大陆站点页脚必须显示', 50),
(1012, 'ICP 备案链接', 'copyright_icp_url', 'https://beian.miit.gov.cn', 'input', NULL, NULL, NULL, NULL, 60),
(1012, '公安备案号', 'copyright_psb', '', 'input', NULL, NULL, '例：京公网安备11010802012345号', NULL, 70),
(1012, '公安备案链接', 'copyright_psb_url', '', 'input', NULL, NULL, NULL, NULL, 80);

-- 设置项：1021 UploadBasic 上传基础
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1021, '默认上传驱动', 'upload_driver', 'local', 'select', '[{"label":"本地存储","value":"local"},{"label":"阿里云 OSS","value":"oss"},{"label":"腾讯云 COS","value":"cos"}]', '[{"type":"required","message":"不能为空"}]', NULL, NULL, 10),
(1021, '单图最大体积(MB)', 'upload_image_max_size', '2', 'number', NULL, NULL, NULL, NULL, 50),
(1021, '多图单张最大体积(MB)', 'upload_images_max_size', '5', 'number', NULL, NULL, NULL, NULL, 60),
(1021, '多图最大张数', 'upload_images_max_count', '9', 'number', NULL, NULL, NULL, NULL, 70),
(1021, '单文件最大体积(MB)', 'upload_file_max_size', '10', 'number', NULL, NULL, NULL, NULL, 80),
(1021, '多文件单个最大体积(MB)', 'upload_files_max_size', '10', 'number', NULL, NULL, NULL, NULL, 90),
(1021, '多文件最大个数', 'upload_files_max_count', '5', 'number', NULL, NULL, NULL, NULL, 100),
(1021, '单视频最大体积(MB)', 'upload_video_max_size', '200', 'number', NULL, NULL, NULL, NULL, 110),
(1021, '多视频单个最大体积(MB)', 'upload_videos_max_size', '200', 'number', NULL, NULL, NULL, NULL, 120),
(1021, '多视频最大个数', 'upload_videos_max_count', '5', 'number', NULL, NULL, NULL, NULL, 130),
(1021, '单证书最大体积(MB)', 'upload_cert_max_size', '1', 'number', NULL, NULL, NULL, NULL, 145),
(1021, '图片 MIME 白名单', 'mime_image', 'image/jpeg,image/png,image/gif,image/webp', 'textarea', NULL, NULL, NULL, '以英文逗号分隔', 200),
(1021, '文档压缩 MIME 白名单', 'mime_document', 'application/pdf,application/zip,application/x-rar-compressed,application/msword,application/vnd.ms-excel', 'textarea', NULL, NULL, NULL, NULL, 210),
(1021, '视频 MIME 白名单', 'mime_video', 'video/mp4,video/webm,video/quicktime', 'textarea', NULL, NULL, NULL, NULL, 220),
(1021, '证书/密钥 扩展名白名单', 'mime_cert', '.pem,.key,.crt,.cer', 'textarea', NULL, NULL, NULL, '以英文逗号分隔；以 . 开头按扩展名匹配。证书/密钥的 MIME 检测不稳定，统一用扩展名识别', 230);

-- 设置项：1022 UploadLocal 本地存储
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1022, 'URL 前缀', 'local_url_prefix', '/uploads', 'input', NULL, NULL, NULL, '以 / 开头，对应 public 目录下子路径', 10),
(1022, '存储根路径', 'local_root_path', 'uploads', 'input', NULL, NULL, NULL, '相对 public 目录的物理路径', 20),
(1022, '访问域名', 'local_base_url', '', 'input', NULL, NULL, '留空则自动使用 site_url', '访问图片时拼接的完整域名，留空读 site_url', 30);

-- 设置项：1023 UploadOss 阿里云OSS（启用该驱动必须填写）
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1023, 'AccessKeyId', 'oss_access_key_id', '', 'input', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', NULL, NULL, 10),
(1023, 'AccessKeySecret', 'oss_access_key_secret', '', 'password', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', NULL, NULL, 20),
(1023, 'Bucket', 'oss_bucket', '', 'input', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', NULL, NULL, 30),
(1023, 'Endpoint', 'oss_endpoint', '', 'input', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', 'oss-cn-hangzhou.aliyuncs.com', NULL, 40),
(1023, '访问域名', 'oss_url_prefix', '', 'input', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', 'https://xxx.oss-cn-hangzhou.aliyuncs.com', NULL, 50);

-- 设置项：1024 UploadCos 腾讯云COS（启用该驱动必须填写）
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1024, 'SecretId', 'cos_secret_id', '', 'input', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', NULL, NULL, 10),
(1024, 'SecretKey', 'cos_secret_key', '', 'password', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', NULL, NULL, 20),
(1024, 'Bucket', 'cos_bucket', '', 'input', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', 'examplebucket-1250000000', NULL, 30),
(1024, 'Region', 'cos_region', '', 'input', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', 'ap-shanghai', NULL, 40),
(1024, '访问域名', 'cos_url_prefix', '', 'input', NULL, '[{"type":"required","message":"启用该驱动必须填写"}]', 'https://examplebucket-1250000000.cos.ap-shanghai.myqcloud.com', NULL, 50);

-- 设置项：1031 WechatMiniProgram 微信小程序
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1031, '小程序 AppID', 'wechat_mini_appid', '', 'input', NULL, NULL, NULL, NULL, 10),
(1031, '小程序 AppSecret', 'wechat_mini_secret', '', 'password', NULL, NULL, NULL, NULL, 20),
(1031, '小程序名称', 'wechat_mini_name', '', 'input', NULL, NULL, NULL, '显示在授权登录弹窗、分享场景', 30),
(1031, '小程序授权页 Logo', 'wechat_mini_auth_logo', '', 'image', NULL, NULL, NULL, '授权登录弹窗展示的品牌 logo，推荐 1:1，建议 144×144 PNG，<50KB', 40),
(1031, '发货管理', 'wechat_mini_shipping_enabled', '0', 'switch', NULL, NULL, NULL, '部分类目需要，开启后对接发货接口', 50),
(1031, '强制获取手机号', 'wechat_mini_force_mobile', '0', 'switch', NULL, NULL, NULL, '开启后小程序首次登录必须授权手机号（getPhoneNumber），用于跨端账号合并；关闭后走"绑定手机号"中间步骤', 60),
(1031, '强制获取头像昵称', 'wechat_mini_force_userinfo', '0', 'switch', NULL, NULL, NULL, '开启后必须用户主动选取头像与昵称（chooseAvatar + nickname 输入框）；关闭后允许默认昵称', 70);

-- 设置项：1032 WechatOffiAccount 微信公众号
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1032, '公众号 AppID', 'wechat_offi_appid', '', 'input', NULL, NULL, NULL, NULL, 10),
(1032, '公众号 AppSecret', 'wechat_offi_secret', '', 'password', NULL, NULL, NULL, NULL, 20),
(1032, 'Token', 'wechat_offi_token', '', 'input', NULL, NULL, NULL, '接入微信服务器验证用', 30),
(1032, 'EncodingAESKey', 'wechat_offi_aes_key', '', 'password', NULL, NULL, NULL, NULL, 40),
(1032, '必须绑定手机号', 'wechat_offi_force_mobile_bind', '0', 'switch', NULL, NULL, NULL, '开启后公众号 OAuth 注册的用户必须走短信验证码绑定手机号（公众号 OAuth 本身无法直接获取手机号）', 50),
(1032, '强制获取头像昵称', 'wechat_offi_force_userinfo', '0', 'switch', NULL, NULL, NULL, '开启后 OAuth scope 使用 snsapi_userinfo 强制获取头像/昵称；关闭后使用 snsapi_base 仅取 openid', 60);

-- 设置项：1041 PaymentBasic 支付基础（总开关）
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1041, '微信支付状态', 'payment_wechat_enabled', '0', 'switch', NULL, NULL, NULL, NULL, 10),
(1041, '余额支付状态', 'payment_balance_enabled', '0', 'switch', NULL, NULL, NULL, '开启后客户端可选择余额支付，订单支付时会扣减用户余额。', 20);

-- 设置项：1042 PaymentWechat 微信支付V3
-- 证书/密钥统一走 secure_upload：文件落到 backend/storage/cert/，不进 public/uploads/
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1042, '商户号 MCHID', 'pay_wechat_mchid', '', 'input', NULL, NULL, NULL, NULL, 10),
(1042, 'APIv3 密钥', 'pay_wechat_api_v3_key', '', 'password', NULL, NULL, NULL, '32 位字符串。来源：商户平台 → 账户中心 → API 安全 → APIv3 密钥 → 修改/查看', 20),
(1042, '商户证书序列号', 'pay_wechat_cert_serial_no', '', 'input', NULL, NULL, NULL, '来源：商户平台 → 账户中心 → API 安全 → 商户 API 证书 → 管理证书页面里的"证书序列号"', 30),
(1042, '商户 API 私钥', 'pay_wechat_private_key', '', 'file', NULL, '[{"type":"secure_upload","value":true},{"type":"accept_types","value":[".pem",".key"]},{"type":"max_size","value":1}]', NULL, '上传申请商户 API 证书时下载的 apiclient_key.pem 文件。证书包压缩内一般有 apiclient_cert.pem 与 apiclient_key.pem 两份，本字段选带 key 的那份。.pem 浏览器/微信打不开，记事本 / VS Code / TextEdit 都可以查看，内容以 -----BEGIN PRIVATE KEY----- 开头。文件仅保存在服务器内部目录 backend/storage/cert/，不会公开。', 40),
(1042, '商户 API 证书', 'pay_wechat_merchant_cert', '', 'file', NULL, '[{"type":"secure_upload","value":true},{"type":"accept_types","value":[".pem",".crt",".cer"]},{"type":"max_size","value":1}]', NULL, '上传 apiclient_cert.pem（与 apiclient_key.pem 同时下载，来源：商户平台 → 账户中心 → API 安全 → API 证书）。内容以 -----BEGIN CERTIFICATE----- 开头。EasyWeChat V3 签名需从该证书解析序列号，缺失会触发 Read the $certificate failed。文件仅落到 backend/storage/cert/。', 45),
(1042, 'V3 平台公钥', 'pay_wechat_platform_public_key', '', 'file', NULL, '[{"type":"secure_upload","value":true},{"type":"accept_types","value":[".pem"]},{"type":"max_size","value":1}]', NULL, '上传 pub_key.pem。下载位置：商户平台 → 账户中心 → API 安全 → 微信支付公钥 → 下载公钥。仅当微信支付公钥已启用时存在；若显示"切换中"请等切换完成后再下载。文件以 -----BEGIN PUBLIC KEY----- 开头。', 50),
(1042, '平台公钥 ID', 'pay_wechat_platform_public_key_id', '', 'input', NULL, NULL, NULL, '形如 PUB_KEY_ID_xxxxxxxx。来源：商户平台 → 账户中心 → API 安全 → 微信支付公钥 → 公钥 ID', 60);

-- 设置项：108 SmsRateLimit 短信频控（由短信配置菜单专属页面使用）
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(108, '验证码有效期(秒)', 'sms_code_ttl', '300', 'number', NULL, '[{"type":"required","message":"请填写验证码有效期"},{"type":"integer","message":"验证码有效期必须是整数"},{"type":"min","value":30,"message":"验证码有效期不能小于 30 秒"},{"type":"max","value":3600,"message":"验证码有效期不能大于 3600 秒"}]', NULL, '建议 300 秒（5 分钟），范围 30 ~ 3600', 10),
(108, '同手机号 24h 上限', 'sms_rate_mobile_daily', '5', 'number', NULL, '[{"type":"required","message":"请填写手机号日上限"},{"type":"integer","message":"手机号日上限必须是整数"},{"type":"min","value":1,"message":"手机号日上限不能小于 1 次"},{"type":"max","value":100,"message":"手机号日上限不能大于 100 次"}]', NULL, '同一手机号 24 小时内最多发送次数', 20),
(108, '同 IP 每分钟上限', 'sms_rate_ip_minute', '3', 'number', NULL, '[{"type":"required","message":"请填写 IP 分钟上限"},{"type":"integer","message":"IP 分钟上限必须是整数"},{"type":"min","value":1,"message":"IP 分钟上限不能小于 1 次"},{"type":"max","value":100,"message":"IP 分钟上限不能大于 100 次"}]', NULL, '同一 IP 每分钟最多发送次数', 30);

-- 设置项：105 ClientConfig 客户端配置
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(105, '客户端站点名称', 'client_site_name', 'Mall Base', 'input', NULL, NULL, NULL, NULL, 10),
(105, '客户端图标', 'client_logo', '/static/client/logo.png', 'image', NULL, NULL, NULL, '推荐 1:1，建议 512×512 PNG 透明，<200KB', 20),
(105, '启动屏图', 'client_launch_image', '/static/client/launch.png', 'image', NULL, NULL, NULL, 'App 启动全屏图，推荐 9:16，建议 1080×2340 JPG，<500KB', 30),
(105, '启用启动页', 'client_splash_enabled', '1', 'switch', NULL, NULL, NULL, '关闭后客户端不再展示启动页', 35),
(105, '启动页时长(ms)', 'client_splash_duration', '3000', 'number', NULL, NULL, NULL, '启动页自动关闭倒计时，单位毫秒，建议 2000-5000', 40),
(105, '分享默认标题', 'client_share_title', '', 'input', NULL, NULL, NULL, NULL, 50),
(105, '分享默认简介', 'client_share_desc', '', 'input', NULL, NULL, NULL, NULL, 60),
(105, '分享默认封面', 'client_share_cover', '/static/client/share-cover.png', 'image', NULL, NULL, NULL, '推荐 5:4，建议 500×400 PNG/JPG，<200KB', 70),
(105, '客服手机号', 'client_customer_service_phone', '', 'input', NULL, '[{"type":"phone","message":"请输入正确的客服手机号"}]', '请输入客服手机号', '客户端“联系客服”入口拨打的手机号，留空时前端提示未配置', 75),
(105, '显示快捷加购按钮', 'client_goods_card_show_cart_button', '1', 'switch', NULL, NULL, NULL, '控制客户端首页商品组和商品列表页快捷加购按钮', 80),
(105, '显示销量', 'client_goods_card_show_sales', '1', 'switch', NULL, NULL, NULL, '控制客户端商品卡片和商品列表销量展示', 90),
(105, '显示市场价', 'client_goods_card_show_market_price', '1', 'switch', NULL, NULL, NULL, '控制客户端商品卡片划线市场价展示', 100),
(105, '显示商品副标题', 'client_goods_card_show_subtitle', '1', 'switch', NULL, NULL, NULL, '控制客户端商品卡片副标题展示', 110),
(105, '显示商品角标', 'client_goods_card_show_badge', '1', 'switch', NULL, NULL, NULL, '控制客户端商品列表推荐/新品/热卖角标展示', 120),
(105, '商品角标样式', 'client_goods_badge_config', '{"new":{"text":"新品"},"hot":{"text":"热卖"},"recommend":{"text":"推荐"},"style":{"backgroundColor":"","textColor":"","fontSize":20,"height":36,"paddingX":14,"borderRadius":999}}', 'json', NULL, NULL, NULL, '客户端商品卡片角标文案、颜色与尺寸配置；颜色留空时跟随主题', 125),
(105, '商品保障', 'client_goods_guarantees', '[{"title":"正品保障","desc":"平台严选商品来源","icon":"shield"},{"title":"极速发货","desc":"现货商品优先出库","icon":"truck"},{"title":"七天无理由","desc":"符合条件可无理由退货","icon":"refresh"},{"title":"售后无忧","desc":"订单售后进度可追踪","icon":"service"}]', 'json', NULL, NULL, NULL, '客户端商品详情页保障说明，JSON 数组', 130),
(105, '显示搜索历史', 'client_search_history_enabled', '1', 'switch', NULL, NULL, NULL, '控制客户端搜索页是否展示本机搜索历史', 150),
(105, '显示快捷筛选', 'client_search_quick_filter_enabled', '1', 'switch', NULL, NULL, NULL, '控制客户端搜索页是否展示快捷筛选入口', 160),
(105, '快捷筛选入口', 'client_search_quick_filters', '["is_new","is_hot","is_recommend","category"]', 'json', NULL, NULL, NULL, '客户端搜索页快捷筛选固定入口选择结果', 170),
(105, '显示热门搜索', 'client_search_hot_enabled', '1', 'switch', NULL, NULL, NULL, '控制客户端搜索页是否展示热门搜索', 180),
(105, '显示常用分类', 'client_search_category_enabled', '1', 'switch', NULL, NULL, NULL, '控制客户端搜索页是否展示常用分类', 190),
(105, '常用分类数据', 'client_search_category_ids', '[]', 'json', NULL, NULL, NULL, '客户端搜索页手动选择的常用分类 ID 数组', 200),
(105, '关于我们', 'client_about_content', '', 'editor', NULL, NULL, NULL, NULL, 210),
(105, '用户协议', 'client_agreement', '', 'editor', NULL, NULL, NULL, NULL, 220),
(105, '隐私政策', 'client_privacy', '', 'editor', NULL, NULL, NULL, NULL, 230),
(105, '平台规则', 'client_platform_rules', '', 'editor', NULL, NULL, NULL, NULL, 240),
(105, '售后政策', 'client_after_sale_policy', '', 'editor', NULL, NULL, NULL, '仅作为客户端展示文案，不影响真实售后规则', 250),
(105, '允许用户自选主题', 'client_theme_user_select_enabled', '1', 'switch', NULL, NULL, NULL, '开启后用户选择优先；关闭后管理员指定主题强制生效', 300),
(105, '管理员指定主题模式', 'client_theme_admin_mode', 'system', 'select', '[{"label":"跟随系统","value":"system"},{"label":"浅色","value":"light"},{"label":"深色","value":"dark"},{"label":"自定义","value":"custom"}]', NULL, NULL, '管理员统一指定的客户端主题模式', 310),
(105, '管理员指定自定义主题ID', 'client_theme_admin_theme_id', '', 'input', NULL, NULL, NULL, '仅管理员指定主题模式为自定义时有效', 320);

-- 设置项：106 OrderConfig 订单配置
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(106, '待支付超时(分钟)', 'order_pending_pay_timeout_minutes', '30', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":1,"message":"必须大于 0"}]', NULL, '订单创建后超过该分钟数仍未支付时，定时任务自动关闭订单并回滚库存', 10),
(106, '自动确认收货(天)', 'order_auto_receive_days', '7', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":1,"message":"必须大于 0"}]', NULL, '订单发货后超过该天数未确认收货时，定时任务自动确认收货', 20);

-- 设置项：109 PointsConfig 积分配置
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(109, '启用积分功能', 'points_enabled', '1', 'switch', NULL, NULL, NULL, '关闭后客户端和商品编辑不展示积分入口，并停止新发放和新抵扣；历史返还、回收和释放继续处理', 10),
(109, '启用积分赠送', 'points_reward_enabled', '1', 'switch', NULL, NULL, NULL, '关闭后订单完成不再产生新的赠送积分', 20),
(109, '积分冻结天数', 'points_reward_freeze_days', '7', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":0,"message":"不能小于 0"}]', NULL, '订单完成后赠送积分先冻结，超过该天数后释放为可用积分', 30),
(109, '启用积分抵扣', 'points_deduction_enabled', '1', 'switch', NULL, NULL, NULL, '开启后客户端确认订单页可使用可用积分抵扣普通订单金额', 40),
(109, '积分抵扣比例', 'points_deduction_points_per_yuan', '100', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":1,"message":"必须大于 0"}]', NULL, '多少积分抵扣 1 元订单金额', 50),
(109, '积分抵扣上限比例', 'points_deduction_max_percent', '50', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":0,"message":"不能小于 0"},{"type":"max","value":100,"message":"不能大于 100"}]', NULL, '单笔订单商品金额最多可用积分抵扣的比例，可设为 100 表示允许全额抵扣，0 表示不允许抵扣', 60);

-- 设置项：110 MemberConfig 会员配置
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(110, '启用会员功能', 'member_enabled', '0', 'switch', NULL, NULL, NULL, '关闭后客户端不展示会员入口，后续会员权益不参与订单计算', 10),
(110, '成长值比例', 'member_growth_points_per_yuan', '1', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":0,"message":"不能小于 0"}]', NULL, '每实付 1 元累计的成长值，0 表示不累计', 20);

-- 设置项：111 DistributionConfig 分销基础设置
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(111, '启用分销功能', 'distribution_enabled', '1', 'switch', NULL, NULL, NULL, '关闭后客户端不展示分销入口，并停止新绑定、新佣金生成和新提现申请；历史佣金结算与扣回继续处理', 10),
(111, '分销员开通方式', 'distributor_open_mode', 'manual', 'select', '[{"label":"后台手动开通","value":"manual"},{"label":"用户申请审核","value":"apply"},{"label":"人人分销","value":"everyone"},{"label":"满额自动开通","value":"amount"}]', '[{"type":"required","message":"请选择分销员开通方式"}]', NULL, '决定客户端是否可申请或自动获得分销员资格', 15),
(111, '自动开通等级ID', 'auto_open_level_id', '1', 'number', NULL, '[{"type":"required","message":"请填写自动开通等级ID"},{"type":"integer","message":"等级ID必须是整数"},{"type":"min","value":1,"message":"不能小于 1"}]', NULL, '申请通过、人人分销、满额开通默认使用的分销员等级', 18),
(111, '启用二级分佣', 'second_level_enabled', '0', 'switch', NULL, NULL, NULL, '默认关闭。开启后才按二级佣金比例生成二级分佣', 20),
(111, '自购返佣', 'self_purchase_enabled', '0', 'switch', NULL, NULL, NULL, '开启后分销员自己下单可按一级比例返佣，默认关闭', 30),
(111, '结算等待天数', 'settlement_days', '7', 'number', NULL, '[{"type":"required","message":"请填写结算等待天数"},{"type":"integer","message":"结算等待天数必须是整数"},{"type":"min","value":0,"message":"不能小于 0"},{"type":"max","value":365,"message":"不能大于 365"}]', NULL, '订单完成后等待多少天释放佣金；0 表示订单完成后立即结算', 40),
(111, '最低提现金额(分)', 'min_withdraw_cents', '10000', 'number', NULL, '[{"type":"required","message":"请填写最低提现金额"},{"type":"integer","message":"最低提现金额必须是整数"},{"type":"min","value":0,"message":"不能小于 0"}]', NULL, '单位：分。10000 表示 100 元', 50),
(111, '一级默认佣金比例(%)', 'global_first_rate', '5.00', 'number', NULL, '[{"type":"required","message":"请填写一级默认佣金比例"},{"type":"min","value":0,"message":"不能小于 0"},{"type":"max","value":100,"message":"不能大于 100"}]', NULL, '未命中特定规则时使用，支持两位小数', 60),
(111, '二级默认佣金比例(%)', 'global_second_rate', '0.00', 'number', NULL, '[{"type":"required","message":"请填写二级默认佣金比例"},{"type":"min","value":0,"message":"不能小于 0"},{"type":"max","value":100,"message":"不能大于 100"}]', NULL, '启用二级分佣后生效，未命中特定规则时使用，支持两位小数', 70),
(111, '满额开通门槛(分)', 'amount_open_threshold_cents', '0', 'number', NULL, '[{"type":"required","message":"请填写满额开通门槛"},{"type":"integer","message":"满额开通门槛必须是整数"},{"type":"min","value":0,"message":"不能小于 0"}]', NULL, '仅开通方式为满额自动开通时生效；单位：分', 80),
(111, '启用固定邀请奖励', 'invite_reward_enabled', '0', 'switch', NULL, NULL, NULL, '默认关闭；只奖励直接邀请人，不做团队层级滚动奖励', 90),
(111, '固定邀请奖励金额(分)', 'invite_reward_amount_cents', '0', 'number', NULL, '[{"type":"required","message":"请填写固定邀请奖励金额"},{"type":"integer","message":"固定邀请奖励金额必须是整数"},{"type":"min","value":0,"message":"不能小于 0"}]', NULL, '单位：分；被邀请人首单支付后只发放一次，通过关系表状态保证幂等', 110),
(111, '启用分享归因', 'attribution_enabled', '1', 'switch', NULL, NULL, NULL, '开启后分享链接和海报参数会记录到绑定关系与佣金快照', 120);

-- 设置项：107 RefundConfig 售后配置
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(107, '售后期限(天)', 'refund_after_sale_days', '0', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":0,"message":"不能小于 0"}]', NULL, '订单收货后多少天内可申请售后；设置为 0 表示不限制', 10),
(107, '退货收货人姓名', 'refund_return_receiver_name', '', 'input', NULL, NULL, NULL, NULL, 20),
(107, '退货收货人电话', 'refund_return_receiver_phone', '', 'input', NULL, NULL, NULL, NULL, 30),
(107, '退货收货人地址', 'refund_return_receiver_address', '', 'textarea', NULL, NULL, NULL, NULL, 40),
(107, '售后原因选项', 'refund_reason_options', '[{"value":"MISTAKEN_ORDER","label":"订单拍错"},{"value":"QUALITY_ISSUE","label":"商品质量问题"},{"value":"NO_LONGER_WANTED","label":"不想要了"},{"value":"OTHER","label":"其他"}]', 'option_list', NULL, NULL, '请输入原因名称', '客户端售后申请页与后端校验共用；后台只维护原因名称，编码由系统自动维护', 50),
(107, '常用驳回原因', 'refund_reject_reason_options', '[{"value":"商品已签收，不符合退款条件","label":"商品已签收，不符合退款条件"},{"value":"买家申请理由不成立","label":"买家申请理由不成立"},{"value":"已超过售后期限","label":"已超过售后期限"},{"value":"需提供相关凭证后重新申请","label":"需提供相关凭证后重新申请"}]', 'option_list', NULL, NULL, '请输入驳回原因', '后台驳回售后申请时快捷选择，买家可见；可继续手动补充详细说明', 60);

UPDATE `mb_setting_group` SET `is_system` = 1;
UPDATE `mb_setting` SET `is_system` = 1;
