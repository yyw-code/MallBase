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
  `placeholder` varchar(255) DEFAULT NULL COMMENT '输入提示',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注说明',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_code` (`group_id`, `code`),
  KEY `idx_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设置项表';

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
(103, 100, 0, '微信配置', 'WechatConfig', 'lucide:message-circle', '微信小程序、公众号与开放平台接入参数', 30, 'tab', 1),
(1031, 103, 0, '微信小程序', 'WechatMiniProgram', NULL, '小程序 AppID、AppSecret 与授权品牌资源', 10, 'page', 1),
(1032, 103, 0, '微信公众号', 'WechatOffiAccount', NULL, '公众号 AppID、AppSecret 与消息加解密参数', 20, 'page', 1),
(1033, 103, 0, '微信开放平台', 'WechatOpenPlatform', NULL, '开放平台主体绑定标记，用于跨小程序/公众号 unionid 互通', 30, 'page', 1);

-- 二级分组：支付配置（选项卡）及其子页面
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(104, 100, 0, '支付配置', 'PaymentConfig', 'lucide:credit-card', '微信支付与支付宝等支付渠道参数', 40, 'tab', 1),
(1041, 104, 0, '基础', 'PaymentBasic', NULL, '各支付渠道的启用状态总开关', 10, 'page', 1),
(1042, 104, 0, '微信支付V3', 'PaymentWechat', NULL, '微信支付 V3 商户号、APIv3 密钥与证书参数', 20, 'page', 1),
(1043, 104, 0, '支付宝', 'PaymentAlipay', NULL, '支付宝 RSA2 证书模式接入参数', 30, 'page', 1);

-- 二级分组：客户端配置（单页面）
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(105, 100, 0, '客户端配置', 'ClientConfig', 'lucide:smartphone', 'App/H5 客户端品牌、启动屏、分享与协议等配置', 50, 'page', 1);

-- SystemConfig 子页面：订单与售后配置（作为系统配置页签）
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(106, 101, 0, '订单配置', 'OrderConfig', NULL, '待支付超时、自动确认收货等订单流程配置', 30, 'page', 1),
(107, 101, 0, '售后配置', 'RefundConfig', NULL, '售后期限、退货收货信息与售后原因配置', 40, 'page', 1);

-- 短信频控配置使用系统表单数据源，但由「短信配置 → 短信频控」专属菜单与权限入口展示
INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`, `permission_parent_code`, `permission_path`, `permission_component`, `permission_status`) VALUES
(108, 0, 0, '短信频控', 'SmsRateLimit', 'lucide:gauge', '验证码有效期与发送频控阈值', 80, 'page', 1, 'SmsConfig', '/sms/config', '/sms/config/index', 1);

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
(1021, '图片 MIME 白名单', 'mime_image', 'image/jpeg,image/png,image/gif,image/webp', 'textarea', NULL, NULL, NULL, '以英文逗号分隔', 20),
(1021, '文档压缩 MIME 白名单', 'mime_document', 'application/pdf,application/zip,application/x-rar-compressed,application/msword,application/vnd.ms-excel', 'textarea', NULL, NULL, NULL, NULL, 30),
(1021, '视频 MIME 白名单', 'mime_video', 'video/mp4,video/webm,video/quicktime', 'textarea', NULL, NULL, NULL, NULL, 40),
(1021, '证书/密钥 扩展名白名单', 'mime_cert', '.pem,.key,.crt,.cer', 'textarea', NULL, NULL, NULL, '以英文逗号分隔；以 . 开头按扩展名匹配。证书/密钥的 MIME 检测不稳定，统一用扩展名识别', 45),
(1021, '单证书最大体积(MB)', 'upload_cert_max_size', '1', 'number', NULL, NULL, NULL, NULL, 145),
(1021, '单图最大体积(MB)', 'upload_image_max_size', '2', 'number', NULL, NULL, NULL, NULL, 50),
(1021, '多图单张最大体积(MB)', 'upload_images_max_size', '5', 'number', NULL, NULL, NULL, NULL, 60),
(1021, '多图最大张数', 'upload_images_max_count', '9', 'number', NULL, NULL, NULL, NULL, 70),
(1021, '单文件最大体积(MB)', 'upload_file_max_size', '10', 'number', NULL, NULL, NULL, NULL, 80),
(1021, '多文件单个最大体积(MB)', 'upload_files_max_size', '10', 'number', NULL, NULL, NULL, NULL, 90),
(1021, '多文件最大个数', 'upload_files_max_count', '5', 'number', NULL, NULL, NULL, NULL, 100),
(1021, '单视频最大体积(MB)', 'upload_video_max_size', '200', 'number', NULL, NULL, NULL, NULL, 110),
(1021, '多视频单个最大体积(MB)', 'upload_videos_max_size', '200', 'number', NULL, NULL, NULL, NULL, 120),
(1021, '多视频最大个数', 'upload_videos_max_count', '5', 'number', NULL, NULL, NULL, NULL, 130);

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

