-- ============================================
-- 演示数据：商品（含分类、品牌、SKU、评论用户）
-- 数据来源：以 CRMEB v6 演示站结构为参考，标题保留中文品牌、去具体型号，
--           价格/库存做合理化处理；图片均已下载至本地 static/demo/
-- ============================================

-- 一级分类补充图片
UPDATE `mb_goods_category` SET `image` = 19 WHERE `id` = 1;
UPDATE `mb_goods_category` SET `image` = 14 WHERE `id` = 2;
UPDATE `mb_goods_category` SET `image` = 15 WHERE `id` = 3;
UPDATE `mb_goods_category` SET `image` = 17 WHERE `id` = 4;

-- 二级分类
INSERT INTO `mb_goods_category` (`id`, `pid`, `name`, `image`, `sort`, `status`) VALUES
(5,  1, '电脑数码', 20, 1, 1),
(6,  1, '摄影摄像', 22, 2, 1),
(7,  1, '时尚配饰', 18, 3, 1),
(8,  2, '女装',     23, 1, 1),
(9,  2, '男装',     18, 2, 1),
(10, 4, '家具软装', 16, 1, 1);

-- 规格模板
INSERT INTO `mb_goods_spec_template` (`id`, `name`, `detail`, `sort`, `status`) VALUES
(1, '数码电脑规格', '[{"name":"版本","add_pic":0,"values":[{"value":"12代酷睿版","pic":""},{"value":"13代酷睿版","pic":""}]},{"name":"配置","add_pic":0,"values":[{"value":"i5 16G 512G","pic":""},{"value":"i7 16G 512G","pic":""},{"value":"i7 32G 1T","pic":""}]}]', 1, 1),
(2, '服装规格', '[{"name":"尺寸","add_pic":0,"values":[{"value":"XS","pic":""},{"value":"S","pic":""},{"value":"M","pic":""},{"value":"L","pic":""},{"value":"XL","pic":""}]},{"name":"颜色分类","add_pic":1,"values":[{"value":"黑色","pic":""},{"value":"白色","pic":""}]}]', 2, 1),
(3, '家具规格', '[{"name":"颜色","add_pic":1,"values":[{"value":"原木色","pic":""},{"value":"胡桃色","pic":""},{"value":"白色","pic":""}]}]', 3, 1);

-- ---- 商品 1：华为轻薄办公笔记本（多规格） ----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `is_new`, `is_hot`, `sort`) VALUES
(1, 5, NULL,
 '华为笔记本电脑 14 英寸轻薄商务办公本 12 代酷睿 护眼大屏 学生办公 全新轻薄本',
 '【限时直降 1000】顺丰包邮 · 12 期免息 · 学生认证再减 200',
 33,
 '[33,29,30,31,32]',
 2,
 '[{"name":"版本","add_pic":0,"values":[{"value":"12代酷睿版","pic":""},{"value":"13代酷睿版","pic":""}]},{"name":"配置","add_pic":0,"values":[{"value":"i5 16G 512G 皓月银","pic":""},{"value":"i7 16G 512G 皓月银","pic":""},{"value":"i7 32G 1T 深空灰","pic":""}]}]',
 '<div class="goods-detail"><h3>产品参数</h3><table border="0" cellpadding="6"><tr><td>屏幕尺寸</td><td>14 英寸 / 1920×1200 / 100% sRGB</td></tr><tr><td>处理器</td><td>Intel 第 12 代 / 第 13 代 酷睿 i5 / i7</td></tr><tr><td>内存 / 硬盘</td><td>16GB / 32GB LPDDR4X · 512GB / 1TB PCIe SSD</td></tr><tr><td>显卡</td><td>Intel 锐炬 Xe 核显</td></tr><tr><td>电池续航</td><td>56Wh · 本地视频续航约 11 小时</td></tr><tr><td>重量</td><td>约 1.38kg · 厚度 15.9mm</td></tr><tr><td>接口</td><td>2×USB-C · 2×USB-A · HDMI · 3.5mm</td></tr><tr><td>操作系统</td><td>Windows 11 家庭中文版</td></tr></table><h3>核心卖点</h3><p>1. 第 12 代 / 13 代酷睿 P 系列处理器，办公多任务流畅不卡顿</p><p>2. 14 英寸全高清护眼大屏，TÜV 莱茵双重认证，长时间使用不伤眼</p><p>3. 1.38kg 超轻机身 + 56Wh 大电池，全天通勤无忧</p><p>4. 全功能 Type-C 接口，反向供电支持多设备协同</p><h3>售后服务</h3><p>· 顺丰包邮 · 24 小时内发货</p><p>· 官方 1 年质保 · 2 年延保可加购</p><p>· 7 天无理由退换 · 15 天有质量问题包换</p></div>',
 3999.00, 5999.00, 487, 156, '台',
 1, 1, 1, 1, 2);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `cost_price`, `stock`, `sku_code`, `image`) VALUES
