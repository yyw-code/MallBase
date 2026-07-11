-- ============================================
-- 客户端装修系统数据库表结构
-- 表前缀：mb_
-- 包含：页面分类、页面库、装修方案、方案快照、主题方案、主题策略
-- ============================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `mb_client_page_category`;
CREATE TABLE `mb_client_page_category` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '分类ID',
  `name` varchar(80) NOT NULL COMMENT '分类名称',
  `description` varchar(255) DEFAULT NULL COMMENT '分类描述',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否系统分类',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0禁用，1启用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户端页面分类';

DROP TABLE IF EXISTS `mb_client_page`;
CREATE TABLE `mb_client_page` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '页面ID',
  `name` varchar(80) NOT NULL COMMENT '页面名称',
  `path` varchar(255) NOT NULL COMMENT 'UniApp 页面路径',
  `page_type` varchar(30) NOT NULL DEFAULT 'page' COMMENT '页面类型：tab/page/subpackage',
  `category_id` int(11) unsigned NOT NULL DEFAULT 9 COMMENT '页面分类ID',
  `package_root` varchar(120) DEFAULT NULL COMMENT '分包 root，主包为空',
  `need_login` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否需要登录',
  `source` varchar(20) NOT NULL DEFAULT 'manual' COMMENT '来源：auto/manual/system',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0禁用，1启用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_path` (`path`),
  KEY `idx_page_type` (`page_type`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户端页面库';

DROP TABLE IF EXISTS `mb_client_decoration_scheme`;
CREATE TABLE `mb_client_decoration_scheme` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '方案ID',
  `type` varchar(20) NOT NULL COMMENT '方案类型：home/floating/profile/tabbar',
  `name` varchar(80) NOT NULL COMMENT '方案名称',
  `description` varchar(255) DEFAULT NULL COMMENT '方案说明',
  `schema` json NOT NULL COMMENT '装修配置 JSON',
  `tabbar_mode` varchar(20) NOT NULL DEFAULT 'native' COMMENT '底部导航模式：native/custom',
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否系统默认',
  `is_active` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否当前启用',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0禁用，1启用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_type_active` (`type`, `is_active`),
  KEY `idx_type_system` (`type`, `is_system`),
  KEY `idx_status` (`status`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户端装修方案';

DROP TABLE IF EXISTS `mb_client_decoration_snapshot`;
CREATE TABLE `mb_client_decoration_snapshot` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '快照ID',
  `scheme_id` int(11) unsigned NOT NULL COMMENT '方案ID',
  `type` varchar(20) NOT NULL COMMENT '方案类型',
  `name` varchar(80) NOT NULL COMMENT '快照名称',
  `schema` json NOT NULL COMMENT '快照配置 JSON',
  `tabbar_mode` varchar(20) NOT NULL DEFAULT 'native' COMMENT '底部导航模式',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_scheme_id` (`scheme_id`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户端装修方案快照';

DROP TABLE IF EXISTS `mb_client_theme`;
CREATE TABLE `mb_client_theme` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主题ID',
  `name` varchar(80) NOT NULL COMMENT '主题名称',
  `type` varchar(20) NOT NULL DEFAULT 'custom' COMMENT '主题类型：light/dark/custom',
  `tokens` json NOT NULL COMMENT '主题变量 JSON',
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否系统主题',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '状态：0草稿，1已发布',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_system` (`is_system`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户端主题方案';

DROP TABLE IF EXISTS `mb_client_theme_policy`;
CREATE TABLE `mb_client_theme_policy` (
  `id` tinyint(1) unsigned NOT NULL COMMENT '固定ID：1',
  `allow_user_select` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许用户自选主题',
  `default_mode` varchar(20) NOT NULL DEFAULT 'system' COMMENT '默认模式：system/light/dark/custom',
  `default_theme_id` int(11) unsigned DEFAULT NULL COMMENT '默认自定义主题ID',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户端主题策略';

INSERT INTO `mb_client_page_category`
(`id`, `name`, `description`, `sort`, `is_system`, `status`)
VALUES
(1, '基础页面', '客户端主包、底部导航等基础页面', 10, 1, 1),
(2, '商品页面', '商品列表、详情、搜索等页面', 20, 1, 1),
(3, '内容页面', '文章、内容等页面', 30, 1, 1),
(4, '订单页面', '订单、物流、评价等页面', 40, 1, 1),
(5, '售后页面', '退款、售后等页面', 50, 1, 1),
(6, '会员页面', '登录、资料、地址等会员页面', 60, 1, 1),
(7, '积分页面', '积分、积分商城、兑换记录等页面', 70, 1, 1),
(8, '余额页面', '余额、充值、余额记录等页面', 80, 1, 1),
(9, '其他页面', '未归入固定业务模块的页面', 900, 1, 1);

INSERT INTO `mb_client_page`
(`id`, `name`, `path`, `page_type`, `category_id`, `package_root`, `need_login`, `source`, `remark`, `sort`, `status`)
VALUES
(1, '首页', '/pages/index/index', 'tab', 1, NULL, 0, 'system', '系统默认底部导航页面', 10, 1),
(2, '分类', '/pages/category/index', 'tab', 1, NULL, 0, 'system', '系统默认底部导航页面', 20, 1),
(3, '购物车', '/pages/cart/index', 'tab', 1, NULL, 1, 'system', '系统默认底部导航页面', 30, 1),
(4, '订单', '/pages/order/index', 'tab', 1, NULL, 1, 'system', '系统默认底部导航页面', 40, 1),
(5, '我的', '/pages/profile/index', 'tab', 1, NULL, 1, 'system', '系统默认底部导航页面', 50, 1),
(6, '商品列表', '/pages-sub/goods/list', 'subpackage', 2, 'pages-sub/goods', 0, 'system', '系统内置商品页面', 1110, 1),
(7, '商品详情', '/pages-sub/goods/detail', 'subpackage', 2, 'pages-sub/goods', 0, 'system', '系统内置商品页面', 1120, 1),
(8, '商品评价', '/pages-sub/goods/comments', 'subpackage', 2, 'pages-sub/goods', 0, 'system', '系统内置商品页面', 1130, 1),
(9, '确认订单', '/pages-sub/order/confirm', 'subpackage', 4, 'pages-sub/order', 1, 'system', '系统内置订单页面', 1210, 1),
(10, '订单列表', '/pages-sub/order/list', 'subpackage', 4, 'pages-sub/order', 1, 'system', '系统内置订单页面', 1220, 1),
(11, '订单详情', '/pages-sub/order/detail', 'subpackage', 4, 'pages-sub/order', 1, 'system', '系统内置订单页面', 1230, 1),
(12, '支付结果', '/pages-sub/order/pay-result', 'subpackage', 4, 'pages-sub/order', 1, 'system', '系统内置订单页面', 1240, 1),
(13, '申请退款', '/pages-sub/refund/apply', 'subpackage', 5, 'pages-sub/refund', 1, 'system', '系统内置售后页面', 1310, 1),
(14, '退款列表', '/pages-sub/refund/list', 'subpackage', 5, 'pages-sub/refund', 1, 'system', '系统内置售后页面', 1320, 1),
(15, '退款详情', '/pages-sub/refund/detail', 'subpackage', 5, 'pages-sub/refund', 1, 'system', '系统内置售后页面', 1330, 1),
(16, '登录', '/pages-sub/user/login', 'subpackage', 6, 'pages-sub/user', 0, 'system', '系统内置会员页面', 1410, 1),
(17, '用户协议', '/pages-sub/user/agreement', 'subpackage', 6, 'pages-sub/user', 0, 'system', '系统内置会员页面', 1420, 1),
(18, '绑定手机号', '/pages-sub/user/bind-mobile', 'subpackage', 6, 'pages-sub/user', 1, 'system', '系统内置会员页面', 1430, 1),
(19, '编辑资料', '/pages-sub/user/edit-profile', 'subpackage', 6, 'pages-sub/user', 1, 'system', '系统内置会员页面', 1440, 1),
(20, '修改密码', '/pages-sub/user/change-password', 'subpackage', 6, 'pages-sub/user', 1, 'system', '系统内置会员页面', 1450, 1),
(21, '设置', '/pages-sub/user/settings', 'subpackage', 6, 'pages-sub/user', 1, 'system', '系统内置会员页面', 1460, 1),
(39, '关于我们', '/pages-sub/user/about', 'subpackage', 6, 'pages-sub/user', 0, 'system', '系统内置会员页面', 1470, 1),
(40, '会员等级', '/pages-sub/member/index', 'subpackage', 6, 'pages-sub/member', 1, 'system', '系统内置会员页面', 1480, 1),
(22, '钱包', '/pages-sub/wallet/index', 'subpackage', 8, 'pages-sub/wallet', 1, 'system', '系统内置余额页面', 1510, 1),
(23, '钱包记录', '/pages-sub/wallet/records', 'subpackage', 8, 'pages-sub/wallet', 1, 'system', '系统内置余额页面', 1520, 1),
(24, '余额充值', '/pages-sub/wallet/recharge', 'subpackage', 8, 'pages-sub/wallet', 1, 'system', '系统内置余额页面', 1530, 1),
(25, '积分', '/pages-sub/points/index', 'subpackage', 7, 'pages-sub/points', 1, 'system', '系统内置积分页面', 1540, 1),
(26, '积分记录', '/pages-sub/points/records', 'subpackage', 7, 'pages-sub/points', 1, 'system', '系统内置积分页面', 1550, 1),
(27, '地址列表', '/pages-sub/address/list', 'subpackage', 6, 'pages-sub/address', 1, 'system', '系统内置会员页面', 1610, 1),
(28, '编辑地址', '/pages-sub/address/edit', 'subpackage', 6, 'pages-sub/address', 1, 'system', '系统内置会员页面', 1620, 1),
(29, '搜索', '/pages-sub/search/index', 'subpackage', 2, 'pages-sub/search', 0, 'system', '系统内置商品页面', 1710, 1),
(30, '发布评价', '/pages-sub/review/post', 'subpackage', 4, 'pages-sub/review', 1, 'system', '系统内置订单页面', 1810, 1),
(31, '物流详情', '/pages-sub/logistics/detail', 'subpackage', 4, 'pages-sub/logistics', 1, 'system', '系统内置订单页面', 1910, 1),
(32, '文章列表', '/pages-sub/article/list', 'subpackage', 3, 'pages-sub/article', 0, 'system', '系统内置文章页面', 2010, 1),
(33, '文章详情', '/pages-sub/article/detail', 'subpackage', 3, 'pages-sub/article', 0, 'system', '系统内置文章页面', 2020, 1),
(34, '积分商城', '/pages-sub/points/mall', 'subpackage', 7, 'pages-sub/points', 0, 'system', '系统内置积分页面', 1560, 1),
(35, '积分商品详情', '/pages-sub/points/mall-detail', 'subpackage', 7, 'pages-sub/points', 0, 'system', '系统内置积分页面', 1570, 1),
(36, '确认积分兑换', '/pages-sub/points/exchange-confirm', 'subpackage', 7, 'pages-sub/points', 1, 'system', '系统内置积分页面', 1580, 1),
(37, '积分兑换记录', '/pages-sub/points/exchange-orders', 'subpackage', 7, 'pages-sub/points', 1, 'system', '系统内置积分页面', 1590, 1),
(38, '积分兑换详情', '/pages-sub/points/exchange-detail', 'subpackage', 7, 'pages-sub/points', 1, 'system', '系统内置积分页面', 1600, 1);

INSERT INTO `mb_client_decoration_scheme`
(`id`, `type`, `name`, `description`, `schema`, `tabbar_mode`, `is_system`, `is_active`, `sort`, `status`)
VALUES
(1, 'home', '系统默认首页', '系统内置首页方案，不能修改或删除',
 JSON_OBJECT('pageStyle', JSON_OBJECT('paddingY', 0, 'paddingX', 28), 'components', JSON_ARRAY(
   JSON_OBJECT('id', 'home-search', 'type', 'search', 'title', '搜索框', 'enabled', true, 'sort', 0, 'props', JSON_OBJECT('placeholder', '搜索商品、分类或品牌', 'radius', 36, 'padding', 12, 'paddingY', 12, 'paddingX', 20, 'marginTop', 4, 'marginBottom', 8, 'background', '', 'target_path', '/pages-sub/goods/list', 'widthPercent', 100)),
   JSON_OBJECT('id', 'home-banner', 'type', 'banner', 'title', '轮播图', 'enabled', true, 'sort', 1, 'props', JSON_OBJECT('items', JSON_ARRAY(JSON_OBJECT('image', '1001', 'path', '/pages-sub/goods/list?is_recommend=1', 'title', '夏日好物限时满减'), JSON_OBJECT('image', '1002', 'path', '/pages-sub/goods/list?sort=sales', 'title', '会员精选 每日上新')), 'list', JSON_ARRAY(JSON_OBJECT('image', '1001', 'path', '/pages-sub/goods/list?is_recommend=1', 'title', '夏日好物限时满减'), JSON_OBJECT('image', '1002', 'path', '/pages-sub/goods/list?sort=sales', 'title', '会员精选 每日上新')), 'images', JSON_ARRAY(JSON_OBJECT('image', '1001', 'path', '/pages-sub/goods/list?is_recommend=1', 'title', '夏日好物限时满减'), JSON_OBJECT('image', '1002', 'path', '/pages-sub/goods/list?sort=sales', 'title', '会员精选 每日上新')), 'height', 314, 'radius', 24, 'padding', 0, 'interval', 3000, 'subtitle', 'MALLBASE SELECTED', 'title', '好物限时满减', 'buttonText', '立即查看', 'marginTop', 12, 'marginBottom', 16, 'background', '', 'widthPercent', 100)),
   JSON_OBJECT('id', 'home-nav', 'type', 'navGrid', 'title', '导航宫格', 'enabled', true, 'sort', 2, 'props', JSON_OBJECT('columns', 3, 'items', JSON_ARRAY(JSON_OBJECT('label', '数码', 'title', '数码', 'icon', 'phone', 'image', '1004', 'path', '/pages/category/index'), JSON_OBJECT('label', '美妆', 'title', '美妆', 'icon', 'beauty', 'image', '1005', 'path', '/pages/category/index'), JSON_OBJECT('label', '服饰', 'title', '服饰', 'icon', 'shirt', 'image', '1006', 'path', '/pages/category/index'), JSON_OBJECT('label', '家居', 'title', '家居', 'icon', 'home', 'image', '1007', 'path', '/pages/category/index'), JSON_OBJECT('label', '美食', 'title', '美食', 'icon', 'food', 'image', '1008', 'path', '/pages/category/index'), JSON_OBJECT('label', '运动', 'title', '运动', 'icon', 'sport', 'image', '1009', 'path', '/pages/category/index')), 'radius', 24, 'padding', 20, 'paddingY', 20, 'paddingX', 20, 'marginTop', 4, 'marginBottom', 18, 'background', '', 'widthPercent', 100)),
   JSON_OBJECT('id', 'home-title-recommend', 'type', 'title', 'title', '标题栏', 'enabled', true, 'sort', 3, 'props', JSON_OBJECT('title', '人气推荐', 'sub_title', '严选好物正在热卖', 'more_text', '查看全部', 'more_path', '/pages-sub/goods/list?is_recommend=1', 'title_align', 'left', 'title_bold', true, 'title_italic', false, 'title_font_size', 34, 'title_color', '', 'sub_bold', false, 'sub_italic', false, 'sub_font_size', 22, 'sub_color', '', 'radius', 0, 'padding', 4, 'paddingY', 4, 'paddingX', 30, 'marginTop', 4, 'marginBottom', 8, 'background', '', 'widthPercent', 100)),
   JSON_OBJECT('id', 'home-products', 'type', 'productGroup', 'title', '商品分组', 'enabled', true, 'sort', 4, 'props', JSON_OBJECT('title', '精选好物', 'subtitle', '精选好物实时更新', 'moreText', '查看全部', 'more_path', '/pages-sub/goods/list?is_recommend=1', 'source', 'recommend', 'layout', 'grid', 'limit', 8, 'sort_by', 'default', 'radius', 24, 'padding', 20, 'paddingY', 20, 'paddingX', 20, 'marginTop', 4, 'marginBottom', 24, 'background', '', 'widthPercent', 100))
 )), 'native', 1, 1, 10, 1),
(2, 'profile', '系统默认个人中心', '系统内置个人中心方案，不能修改或删除',
 JSON_OBJECT('pageStyle', JSON_OBJECT('backgroundColorEnd', '', 'backgroundColorStart', '', 'backgroundGradientDirection', 'horizontal', 'backgroundMode', 'color', 'background_image', '', 'padding', 23, 'paddingBottom', 24, 'paddingLeft', 28, 'paddingRight', 28, 'paddingTop', 10, 'paddingX', 28, 'paddingY', 17), 'modules', JSON_ARRAY(
   JSON_OBJECT('id', 'profile-user', 'type', 'userInfo', 'props', JSON_OBJECT('background', '', 'backgroundColorEnd', '', 'backgroundColorStart', '', 'backgroundGradientDirection', 'horizontal', 'backgroundMode', 'color', 'background_image', '', 'borderColor', '', 'borderEnabled', true, 'borderStyle', 'solid', 'borderWidth', 1, 'marginBottom', 0, 'marginLeft', 0, 'marginRight', 0, 'marginTop', 0, 'padding', 0, 'paddingX', 28, 'paddingY', 28, 'radius', 0, 'shadowBlur', 30, 'shadowColor', '#0f172a', 'shadowEnabled', false, 'shadowOffsetX', 0, 'shadowOffsetY', 12, 'shadowOpacity', 14, 'shadowSpread', 0, 'widthPercent', 100, 'show_mobile', true)),
   JSON_OBJECT('id', 'profile-order', 'type', 'orderEntry', 'props', JSON_OBJECT('background', '', 'backgroundColorEnd', '', 'backgroundColorStart', '', 'backgroundGradientDirection', 'horizontal', 'backgroundMode', 'color', 'background_image', '', 'borderColor', '', 'borderEnabled', true, 'borderStyle', 'solid', 'borderWidth', 1, 'marginBottom', 0, 'marginLeft', 0, 'marginRight', 0, 'marginTop', 0, 'padding', 0, 'paddingX', 28, 'paddingY', 28, 'radius', 20, 'shadowBlur', 30, 'shadowColor', '#0f172a', 'shadowEnabled', false, 'shadowOffsetX', 0, 'shadowOffsetY', 12, 'shadowOpacity', 14, 'shadowSpread', 0, 'widthPercent', 100, 'title', '我的订单', 'display', 'grid', 'items', JSON_ARRAY(JSON_OBJECT('title', '待付款', 'label', '待付款', 'image', 'static/decorate/profile-order-pay.svg', 'path', '/pages-sub/order/list?status=10'), JSON_OBJECT('title', '待发货', 'label', '待发货', 'image', 'static/decorate/profile-order-ship.svg', 'path', '/pages-sub/order/list?status=20'), JSON_OBJECT('title', '待收货', 'label', '待收货', 'image', 'static/decorate/profile-order-receive.svg', 'path', '/pages-sub/order/list?status=30'), JSON_OBJECT('title', '退款售后', 'label', '退款售后', 'image', 'static/decorate/profile-order-refund.svg', 'path', '/pages-sub/refund/list')))),
   JSON_OBJECT('id', 'profile-wallet', 'type', 'walletEntry', 'props', JSON_OBJECT('background', '', 'backgroundColorEnd', '', 'backgroundColorStart', '', 'backgroundGradientDirection', 'horizontal', 'backgroundMode', 'color', 'background_image', '', 'borderColor', '', 'borderEnabled', true, 'borderStyle', 'solid', 'borderWidth', 1, 'marginBottom', 0, 'marginLeft', 0, 'marginRight', 0, 'marginTop', 0, 'padding', 0, 'paddingX', 28, 'paddingY', 28, 'radius', 20, 'shadowBlur', 30, 'shadowColor', '#0f172a', 'shadowEnabled', false, 'shadowOffsetX', 0, 'shadowOffsetY', 12, 'shadowOpacity', 14, 'shadowSpread', 0, 'widthPercent', 100, 'title', '我的余额', 'show_balance', true, 'show_records', true, 'show_view_button', true)),
   JSON_OBJECT('id', 'profile-points', 'type', 'pointsEntry', 'props', JSON_OBJECT('background', '', 'backgroundColorEnd', '', 'backgroundColorStart', '', 'backgroundGradientDirection', 'horizontal', 'backgroundMode', 'color', 'background_image', '', 'borderColor', '', 'borderEnabled', true, 'borderStyle', 'solid', 'borderWidth', 1, 'marginBottom', 0, 'marginLeft', 0, 'marginRight', 0, 'marginTop', 0, 'padding', 0, 'paddingX', 28, 'paddingY', 28, 'radius', 20, 'shadowBlur', 30, 'shadowColor', '#0f172a', 'shadowEnabled', false, 'shadowOffsetX', 0, 'shadowOffsetY', 12, 'shadowOpacity', 14, 'shadowSpread', 0, 'widthPercent', 100, 'title', '我的积分', 'show_records', true, 'show_view_button', true)),
   JSON_OBJECT('id', 'profile-distribution', 'type', 'distributionEntry', 'props', JSON_OBJECT('background', '', 'backgroundColorEnd', '', 'backgroundColorStart', '', 'backgroundGradientDirection', 'horizontal', 'backgroundMode', 'color', 'background_image', '', 'borderColor', '', 'borderEnabled', true, 'borderStyle', 'solid', 'borderWidth', 1, 'marginBottom', 0, 'marginLeft', 0, 'marginRight', 0, 'marginTop', 0, 'padding', 0, 'paddingX', 28, 'paddingY', 28, 'radius', 20, 'shadowBlur', 30, 'shadowColor', '#0f172a', 'shadowEnabled', false, 'shadowOffsetX', 0, 'shadowOffsetY', 12, 'shadowOpacity', 14, 'shadowSpread', 0, 'widthPercent', 100, 'title', '分销中心', 'show_commission', true, 'show_team', true, 'show_invite', true, 'show_withdraw_button', true, 'show_records', true)),
   JSON_OBJECT('id', 'profile-service', 'type', 'serviceMenu', 'props', JSON_OBJECT('background', '', 'backgroundColorEnd', '', 'backgroundColorStart', '', 'backgroundGradientDirection', 'horizontal', 'backgroundMode', 'color', 'background_image', '', 'borderColor', '', 'borderEnabled', true, 'borderStyle', 'solid', 'borderWidth', 1, 'marginBottom', 0, 'marginLeft', 0, 'marginRight', 0, 'marginTop', 0, 'padding', 0, 'paddingX', 10, 'paddingY', 0, 'radius', 20, 'shadowBlur', 30, 'shadowColor', '#0f172a', 'shadowEnabled', false, 'shadowOffsetX', 0, 'shadowOffsetY', 12, 'shadowOpacity', 14, 'shadowSpread', 0, 'widthPercent', 100, 'title', '我的服务', 'display', 'list', 'columns', 4, 'items', JSON_ARRAY(JSON_OBJECT('title', '地址管理', 'label', '地址管理', 'image', 'static/decorate/profile-service-address.svg', 'path', '/pages-sub/address/list'), JSON_OBJECT('title', '系统设置', 'label', '系统设置', 'image', 'static/decorate/profile-service-settings.svg', 'path', '/pages-sub/user/settings'), JSON_OBJECT('title', '联系客服', 'label', '联系客服', 'image', 'static/decorate/profile-service-support.svg', 'path', ''))))
  )), 'native', 1, 1, 20, 1),
(3, 'tabbar', '系统默认底部导航', '系统内置底部导航方案，不能修改或删除',
 JSON_OBJECT('items', JSON_ARRAY(
   JSON_OBJECT('text', '首页', 'path', '/pages/index/index', 'icon', 'static/images/tabbar/home.png', 'activeIcon', 'static/images/tabbar/home-active.png'),
   JSON_OBJECT('text', '分类', 'path', '/pages/category/index', 'icon', 'static/images/tabbar/category.png', 'activeIcon', 'static/images/tabbar/category-active.png'),
   JSON_OBJECT('text', '购物车', 'path', '/pages/cart/index', 'icon', 'static/images/tabbar/cart.png', 'activeIcon', 'static/images/tabbar/cart-active.png'),
   JSON_OBJECT('text', '订单', 'path', '/pages/order/index', 'icon', 'static/images/tabbar/order.png', 'activeIcon', 'static/images/tabbar/order-active.png'),
   JSON_OBJECT('text', '我的', 'path', '/pages/profile/index', 'icon', 'static/images/tabbar/profile.png', 'activeIcon', 'static/images/tabbar/profile-active.png')
 )), 'native', 1, 1, 30, 1),
(4, 'floating', '系统默认悬浮按钮', '系统内置全局悬浮入口方案，不能修改或删除',
 JSON_OBJECT(
   'enabled', true,
   'mode', 'expand',
   'position', 'right-bottom',
   'offsetX', 24,
   'offsetBottom', 160,
   'singleItemId', '',
   'style', JSON_OBJECT('size', 88, 'radius', 44, 'backgroundColor', '', 'color', '', 'shadowEnabled', true, 'shadowOffsetX', 0, 'shadowOffsetY', 12, 'shadowBlur', 30, 'shadowSpread', 0, 'shadowColor', '#0f172a', 'shadowOpacity', 14),
   'hiddenPages', JSON_ARRAY('/pages-sub/user/login', '/pages-sub/user/agreement'),
   'items', JSON_ARRAY(
     JSON_OBJECT('id', 'floating-service', 'text', '客服', 'type', 'customerService', 'icon', 'static/decorate/floating/service.png', 'enabled', true),
     JSON_OBJECT('id', 'floating-cart', 'text', '购物车', 'type', 'page', 'path', '/pages/cart/index', 'icon', 'static/decorate/floating/cart.png', 'enabled', true),
     JSON_OBJECT('id', 'floating-home', 'text', '首页', 'type', 'page', 'path', '/pages/index/index', 'icon', 'static/decorate/floating/home.png', 'enabled', true)
   )
 ), 'native', 1, 1, 40, 1);

INSERT INTO `mb_client_theme`
(`id`, `name`, `type`, `tokens`, `is_system`, `status`, `sort`)
VALUES
(1, '系统浅色主题', 'light', JSON_OBJECT(
  'colorPrimary', '#0d50d5',
  'colorPrimaryLight', '#386bef',
  'colorBg', '#ffffff',
  'colorBgSecondary', '#faf8ff',
  'colorBgSurface', '#f3f3fe',
  'colorText', '#191b23',
  'colorTextSecondary', '#434654',
  'colorTextTertiary', '#737686',
  'colorBorder', '#e0e4e8',
  'colorDivider', '#f0f2f5',
  'colorPrice', '#ff5a1f',
  'colorError', '#ba1a1a',
  'colorSuccess', '#34c759',
  'colorWarning', '#f0ad4e'
), 1, 1, 10),
(2, '系统深色主题', 'dark', JSON_OBJECT(
  'colorPrimary', '#386bef',
  'colorPrimaryLight', '#6f97ff',
  'colorBg', '#10131a',
  'colorBgSecondary', '#151923',
  'colorBgSurface', '#1b202a',
  'colorText', '#f2f5fa',
  'colorTextSecondary', '#c9d1df',
  'colorTextTertiary', '#9aa4b5',
  'colorBorder', '#303746',
  'colorDivider', '#262c38',
  'colorPrice', '#ff7a45',
  'colorError', '#ff6b6b',
  'colorSuccess', '#4ade80',
  'colorWarning', '#fbbf24'
), 1, 1, 20);

INSERT INTO `mb_client_theme_policy`
(`id`, `allow_user_select`, `default_mode`, `default_theme_id`)
VALUES (1, 1, 'system', NULL);