-- 设置项：1033 WechatOpenPlatform 微信开放平台
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1033, '开放平台 AppID', 'wechat_open_appid', '', 'input', NULL, NULL, NULL, '占位字段，本期不调用开放平台 API；接入 PC 扫码或移动 APP 时使用', 10),
(1033, '开放平台 AppSecret', 'wechat_open_secret', '', 'password', NULL, NULL, NULL, '占位字段，配合 AppID 同时填写', 20),
(1033, '已绑定开放平台主体', 'wechat_open_bound', '0', 'switch', NULL, NULL, NULL, '若小程序与公众号已绑定到同一开放平台主体，开启此项以信任 unionid 进行跨端账号合并；未绑定时关闭，避免 unionid 误用', 30);

-- 设置项：1041 PaymentBasic 支付基础（总开关）
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1041, '微信支付状态', 'payment_wechat_enabled', '0', 'switch', NULL, NULL, NULL, NULL, 10),
(1041, '支付宝状态', 'payment_alipay_enabled', '0', 'switch', NULL, NULL, NULL, NULL, 20),
(1041, '余额支付状态', 'payment_balance_enabled', '0', 'switch', NULL, NULL, NULL, '开启后客户端可选择余额支付，订单支付时会扣减用户余额。', 30);

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

-- 设置项：1043 PaymentAlipay 支付宝（RSA2 证书模式）
-- 应用私钥与三份公钥/根证书统一走 secure_upload，落到 backend/storage/cert/
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(1043, '应用 AppID', 'pay_alipay_app_id', '', 'input', NULL, NULL, NULL, NULL, 10),
(1043, '签名算法', 'pay_alipay_sign_type', 'RSA2', 'select', '[{"label":"RSA2","value":"RSA2"}]', NULL, NULL, NULL, 20),
(1043, '应用私钥', 'pay_alipay_app_private_key', '', 'file', NULL, '[{"type":"secure_upload","value":true},{"type":"accept_types","value":[".pem",".key"]},{"type":"max_size","value":1}]', NULL, '上传支付宝开放平台生成的应用私钥文件（如 appPrivateKey.pem / 应用私钥RSA2.pem）。内容以 -----BEGIN RSA PRIVATE KEY----- 或 -----BEGIN PRIVATE KEY----- 开头。文件仅保存在服务器内部目录 backend/storage/cert/，不会公开。', 30),
(1043, '应用公钥证书', 'pay_alipay_app_cert_public_key', '', 'file', NULL, '[{"type":"secure_upload","value":true},{"type":"accept_types","value":[".crt",".cer",".pem"]},{"type":"max_size","value":1}]', NULL, '上传 appCertPublicKey_xxx.crt（支付宝控制台 → 应用 → 接口加签方式（公钥证书）→ 生成并下载）。文件保存于服务器内部，不会公开。', 40),
(1043, '支付宝公钥证书', 'pay_alipay_alipay_cert_public_key', '', 'file', NULL, '[{"type":"secure_upload","value":true},{"type":"accept_types","value":[".crt",".cer",".pem"]},{"type":"max_size","value":1}]', NULL, '上传 alipayCertPublicKey_RSA2.crt（支付宝控制台同一位置同时下载）。文件保存于服务器内部，不会公开。', 50),
(1043, '支付宝根证书', 'pay_alipay_alipay_root_cert', '', 'file', NULL, '[{"type":"secure_upload","value":true},{"type":"accept_types","value":[".crt",".cer",".pem"]},{"type":"max_size","value":1}]', NULL, '上传 alipayRootCert.crt（支付宝控制台同一位置一同提供）。文件保存于服务器内部，不会公开。', 60),
(1043, '网关地址', 'pay_alipay_gateway', 'https://openapi.alipay.com/gateway.do', 'input', NULL, NULL, NULL, NULL, 70);

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
(105, '启用启动页', 'client_splash_enabled', '1', 'switch', NULL, NULL, NULL, '关闭后客户端不再展示启动页', 110),
(105, '启动页时长(ms)', 'client_splash_duration', '3000', 'input', NULL, NULL, NULL, '启动页自动关闭倒计时，单位毫秒，建议 2000-5000', 120),
(105, '分享默认标题', 'client_share_title', '', 'input', NULL, NULL, NULL, NULL, 50),
(105, '分享默认简介', 'client_share_desc', '', 'input', NULL, NULL, NULL, NULL, 60),
(105, '分享默认封面', 'client_share_cover', '/static/client/share-cover.png', 'image', NULL, NULL, NULL, '推荐 5:4，建议 500×400 PNG/JPG，<200KB', 70),
(105, '商品保障', 'client_goods_guarantees', '[{"title":"正品保障","desc":"平台严选商品来源","icon":"shield"},{"title":"极速发货","desc":"现货商品优先出库","icon":"truck"},{"title":"七天无理由","desc":"符合条件可无理由退货","icon":"refresh"},{"title":"售后无忧","desc":"订单售后进度可追踪","icon":"service"}]', 'json', NULL, NULL, NULL, '客户端商品详情页保障说明，JSON 数组', 80),
(105, '用户协议', 'client_agreement', '', 'editor', NULL, NULL, NULL, NULL, 90),
(105, '隐私政策', 'client_privacy', '', 'editor', NULL, NULL, NULL, NULL, 100),
(105, '允许用户自选主题', 'client_theme_user_select_enabled', '1', 'switch', NULL, NULL, NULL, '开启后用户选择优先；关闭后管理员指定主题强制生效', 130),
(105, '管理员指定主题模式', 'client_theme_admin_mode', 'system', 'select', '[{"label":"跟随系统","value":"system"},{"label":"浅色","value":"light"},{"label":"深色","value":"dark"},{"label":"自定义","value":"custom"}]', NULL, NULL, '管理员统一指定的客户端主题模式', 140),
(105, '管理员指定自定义主题ID', 'client_theme_admin_theme_id', '', 'input', NULL, NULL, NULL, '仅管理员指定主题模式为自定义时有效', 150);