(1, '12代酷睿版,i5 16G 512G 皓月银', 3999.00, 5999.00, 3200.00, 143, 'HW-LP-12-I5-512',   33),
(1, '12代酷睿版,i7 16G 512G 皓月银', 4599.00, 6499.00, 3700.00,  68, 'HW-LP-12-I7-512',   29),
(1, '12代酷睿版,i7 32G 1T 深空灰',   5299.00, 6999.00, 4300.00,  12, 'HW-LP-12-I7-1T',    30),
(1, '13代酷睿版,i5 16G 512G 皓月银', 4399.00, 6299.00, 3500.00,  98, 'HW-LP-13-I5-512',   31),
(1, '13代酷睿版,i7 16G 512G 皓月银', 4999.00, 6999.00, 4000.00, 156, 'HW-LP-13-I7-512',   32),
(1, '13代酷睿版,i7 32G 1T 深空灰',   5799.00, 7999.00, 4600.00,  10, 'HW-LP-13-I7-1T',    30);

-- ---- 商品 2：松典 5K 数码微单相机（多规格） ----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `is_new`, `sort`) VALUES
(2, 6, NULL,
 '松典数码相机 5K 高清微单 复古入门级 学生女生旅游 Vlog 摄影 6 轴防抖',
 '【新品上市】顺丰包邮 · 标配 2 块电池 · WIFI 传输 · 1 年质保',
 13,
 '[13,9,10,11,12]',
 2,
 '[{"name":"存储","add_pic":0,"values":[{"value":"无卡","pic":""},{"value":"32G","pic":""},{"value":"64G","pic":""},{"value":"128G","pic":""}]},{"name":"套装","add_pic":1,"values":[{"value":"官方标配","pic":13},{"value":"加广角镜套装","pic":9}]}]',
 '<div class="goods-detail"><h3>产品参数</h3><table border="0" cellpadding="6"><tr><td>有效像素</td><td>5K 摄录 · 6400 万像素照片</td></tr><tr><td>传感器</td><td>1/2.5 英寸 CMOS</td></tr><tr><td>对焦</td><td>自动对焦 + 人脸识别</td></tr><tr><td>防抖</td><td>6 轴电子防抖</td></tr><tr><td>视频</td><td>5K @ 30fps · 4K @ 60fps · 慢动作支持</td></tr><tr><td>无线</td><td>内置 WIFI · 手机一键传输</td></tr><tr><td>屏幕</td><td>3.0 英寸高清触摸翻转屏</td></tr><tr><td>续航</td><td>标配 2 块电池 · 单块约 120 分钟拍摄</td></tr></table><h3>适用场景</h3><p>1. 学生党 Vlog · 校园记录 · 出游摄影</p><p>2. 网红博主 · 美食探店 · 街拍直出</p><p>3. 入门复古风 · 女生礼物 · 男友送礼</p><p>4. 旅行轻装备 · 比单反更便携，比手机更专业</p><h3>包装清单</h3><p>· 相机机身 × 1 · 电池 × 2 · 充电器 × 1 · 数据线 × 1 · 镜头盖 × 1 · 防尘袋 × 1 · 中文说明书 × 1</p><h3>售后服务</h3><p>· 顺丰快递 24 小时发货 · 7 天无理由 · 1 年官方质保</p></div>',
 959.00, 1599.00, 1186, 219, '台',
 1, 1, 1, 3);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `cost_price`, `stock`, `sku_code`, `image`) VALUES
