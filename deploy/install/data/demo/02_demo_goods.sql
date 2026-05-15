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
(1, 5, 1, 'iPhone 15 Pro', '钛金属设计，A17 Pro 芯片', '/static/demo/iphone15pro.png', '["/static/demo/iphone15pro.png","/static/demo/iphone15pro-2.png","/static/demo/iphone15pro-3.png"]', 2,
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
(3, 5, 3, '小米14', '徕卡光学，骁龙8 Gen3', '/static/demo/xiaomi14.png', '["/static/demo/xiaomi14.png","/static/demo/xiaomi14-2.png","/static/demo/xiaomi14-3.png"]', 2,
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

-- ============================================
-- 演示数据：潮流商品（与 Stitch 设计图配套）
-- ============================================

-- ---- 商品 6：StreetWave Air 1 复古潮流运动鞋（多规格）----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `is_new`, `is_hot`, `sort`) VALUES
(6, 2, NULL, 'StreetWave Air 1 复古潮流运动鞋', '2025 春季新款 限量 500 双', '/static/demo/goods/streetwave-air-1/main-0.png',
 '["/static/demo/goods/streetwave-air-1/main-0.png","/static/demo/goods/streetwave-air-1/swiper-0.png","/static/demo/goods/streetwave-air-1/swiper-1.png","/static/demo/goods/streetwave-air-1/swiper-2.png","/static/demo/goods/streetwave-air-1/detail-0.png"]', 2,
 '[{"name":"颜色","add_pic":1,"values":[{"value":"白蓝","pic":"/static/demo/goods/streetwave-air-1/main-0.png"},{"value":"烤白","pic":"/static/demo/goods/streetwave-air-1/swiper-0.png"},{"value":"蛋红","pic":"/static/demo/goods/streetwave-air-1/swiper-1.png"},{"value":"米色","pic":"/static/demo/goods/streetwave-air-1/swiper-2.png"}]},{"name":"尺码","add_pic":0,"values":[{"value":"39","pic":""},{"value":"40","pic":""},{"value":"41","pic":""},{"value":"42","pic":""},{"value":"43","pic":""},{"value":"44","pic":""}]}]',
 '<p><img src="/static/demo/goods/streetwave-air-1/detail-0.png" alt="" style="max-width:100%"/></p><p>StreetWave Air 1 复古潮流运动鞋，2025 春季新款，限量 500 双发售。鞋面采用透气网布与人造革拼接，缓震中底贴合脚型，街头穿搭百搭单品。</p><p><img src="/static/demo/goods/streetwave-air-1/detail-1.png" alt="" style="max-width:100%"/></p>',
 899.00, 1299.00, 23, 1287, '双', 1, 1, 1, 1, 5);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `stock`, `sku_code`) VALUES
(6, '白蓝,39', 899.00, 1299.00, 5, 'SWA1-WB-39'),
(6, '白蓝,40', 899.00, 1299.00, 4, 'SWA1-WB-40'),
(6, '白蓝,41', 899.00, 1299.00, 3, 'SWA1-WB-41'),
(6, '白蓝,42', 899.00, 1299.00, 6, 'SWA1-WB-42'),
(6, '白蓝,43', 899.00, 1299.00, 3, 'SWA1-WB-43'),
(6, '白蓝,44', 899.00, 1299.00, 2, 'SWA1-WB-44'),
(6, '烤白,40', 899.00, 1299.00, 5, 'SWA1-CW-40'),
(6, '烤白,41', 899.00, 1299.00, 4, 'SWA1-CW-41'),
(6, '烤白,42', 899.00, 1299.00, 5, 'SWA1-CW-42'),
(6, '烤白,43', 899.00, 1299.00, 3, 'SWA1-CW-43'),
(6, '蛋红,40', 899.00, 1299.00, 4, 'SWA1-ER-40'),
(6, '蛋红,41', 899.00, 1299.00, 3, 'SWA1-ER-41'),
(6, '蛋红,42', 899.00, 1299.00, 3, 'SWA1-ER-42'),
(6, '米色,40', 899.00, 1299.00, 4, 'SWA1-BG-40'),
(6, '米色,41', 899.00, 1299.00, 3, 'SWA1-BG-41'),
(6, '米色,42', 899.00, 1299.00, 4, 'SWA1-BG-42');

