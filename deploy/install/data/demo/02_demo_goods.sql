-- ============================================
-- 演示数据：商品（含分类、品牌、SKU）
-- ============================================

-- 一级分类补充图片
UPDATE `mb_goods_category` SET `image` = '/static/demo/cat-phone.png'   WHERE `id` = 1;
UPDATE `mb_goods_category` SET `image` = '/static/demo/cat-clothes.png' WHERE `id` = 2;
UPDATE `mb_goods_category` SET `image` = '/static/demo/cat-food.png'    WHERE `id` = 3;
UPDATE `mb_goods_category` SET `image` = '/static/demo/cat-home.png'    WHERE `id` = 4;

-- 二级分类
INSERT INTO `mb_goods_category` (`id`, `pid`, `name`, `image`, `sort`, `status`) VALUES
(5, 1, '智能手机', '/static/demo/cat-smartphone.png', 1, 1),
(6, 1, '平板电脑', '/static/demo/cat-tablet.png', 2, 1),
(7, 2, '男装', '/static/demo/cat-menswear.png', 1, 1),
(8, 2, '女装', '/static/demo/cat-womenswear.png', 2, 1),
(9, 3, '休闲零食', '/static/demo/cat-snacks.png', 1, 1),
(10, 4, '家具家装', '/static/demo/cat-furniture.png', 1, 1);

-- 规格模板
INSERT INTO `mb_goods_spec_template` (`id`, `name`, `detail`, `sort`, `status`) VALUES
(1, '手机规格', '[{"name":"颜色","add_pic":0,"values":[{"value":"黑色","pic":""},{"value":"白色","pic":""},{"value":"金色","pic":""}]},{"name":"内存","add_pic":0,"values":[{"value":"128G","pic":""},{"value":"256G","pic":""},{"value":"512G","pic":""}]}]', 1, 1),
(2, '服装规格', '[{"name":"颜色","add_pic":0,"values":[{"value":"黑色","pic":""},{"value":"白色","pic":""}]},{"name":"尺码","add_pic":0,"values":[{"value":"S","pic":""},{"value":"M","pic":""},{"value":"L","pic":""},{"value":"XL","pic":""}]}]', 2, 1);

-- ---- 商品 1：iPhone 15 Pro（多规格）----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `is_new`, `is_hot`, `sort`) VALUES
(1, 5, 1, 'iPhone 15 Pro', '钛金属设计，A17 Pro 芯片', '/static/demo/iphone15pro.png', '["/static/demo/iphone15pro.png"]', 2,
 '[{"name":"颜色","add_pic":0,"values":[{"value":"黑色钛金属","pic":""},{"value":"白色钛金属","pic":""}]},{"name":"内存","add_pic":0,"values":[{"value":"256G","pic":""},{"value":"512G","pic":""}]}]',
 '<p>全新 iPhone 15 Pro，搭载 A17 Pro 芯片，钛金属设计，超视网膜 XDR 显示屏。</p>',
 7999.00, 8999.00, 200, 56, '台', 1, 1, 1, 1, 1);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `cost_price`, `stock`, `sku_code`) VALUES
(1, '黑色钛金属,256G', 7999.00, 8999.00, 6500.00, 50, 'IP15P-BK-256'),
(1, '黑色钛金属,512G', 9999.00, 10999.00, 8000.00, 50, 'IP15P-BK-512'),
(1, '白色钛金属,256G', 7999.00, 8999.00, 6500.00, 50, 'IP15P-WH-256'),
(1, '白色钛金属,512G', 9999.00, 10999.00, 8000.00, 50, 'IP15P-WH-512');

-- ---- 商品 2：华为 Mate 60 Pro（多规格）----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `is_new`, `is_hot`, `sort`) VALUES
(2, 5, 2, '华为 Mate 60 Pro', '遥遥领先，卫星通信', '/static/demo/mate60pro.png', '["/static/demo/mate60pro.png","/static/demo/mate60pro-2.png","/static/demo/mate60pro-3.png"]', 2,
 '[{"name":"颜色","add_pic":0,"values":[{"value":"雅丹黑","pic":""},{"value":"白沙银","pic":""}]},{"name":"内存","add_pic":0,"values":[{"value":"256G","pic":""},{"value":"512G","pic":""}]}]',
 '<p>华为 Mate 60 Pro，搭载麒麟芯片，支持卫星通信，超感知影像系统。</p>',
 6999.00, 7999.00, 150, 88, '台', 1, 1, 1, 1, 2);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `cost_price`, `stock`, `sku_code`) VALUES