(2, '无卡,官方标配',      959.00,  1599.00, 480.00, 287, 'SD-CAM-NC-STD',  13),
(2, '无卡,加广角镜套装',  1199.00, 1799.00, 620.00,  46, 'SD-CAM-NC-WIDE', 9),
(2, '32G,官方标配',      999.00,  1599.00, 510.00, 121, 'SD-CAM-32-STD',  13),
(2, '32G,加广角镜套装',  1239.00, 1799.00, 650.00,  88, 'SD-CAM-32-WIDE', 10),
(2, '64G,官方标配',      1029.00, 1699.00, 540.00, 116, 'SD-CAM-64-STD',  11),
(2, '64G,加广角镜套装',  1269.00, 1899.00, 680.00, 192, 'SD-CAM-64-WIDE', 11),
(2, '128G,官方标配',     1099.00, 1799.00, 600.00, 169, 'SD-CAM-128-STD', 12),
(2, '128G,加广角镜套装', 1339.00, 1999.00, 740.00, 167, 'SD-CAM-128-WIDE',12);

-- ---- 商品 3：卡西欧时尚女表（多规格） ----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `sort`) VALUES
(3, 7, NULL,
 '卡西欧女表 时尚优雅简约指针防水复古石英手表 学生情侣节日礼物',
 '【七夕礼物】顺丰包邮 · 2 年质保 · 官方授权 · 礼盒包装',
 47,
 '[47]',
 2,
 '[{"name":"表带款式","add_pic":1,"values":[{"value":"经典银","pic":47},{"value":"玫瑰金","pic":47},{"value":"复古金","pic":47},{"value":"雪域白","pic":47}]}]',
 '<div class="goods-detail"><h3>产品参数</h3><table border="0" cellpadding="6"><tr><td>品牌</td><td>卡西欧 Casio</td></tr><tr><td>机芯</td><td>日本进口高精度石英机芯</td></tr><tr><td>表壳材质</td><td>不锈钢</td></tr><tr><td>表盘直径</td><td>23mm</td></tr><tr><td>表带宽度</td><td>13mm</td></tr><tr><td>防水深度</td><td>30m 生活防水</td></tr><tr><td>玻璃</td><td>高硬度矿物玻璃</td></tr><tr><td>包装</td><td>官方礼盒 · 含纸袋</td></tr></table><h3>送礼推荐</h3><p>· 送女友 · 送闺蜜 · 送妈妈 · 送老婆</p><p>· 适合节日：七夕 · 情人节 · 生日 · 圣诞 · 周年纪念</p><h3>售后服务</h3><p>· 全国联保 2 年 · 7 天无理由 · 假一赔十</p><p>· 顺丰包邮 · 标配品牌礼盒 · 可代写贺卡</p></div>',
 269.00, 459.00, 3284, 612, '块',
 1, 1, 4);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `cost_price`, `stock`, `sku_code`, `image`) VALUES
(3, '经典银', 269.00, 459.00, 138.00, 1186, 'CAS-W-SLV', 47),
(3, '玫瑰金', 289.00, 489.00, 148.00,   84, 'CAS-W-RGD', 47),
(3, '复古金', 289.00, 489.00, 148.00,  998, 'CAS-W-VGD', 47),
(3, '雪域白', 269.00, 459.00, 138.00, 1016, 'CAS-W-WHT', 47);

