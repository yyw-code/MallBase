-- ============================================
-- 订单模块数据库表结构
-- 表前缀：mb_
-- 包含：购物车、订单主表、订单明细、订单流转日志、售后订单（预留）
-- 设计要点：
--   1. 状态机解耦：order.status（线性主状态）+ refund_order.status（售后独立状态）
--   2. after_sale_tag 不落库，由 OrderService 列表接口实时聚合
--   3. 订单时间线：主表 5 个时间戳（走索引）+ mb_order_log 审计（详情时间轴）
--   4. 订单号格式：YYMMDD + 10 位序列（生成逻辑在 OrderSnGenerator）
--   5. 库存扣减走乐观锁（UPDATE ... WHERE stock>=qty），本 SQL 不涉及 mb_goods_sku 变更
-- ============================================

-- -----------------------------
-- 一、购物车表
-- UNIQUE(user_id, sku_id) 保证同 SKU 只存一行，数量累加
-- -----------------------------
DROP TABLE IF EXISTS `mb_cart`;
CREATE TABLE `mb_cart` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '购物车ID',
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `goods_id` int(11) unsigned NOT NULL COMMENT '商品ID（SPU）',
  `sku_id` int(11) unsigned NOT NULL COMMENT 'SKU ID',
  `quantity` int(11) unsigned NOT NULL DEFAULT 1 COMMENT '数量',
  `selected` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否勾选（0未选，1已选）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_sku` (`user_id`, `sku_id`, `delete_time`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_sku_id` (`sku_id`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='购物车表';

-- -----------------------------
-- 二、订单主表
-- status 取值：0=待支付 10=已支付 20=已发货 30=已收货 40=已完成 90=已关闭
-- -----------------------------
DROP TABLE IF EXISTS `mb_order`;
CREATE TABLE `mb_order` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '订单ID',
  `sn` varchar(32) NOT NULL COMMENT '订单号（YYMMDD + 10位序列）',
  `user_id` int(11) unsigned NOT NULL COMMENT '下单用户ID',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '订单状态（0待支付 10已支付 20已发货 30已收货 40已完成 90已关闭）',
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '商品总金额（未减优惠、未加运费）',
  `freight_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '运费',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '优惠金额',
  `pay_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '应付金额（total + freight - discount）',
  `pay_method` tinyint(1) DEFAULT NULL COMMENT '支付方式（1微信 3余额）',
  `pay_scene` tinyint(1) unsigned DEFAULT NULL COMMENT '支付场景（1小程序 2公众号 3H5），仅微信支付有值',
  `trade_no` varchar(64) DEFAULT NULL COMMENT '第三方交易流水号',
  `receiver_name` varchar(50) NOT NULL COMMENT '收货人姓名',
  `receiver_phone` varchar(20) NOT NULL COMMENT '收货人电话',
  `receiver_province` varchar(50) NOT NULL DEFAULT '' COMMENT '省',
  `receiver_city` varchar(50) NOT NULL DEFAULT '' COMMENT '市',
  `receiver_district` varchar(50) NOT NULL DEFAULT '' COMMENT '区/县',
  `receiver_address` varchar(255) NOT NULL COMMENT '详细地址',
  `delivery_type` varchar(16) NOT NULL DEFAULT 'physical' COMMENT '发货类型（physical实物快递 virtual虚拟发货）',
  `delivery_note` varchar(255) DEFAULT NULL COMMENT '发货备注（虚拟发货说明等）',
  `logistics_platform` varchar(32) DEFAULT NULL COMMENT '物流平台',
  `logistics_company_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '物流公司ID',
  `logistics_company_code` varchar(64) DEFAULT NULL COMMENT '物流公司编码',
  `logistics_company` varchar(100) DEFAULT NULL COMMENT '物流公司',
  `logistics_sn` varchar(64) DEFAULT NULL COMMENT '物流单号',
  `buyer_remark` varchar(255) DEFAULT NULL COMMENT '买家备注',
  `admin_remark` varchar(255) DEFAULT NULL COMMENT '商家备注',
  `expire_at` datetime DEFAULT NULL COMMENT '支付超时时间（用于定时扫描关闭）',
  `paid_at` datetime DEFAULT NULL COMMENT '支付完成时间',
  `shipped_at` datetime DEFAULT NULL COMMENT '发货时间',
  `received_at` datetime DEFAULT NULL COMMENT '确认收货时间',
  `completed_at` datetime DEFAULT NULL COMMENT '订单完成时间',
  `closed_at` datetime DEFAULT NULL COMMENT '订单关闭时间',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间（下单时间）',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_status_created` (`status`, `create_time`),
  KEY `idx_expire_at` (`expire_at`),
  KEY `idx_paid_at` (`paid_at`),
  KEY `idx_shipped_at` (`shipped_at`),
  KEY `idx_logistics_platform_company` (`logistics_platform`, `logistics_company_id`),
  KEY `idx_logistics_company_code` (`logistics_company_code`),
  KEY `idx_logistics_sn` (`logistics_sn`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单主表';

-- -----------------------------
-- 三、订单明细表（订单项）
-- 下单时将商品名/图/规格文案快照写入，避免后续商品改动影响历史订单
-- shipped/refunded/returned_quantity 支持未来拆单发货/拆单退款/拆单退货的聚合
-- -----------------------------
DROP TABLE IF EXISTS `mb_order_item`;
CREATE TABLE `mb_order_item` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '订单项ID',
  `order_id` int(11) unsigned NOT NULL COMMENT '订单ID',
  `goods_id` int(11) unsigned NOT NULL COMMENT '商品ID（SPU）',
  `sku_id` int(11) unsigned NOT NULL COMMENT 'SKU ID',
  `goods_name` varchar(200) NOT NULL COMMENT '商品名称快照',
  `goods_image` bigint(20) unsigned DEFAULT NULL COMMENT '商品主图素材ID快照',
  `sku_spec` varchar(500) DEFAULT NULL COMMENT 'SKU 规格文案快照（如：红色,XL）',
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '下单时单价快照',
  `quantity` int(11) unsigned NOT NULL DEFAULT 1 COMMENT '购买数量',
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '小计金额（unit_price * quantity）',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '订单项优惠金额',
  `pay_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '订单项实付金额（subtotal - discount）',
  `shipped_quantity` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '已发货数量（拆单发货用）',
  `refunded_quantity` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '已退款数量（拆单退款聚合）',
  `returned_quantity` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '已退货数量（拆单退货聚合）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_sku_id` (`sku_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单明细表';

-- -----------------------------
-- 四、订单流转日志表（append-only 审计）
-- 任何订单状态变更都会写一条；operator_type 0=系统 1=买家 2=管理员
-- -----------------------------
DROP TABLE IF EXISTS `mb_order_log`;
CREATE TABLE `mb_order_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `order_id` int(11) unsigned NOT NULL COMMENT '订单ID',
  `from_status` tinyint(1) DEFAULT NULL COMMENT '变更前状态（首条为 NULL）',
  `to_status` tinyint(1) NOT NULL COMMENT '变更后状态',
  `operator_type` tinyint(1) NOT NULL DEFAULT 0 COMMENT '操作者类型（0系统 1买家 2管理员）',
  `operator_id` int(11) unsigned DEFAULT NULL COMMENT '操作者ID（系统为 NULL）',
  `remark` varchar(255) DEFAULT NULL COMMENT '变更备注',
  `ip` varchar(45) DEFAULT NULL COMMENT '操作来源IP',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_created` (`order_id`, `create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单流转日志（审计）';

-- -----------------------------
-- 五、售后订单表（退款/退货）
-- type 0=仅退款 1=退货退款
-- status 0=待审核 1=已同意（保留） 2=退款中（保留） 10=已完成 20=已拒绝 90=关闭
-- 审计字段（admin_remark / reviewed_by / reviewed_at / refunded_at / canceled_at）
--   由 RefundOrderStatusMachine 在状态流转时原子写入，不引入独立日志表
-- -----------------------------
DROP TABLE IF EXISTS `mb_refund_order`;
CREATE TABLE `mb_refund_order` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '售后单ID',
  `sn` varchar(32) NOT NULL COMMENT '售后单号',
  `order_id` int(11) unsigned NOT NULL COMMENT '原订单ID',
  `order_item_id` int(11) unsigned DEFAULT NULL COMMENT '原订单项ID（整单售后可为空）',
  `user_id` int(11) unsigned NOT NULL COMMENT '申请用户ID',
  `type` tinyint(1) NOT NULL DEFAULT 0 COMMENT '类型（0仅退款 1退货退款）',
  `receive_status` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '收货状态（0未收到货 1已收到货）',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '售后状态（0待审核 1已同意 2退款中 10已完成 20已拒绝 90关闭）',
  `quantity` int(11) unsigned NOT NULL DEFAULT 1 COMMENT '申请数量',
  `refund_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '申请退款金额',
  `reason` varchar(255) DEFAULT NULL COMMENT '售后原因（枚举字符串：MISTAKEN_ORDER 等）',
  `remark` varchar(255) DEFAULT NULL COMMENT '买家售后说明',
  `admin_remark` varchar(255) NOT NULL DEFAULT '' COMMENT '审核意见/驳回原因（由后台审核时写入）',
  `return_receiver_name` varchar(50) DEFAULT NULL COMMENT '退货收货人姓名快照',
  `return_receiver_phone` varchar(30) DEFAULT NULL COMMENT '退货收货人电话快照',
  `return_receiver_address` varchar(255) DEFAULT NULL COMMENT '退货收货地址快照',
  `return_company` varchar(50) DEFAULT NULL COMMENT '买家退货物流公司',
  `return_tracking_no` varchar(64) DEFAULT NULL COMMENT '买家退货物流单号',
  `return_shipped_at` datetime DEFAULT NULL COMMENT '买家填写退货物流时间',
  `return_received_at` datetime DEFAULT NULL COMMENT '商家确认收到退货时间',
  `intercept_status` varchar(24) NOT NULL DEFAULT 'none' COMMENT '物流拦截状态（none/pending/intercepting/success/failed/returning/returned/exception）',
  `intercept_note` varchar(255) DEFAULT NULL COMMENT '物流拦截备注',
  `reviewed_by` int(11) unsigned DEFAULT NULL COMMENT '审核管理员ID',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间（approve/reject 两路径均写）',
  `refunded_at` datetime DEFAULT NULL COMMENT '退款完成时间（COMPLETED 态时写入）',
  `canceled_at` datetime DEFAULT NULL COMMENT '买家撤销时间（CLOSED 态时写入）',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  KEY `idx_order_status` (`order_id`, `status`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_reviewed_at` (`reviewed_at`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='售后订单表（退款/退货）';