(2, '雅丹黑,256G', 6999.00, 7999.00, 5500.00, 40, 'HW-M60P-BK-256'),
(2, '雅丹黑,512G', 7999.00, 8999.00, 6500.00, 35, 'HW-M60P-BK-512'),
(2, '白沙银,256G', 6999.00, 7999.00, 5500.00, 40, 'HW-M60P-WH-256'),
(2, '白沙银,512G', 7999.00, 8999.00, 6500.00, 35, 'HW-M60P-WH-512');

-- ---- 商品 3：小米14（多规格）----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `is_new`, `is_hot`, `sort`) VALUES
(3, 5, 3, '小米14', '徕卡光学，骁龙8 Gen3', '/static/demo/xiaomi14.png', '["/static/demo/xiaomi14.png"]', 2,
 '[{"name":"颜色","add_pic":0,"values":[{"value":"黑色","pic":""},{"value":"白色","pic":""},{"value":"岩石青","pic":""}]},{"name":"内存","add_pic":0,"values":[{"value":"256G","pic":""},{"value":"512G","pic":""}]}]',
 '<p>小米14，搭载第三代骁龙8，徕卡光学镜头，小米澎湃 OS。</p>',
 3999.00, 4299.00, 300, 120, '台', 1, 1, 1, 0, 3);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `cost_price`, `stock`, `sku_code`) VALUES
(3, '黑色,256G', 3999.00, 4299.00, 3200.00, 50, 'MI14-BK-256'),
(3, '黑色,512G', 4499.00, 4799.00, 3600.00, 50, 'MI14-BK-512'),
(3, '白色,256G', 3999.00, 4299.00, 3200.00, 50, 'MI14-WH-256'),
(3, '白色,512G', 4499.00, 4799.00, 3600.00, 50, 'MI14-WH-512'),
(3, '岩石青,256G', 3999.00, 4299.00, 3200.00, 50, 'MI14-GR-256'),
(3, '岩石青,512G', 4499.00, 4799.00, 3600.00, 50, 'MI14-GR-512');

-- ---- 商品 4：经典白T恤（多规格）----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `unit`, `is_on_sale`, `sort`) VALUES
(4, 7, NULL, '经典纯棉圆领T恤', '100%新疆长绒棉', '/static/demo/tshirt.png', '["/static/demo/tshirt.png"]', 2,
 '[{"name":"颜色","add_pic":0,"values":[{"value":"黑色","pic":""},{"value":"白色","pic":""}]},{"name":"尺码","add_pic":0,"values":[{"value":"S","pic":""},{"value":"M","pic":""},{"value":"L","pic":""},{"value":"XL","pic":""}]}]',
 '<p>100%新疆长绒棉面料，柔软亲肤，经典圆领百搭款。</p>',
 99.00, 199.00, 500, '件', 1, 10);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `stock`, `sku_code`) VALUES
(4, '黑色,S', 99.00, 199.00, 60, 'TS-BK-S'),
(4, '黑色,M', 99.00, 199.00, 70, 'TS-BK-M'),
(4, '黑色,L', 99.00, 199.00, 70, 'TS-BK-L'),
(4, '黑色,XL', 99.00, 199.00, 50, 'TS-BK-XL'),
(4, '白色,S', 99.00, 199.00, 60, 'TS-WH-S'),
(4, '白色,M', 99.00, 199.00, 70, 'TS-WH-M'),
(4, '白色,L', 99.00, 199.00, 70, 'TS-WH-L'),
(4, '白色,XL', 99.00, 199.00, 50, 'TS-WH-XL');

-- ---- 商品 5：坚果礼盒（单规格）----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `sort`) VALUES
(5, 9, NULL, '每日坚果混合礼盒', '750g/30包 每日一包', '/static/demo/nuts.png', '["/static/demo/nuts.png"]', 1,
 '<p>精选6种坚果，科学配比，每日一小包，营养均衡。包含腰果、核桃仁、巴旦木、蔓越莓干、榛子仁、南瓜子仁。</p>',
 89.90, 128.00, 800, 230, '盒', 1, 1, 20);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `stock`, `sku_code`) VALUES
(5, '', 89.90, 128.00, 800, 'NUT-MIX-750');

-- 商品标签关联
INSERT INTO `mb_goods_tag_relation` (`goods_id`, `tag_id`) VALUES
(1, 1), (1, 3),
(2, 1), (2, 3),
(3, 1), (3, 2),
(4, 2),
(5, 4);

-- 演示用首页轮播图（images 类型以 JSON 数组存储路径）
UPDATE `mb_setting` SET `value` = '["/static/client/banner-1.png","/static/client/banner-2.png","/static/client/banner-3.png"]'
WHERE `code` = 'client_home_banners';