-- ---- 商品 4：美式复古阔腿牛仔裤（多规格） ----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `is_new`, `is_hot`, `sort`) VALUES
(4, 8, NULL,
 '美式复古阔腿牛仔裤女 2024 夏季新款 高腰宽松显瘦垂感喇叭拖地裙裤',
 '【限时直降】顺丰包邮 · 7 天无理由 · 24 小时发货 · 三色五款可选',
 28,
 '[28,24,25,26,27]',
 2,
 '[{"name":"尺寸","add_pic":0,"values":[{"value":"XS","pic":""},{"value":"S","pic":""},{"value":"M","pic":""},{"value":"L","pic":""}]},{"name":"颜色分类","add_pic":1,"values":[{"value":"复古蓝","pic":24},{"value":"复古蓝【高质量版】","pic":25},{"value":"浅色","pic":26},{"value":"浅色【高质量版】","pic":26},{"value":"复古蓝【大口袋】","pic":27}]}]',
 '<div class="goods-detail"><h3>产品参数</h3><table border="0" cellpadding="6"><tr><td>面料成分</td><td>棉 78% / 涤纶 20% / 氨纶 2%</td></tr><tr><td>裤型</td><td>阔腿喇叭裤</td></tr><tr><td>腰型</td><td>高腰</td></tr><tr><td>裤长</td><td>拖地长款（约 102cm）</td></tr><tr><td>洗水工艺</td><td>石磨水洗</td></tr><tr><td>风格</td><td>美式复古 / Y2K</td></tr><tr><td>适用季节</td><td>春夏秋</td></tr><tr><td>洗涤方式</td><td>30℃ 以下机洗 · 反面晾晒</td></tr></table><h3>设计亮点</h3><p>1. 高腰收腰版型，显瘦 5cm 不夸张</p><p>2. 垂感面料，告别厚重感，夏天也能穿</p><p>3. 美式做旧色系，洗水自然不掉色</p><p>4. 加大复古口袋款，工装感拉满</p><h3>售后说明</h3><p>· 顺丰快递 · 24h 内发货</p><p>· 7 天无理由退换</p><p>· 质量问题免费换新</p></div>',
 49.98, 200.00, 29867, 869, '件',
 1, 1, 1, 1, 5);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `cost_price`, `stock`, `sku_code`, `image`) VALUES
(4, 'XS,复古蓝',              59.98, 200.00, 24.00,  956, 'JEANS-XS-DB',     24),
(4, 'XS,复古蓝【高质量版】',   69.98, 200.00, 30.00, 1989, 'JEANS-XS-DB-PRO', 25),
(4, 'XS,浅色',                59.98, 200.00, 24.00, 1993, 'JEANS-XS-LT',     26),
(4, 'XS,浅色【高质量版】',     69.98, 200.00, 30.00, 1994, 'JEANS-XS-LT-PRO', 26),
(4, 'XS,复古蓝【大口袋】',     49.98, 200.00, 22.00, 1997, 'JEANS-XS-DB-BIG', 27),
(4, 'S,复古蓝',               59.98, 200.00, 24.00,  192, 'JEANS-S-DB',      24),
(4, 'S,复古蓝【高质量版】',    69.98, 200.00, 30.00, 1995, 'JEANS-S-DB-PRO',  25),
(4, 'S,浅色',                 59.98, 200.00, 24.00, 1999, 'JEANS-S-LT',      26),
(4, 'S,浅色【高质量版】',      69.98, 200.00, 30.00, 1999, 'JEANS-S-LT-PRO',  26),
(4, 'S,复古蓝【大口袋】',      49.98, 200.00, 22.00, 1999, 'JEANS-S-DB-BIG',  27),
(4, 'M,复古蓝',               59.98, 200.00, 24.00,    0, 'JEANS-M-DB',      24),
(4, 'M,复古蓝【高质量版】',    69.98, 200.00, 30.00, 1999, 'JEANS-M-DB-PRO',  25),
(4, 'M,浅色',                 59.98, 200.00, 24.00, 2000, 'JEANS-M-LT',      26),
(4, 'M,浅色【高质量版】',      69.98, 200.00, 30.00,   38, 'JEANS-M-LT-PRO',  26),
(4, 'M,复古蓝【大口袋】',      49.98, 200.00, 22.00, 2000, 'JEANS-M-DB-BIG',  27),
(4, 'L,复古蓝',               59.98, 200.00, 24.00, 1850, 'JEANS-L-DB',      24),
(4, 'L,复古蓝【高质量版】',    69.98, 200.00, 30.00, 1900, 'JEANS-L-DB-PRO',  25),
(4, 'L,浅色',                 59.98, 200.00, 24.00, 1850, 'JEANS-L-LT',      26),
(4, 'L,浅色【高质量版】',      69.98, 200.00, 30.00, 1880, 'JEANS-L-LT-PRO',  26),
(4, 'L,复古蓝【大口袋】',      49.98, 200.00, 22.00, 1900, 'JEANS-L-DB-BIG',  27);