-- ---- 商品 7：NebulaWave 短袖 T 恤（多规格）----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `is_new`, `sort`) VALUES
(7, 7, NULL, 'NebulaWave 街头潮流短袖 T 恤', '300g 重磅纯棉 落肩剪裁', '/static/demo/goods/nebulawave-tee/main-0.png',
 '["/static/demo/goods/nebulawave-tee/main-0.png","/static/demo/goods/nebulawave-tee/swiper-0.png","/static/demo/goods/nebulawave-tee/swiper-1.png","/static/demo/goods/nebulawave-tee/swiper-2.png"]', 2,
 '[{"name":"颜色","add_pic":1,"values":[{"value":"黑色","pic":"/static/demo/goods/nebulawave-tee/main-0.png"},{"value":"白色","pic":"/static/demo/goods/nebulawave-tee/swiper-0.png"},{"value":"蓝色","pic":"/static/demo/goods/nebulawave-tee/swiper-1.png"}]},{"name":"尺码","add_pic":0,"values":[{"value":"S","pic":""},{"value":"M","pic":""},{"value":"L","pic":""},{"value":"XL","pic":""}]}]',
 '<p><img src="/static/demo/goods/nebulawave-tee/detail-0.png" alt="" style="max-width:100%"/></p><p>NebulaWave 街头潮流短袖 T 恤，300g 重磅纯棉面料，落肩剪裁更显廓形，胸前抽象图案印花。日常搭配卫裤、牛仔裤均合宜。</p>',
 199.00, 299.00, 240, 612, '件', 1, 1, 1, 6);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `stock`, `sku_code`) VALUES
(7, '黑色,S', 199.00, 299.00, 22, 'NWT-BK-S'),
(7, '黑色,M', 199.00, 299.00, 30, 'NWT-BK-M'),
(7, '黑色,L', 199.00, 299.00, 28, 'NWT-BK-L'),
(7, '黑色,XL', 199.00, 299.00, 18, 'NWT-BK-XL'),
(7, '白色,S', 199.00, 299.00, 24, 'NWT-WH-S'),
(7, '白色,M', 199.00, 299.00, 30, 'NWT-WH-M'),
(7, '白色,L', 199.00, 299.00, 26, 'NWT-WH-L'),
(7, '白色,XL', 199.00, 299.00, 16, 'NWT-WH-XL'),
(7, '蓝色,M', 199.00, 299.00, 18, 'NWT-BL-M'),
(7, '蓝色,L', 199.00, 299.00, 16, 'NWT-BL-L'),
(7, '蓝色,XL', 199.00, 299.00, 12, 'NWT-BL-XL');

-- ---- 商品 8：CityRunner 连帽卫衣（多规格）----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `is_hot`, `sort`) VALUES
(8, 7, NULL, 'CityRunner 城市连帽卫衣', '抓绒内里 防风袖口 通勤百搭', '/static/demo/goods/cityrunner-hoodie/main-0.png',
 '["/static/demo/goods/cityrunner-hoodie/main-0.png","/static/demo/goods/cityrunner-hoodie/swiper-0.png","/static/demo/goods/cityrunner-hoodie/swiper-1.png","/static/demo/goods/cityrunner-hoodie/swiper-2.png"]', 2,
 '[{"name":"颜色","add_pic":1,"values":[{"value":"碳灰","pic":"/static/demo/goods/cityrunner-hoodie/main-0.png"},{"value":"卡其","pic":"/static/demo/goods/cityrunner-hoodie/swiper-0.png"},{"value":"墨绿","pic":"/static/demo/goods/cityrunner-hoodie/swiper-1.png"}]},{"name":"尺码","add_pic":0,"values":[{"value":"M","pic":""},{"value":"L","pic":""},{"value":"XL","pic":""},{"value":"2XL","pic":""}]}]',
 '<p><img src="/static/demo/goods/cityrunner-hoodie/detail-0.png" alt="" style="max-width:100%"/></p><p>CityRunner 城市连帽卫衣，抓绒内里保暖透气，袖口与下摆罗纹收紧防风，胸前贴布 logo 设计。秋冬通勤运动两用。</p>',
 399.00, 599.00, 150, 318, '件', 1, 1, 1, 7);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `stock`, `sku_code`) VALUES