-- 设置项：106 OrderConfig 订单配置
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(106, '待支付超时(分钟)', 'order_pending_pay_timeout_minutes', '30', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":1,"message":"必须大于 0"}]', NULL, '订单创建后超过该分钟数仍未支付时，定时任务自动关闭订单并回滚库存', 10),
(106, '自动确认收货(天)', 'order_auto_receive_days', '7', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":1,"message":"必须大于 0"}]', NULL, '订单发货后超过该天数未确认收货时，定时任务自动确认收货', 20);

-- 设置项：107 RefundConfig 售后配置
INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(107, '售后期限(天)', 'refund_after_sale_days', '0', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":0,"message":"不能小于 0"}]', NULL, '订单收货后多少天内可申请售后；设置为 0 表示不限制', 10),
(107, '退货收货人姓名', 'refund_return_receiver_name', '', 'input', NULL, NULL, NULL, NULL, 20),
(107, '退货收货人电话', 'refund_return_receiver_phone', '', 'input', NULL, NULL, NULL, NULL, 30),
(107, '退货收货人地址', 'refund_return_receiver_address', '', 'textarea', NULL, NULL, NULL, NULL, 40),
(107, '售后原因选项', 'refund_reason_options', '[{"value":"MISTAKEN_ORDER","label":"订单拍错"},{"value":"QUALITY_ISSUE","label":"商品质量问题"},{"value":"NO_LONGER_WANTED","label":"不想要了"},{"value":"OTHER","label":"其他"}]', 'option_list', NULL, NULL, '请输入原因名称', '客户端售后申请页与后端校验共用；后台只维护原因名称，编码由系统自动维护', 50),
(107, '常用驳回原因', 'refund_reject_reason_options', '[{"value":"商品已签收，不符合退款条件","label":"商品已签收，不符合退款条件"},{"value":"买家申请理由不成立","label":"买家申请理由不成立"},{"value":"已超过售后期限","label":"已超过售后期限"},{"value":"需提供相关凭证后重新申请","label":"需提供相关凭证后重新申请"}]', 'option_list', NULL, NULL, '请输入驳回原因', '后台驳回售后申请时快捷选择，买家可见；可继续手动补充详细说明', 60);