-- ---- 商品 5：小木槿意式轻奢单人沙发椅（多规格） ----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `sort`) VALUES
(5, 10, NULL,
 '小木槿意式轻奢设计师款单人沙发椅 极简客厅 网红老虎椅 休闲椅五色可选',
 '【设计师款】送货到家 · 一年质保 · 木箱包装 · 一年内有质量问题可退换',
 40,
 '[40,36,37,38,39]',
 2,
 '[{"name":"颜色","add_pic":1,"values":[{"value":"橙色","pic":40},{"value":"墨绿色","pic":36},{"value":"浅灰","pic":37},{"value":"米白色","pic":38},{"value":"卡其色","pic":39}]}]',
 '<div class="goods-detail"><h3>产品参数</h3><table border="0" cellpadding="6"><tr><td>主材</td><td>实木框架 · 高密度海绵</td></tr><tr><td>面料</td><td>科技布 · 易打理</td></tr><tr><td>颜色</td><td>五色可选 · 莫兰迪色系</td></tr><tr><td>尺寸</td><td>长 76 × 宽 75 × 高 84 cm</td></tr><tr><td>坐高</td><td>43cm</td></tr><tr><td>承重</td><td>≤ 200kg</td></tr><tr><td>风格</td><td>意式极简 · 现代轻奢</td></tr><tr><td>适用空间</td><td>客厅 / 卧室 / 书房 / 阳台</td></tr></table><h3>设计亮点</h3><p>1. M 字曲线扶手 · 设计师原创版型 · 区别于市面流水款</p><p>2. 高弹海绵 + 实木腿 · 久坐不塌陷 · 承重稳固</p><p>3. 科技布面料 · 防水防污 · 湿巾可擦</p><p>4. 五种莫兰迪配色 · 不挑装修风格 · 单椅即点睛</p><h3>送货说明</h3><p>· 全国包邮 · 偏远地区联系客服</p><p>· 木箱专业打包 · 物流送货上门</p><p>· 可预约送货时间 · 安装免费上门服务</p></div>',
 899.00, 1399.00, 7445, 102, '把',
 1, 1, 1);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `cost_price`, `stock`, `sku_code`, `image`) VALUES
(5, '橙色',   899.00, 1399.00, 420.00, 1474, 'SOFA-OR', 40),
(5, '墨绿色', 899.00, 1399.00, 420.00, 1500, 'SOFA-DG', 36),
(5, '浅灰',   899.00, 1399.00, 420.00, 1471, 'SOFA-LG', 37),
(5, '米白色', 999.00, 1499.00, 460.00, 1500, 'SOFA-IV', 38),
(5, '卡其色', 899.00, 1399.00, 420.00, 1500, 'SOFA-KH', 39);