(8, '碳灰,M', 399.00, 599.00, 18, 'CRH-CG-M'),
(8, '碳灰,L', 399.00, 599.00, 22, 'CRH-CG-L'),
(8, '碳灰,XL', 399.00, 599.00, 16, 'CRH-CG-XL'),
(8, '碳灰,2XL', 399.00, 599.00, 8,  'CRH-CG-2XL'),
(8, '卡其,M', 399.00, 599.00, 14, 'CRH-KH-M'),
(8, '卡其,L', 399.00, 599.00, 20, 'CRH-KH-L'),
(8, '卡其,XL', 399.00, 599.00, 12, 'CRH-KH-XL'),
(8, '墨绿,M', 399.00, 599.00, 10, 'CRH-MG-M'),
(8, '墨绿,L', 399.00, 599.00, 14, 'CRH-MG-L'),
(8, '墨绿,XL', 399.00, 599.00, 8,  'CRH-MG-XL');

-- ---- 商品 9：StreetWave 帆布单肩包（单规格）----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_new`, `sort`) VALUES
(9, 4, NULL, 'StreetWave 复古帆布单肩包', '加厚帆布 大容量 街头风格', '/static/demo/goods/streetwave-bag/main-0.png',
 '["/static/demo/goods/streetwave-bag/main-0.png","/static/demo/goods/streetwave-bag/swiper-0.png","/static/demo/goods/streetwave-bag/swiper-1.png"]', 1,
 '<p><img src="/static/demo/goods/streetwave-bag/detail-0.png" alt="" style="max-width:100%"/></p><p>StreetWave 复古帆布单肩包，加厚 16oz 帆布面料，大容量主仓 + 拉链暗袋，肩带可调节。日常通勤、短途旅行皆可。</p>',
 129.00, 199.00, 320, 482, '只', 1, 1, 8);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `stock`, `sku_code`) VALUES
(9, '', 129.00, 199.00, 320, 'SWB-CV-STD');

-- ---- 商品 10：MetroFit 运动棒球帽（多规格）----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `sort`) VALUES
(10, 2, NULL, 'MetroFit 运动棒球帽', '速干面料 防晒透气 男女通用', '/static/demo/goods/metrofit-cap/main-0.png',
 '["/static/demo/goods/metrofit-cap/main-0.png","/static/demo/goods/metrofit-cap/swiper-0.png","/static/demo/goods/metrofit-cap/swiper-1.png","/static/demo/goods/metrofit-cap/swiper-2.png"]', 2,
 '[{"name":"颜色","add_pic":1,"values":[{"value":"黑色","pic":"/static/demo/goods/metrofit-cap/main-0.png"},{"value":"白色","pic":"/static/demo/goods/metrofit-cap/swiper-0.png"},{"value":"红色","pic":"/static/demo/goods/metrofit-cap/swiper-1.png"},{"value":"藏青","pic":"/static/demo/goods/metrofit-cap/swiper-2.png"}]}]',
 '<p><img src="/static/demo/goods/metrofit-cap/detail-0.png" alt="" style="max-width:100%"/></p><p>MetroFit 运动棒球帽，速干面料，UPF 50+ 防晒，后部魔术贴可调节，男女通用。运动跑步、街头穿搭都合适。</p>',
 89.00, 129.00, 540, 921, '顶', 1, 9);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `stock`, `sku_code`) VALUES
(10, '黑色', 89.00, 129.00, 150, 'MFC-BK'),
(10, '白色', 89.00, 129.00, 140, 'MFC-WH'),
(10, '红色', 89.00, 129.00, 130, 'MFC-RD'),
(10, '藏青', 89.00, 129.00, 120, 'MFC-NV');

-- 新增商品标签
INSERT INTO `mb_goods_tag_relation` (`goods_id`, `tag_id`) VALUES
(6, 1), (6, 2), (6, 3),
(7, 1), (7, 3),
(8, 3), (8, 4),
(9, 4),
(10, 4);

-- 演示用首页轮播图（images 类型以 JSON 数组存储路径）
UPDATE `mb_setting` SET `value` = '["/static/demo/banner-digital.png","/static/demo/banner-fashion.png","/static/demo/banner-home.png"]'
WHERE `code` = 'client_home_banners';

-- 演示用商品保障
UPDATE `mb_setting` SET `value` = '[{"title":"正品保障","desc":"平台严选商品来源","icon":"shield"},{"title":"极速发货","desc":"现货商品优先出库","icon":"truck"},{"title":"七天无理由","desc":"符合条件可无理由退货","icon":"refresh"},{"title":"售后无忧","desc":"订单售后进度可追踪","icon":"service"}]'
WHERE `code` = 'client_goods_guarantees';