-- ---- 商品 6：米维卡现代意式轻奢梳妆台（多规格） ----
INSERT INTO `mb_goods` (`id`, `category_id`, `brand_id`, `name`, `subtitle`, `main_image`, `images`, `spec_type`, `spec_meta`, `description`, `price`, `market_price`, `stock`, `sales`, `unit`, `is_on_sale`, `is_recommend`, `is_new`, `sort`) VALUES
(6, 10, NULL,
 '米维卡现代意式轻奢梳妆台 卧室主卧化妆桌 收纳一体木面妆台 含妆凳套装',
 '【设计师联名】送货上门 · 12 期免息 · 5 年质保 · 含 LED 化妆镜',
 46,
 '[46,42,43,44,45]',
 2,
 '[{"name":"套装","add_pic":1,"values":[{"value":"单妆台","pic":46},{"value":"妆台+妆凳","pic":42}]},{"name":"配色","add_pic":0,"values":[{"value":"原木面","pic":""},{"value":"胡桃面","pic":""}]}]',
 '<div class="goods-detail"><h3>产品参数</h3><table border="0" cellpadding="6"><tr><td>主材</td><td>E1 级环保中纤板 · 真木皮饰面</td></tr><tr><td>五金</td><td>百隆静音滑轨 · 缓冲铰链</td></tr><tr><td>桌面尺寸</td><td>长 100 × 宽 45 × 高 76 cm</td></tr><tr><td>抽屉</td><td>3 层大容量 · 独立分区收纳</td></tr><tr><td>化妆镜</td><td>LED 三色温补光 · 可调角度</td></tr><tr><td>电源</td><td>USB-C · 适配各种充电头</td></tr><tr><td>风格</td><td>现代意式 · 轻奢极简</td></tr><tr><td>适用空间</td><td>主卧 · 次卧 · 衣帽间</td></tr></table><h3>设计亮点</h3><p>1. 真木皮饰面工艺 · 高级感肉眼可见 · 区别于贴纸款</p><p>2. 三层独立抽屉 · 内置分隔板 · 化妆品分类一目了然</p><p>3. 集成 LED 三色温化妆镜 · 早晚妆光线还原真实</p><p>4. 隐藏电源插座 · USB-C 接口 · 桌面整洁不杂乱</p><h3>包装清单</h3><p>· 妆台 × 1 · 化妆镜 × 1 · 妆凳 × 1（仅套装款）· 安装工具 × 1 · 安装说明 × 1</p><h3>送货安装</h3><p>· 全国包邮 · 物流送货到楼下 · 偏远地区咨询客服</p><p>· 木箱专业打包 · 安装服务可加购</p><p>· 5 年质保 · 终身维护</p></div>',
 4998.00, 9999.00, 1976, 167, '套',
 1, 1, 1, 6);

INSERT INTO `mb_goods_sku` (`goods_id`, `spec_values`, `price`, `market_price`, `cost_price`, `stock`, `sku_code`, `image`) VALUES
(6, '单妆台,原木面',     4998.00,  9999.00, 2400.00, 487, 'VAN-S-OAK',  46),
(6, '单妆台,胡桃面',     5198.00,  9999.00, 2500.00, 388, 'VAN-S-WAL',  43),
(6, '妆台+妆凳,原木面',  5998.00, 11999.00, 2800.00, 612, 'VAN-SS-OAK', 42),
(6, '妆台+妆凳,胡桃面',  6198.00, 11999.00, 2900.00, 489, 'VAN-SS-WAL', 44);

-- 商品标签关系
INSERT INTO `mb_goods_tag_relation` (`goods_id`, `tag_id`) VALUES
(1, 1), (1, 2), (1, 3),
(2, 1), (2, 3),
(3, 2), (3, 4),
(4, 1), (4, 3), (4, 4),
(5, 2), (5, 4),
(6, 1), (6, 2);

-- 演示用商品保障
UPDATE `mb_setting` SET `value` = '[{"title":"正品保障","desc":"平台严选商品来源","icon":"shield"},{"title":"极速发货","desc":"现货商品优先出库","icon":"truck"},{"title":"七天无理由","desc":"符合条件可无理由退货","icon":"refresh"},{"title":"售后无忧","desc":"订单售后进度可追踪","icon":"service"}]'
WHERE `code` = 'client_goods_guarantees';
