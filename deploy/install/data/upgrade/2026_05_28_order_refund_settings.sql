-- 已安装环境补齐订单与售后配置（可重复执行）

SET NAMES utf8mb4;

INSERT INTO `mb_setting_group` (`id`, `parent_id`, `permission_id`, `name`, `code`, `icon`, `description`, `sort`, `display_type`, `status`) VALUES
(106, 101, 0, '订单配置', 'OrderConfig', NULL, '待支付超时、自动确认收货等订单流程配置', 30, 'page', 1),
(107, 101, 0, '售后配置', 'RefundConfig', NULL, '售后期限、退货收货信息与售后原因配置', 40, 'page', 1)
ON DUPLICATE KEY UPDATE
  `parent_id` = VALUES(`parent_id`),
  `name` = VALUES(`name`),
  `icon` = VALUES(`icon`),
  `description` = VALUES(`description`),
  `sort` = VALUES(`sort`),
  `display_type` = VALUES(`display_type`),
  `status` = VALUES(`status`);

INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(106, '待支付超时(分钟)', 'order_pending_pay_timeout_minutes', '30', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":1,"message":"必须大于 0"}]', NULL, '订单创建后超过该分钟数仍未支付时，定时任务自动关闭订单并回滚库存', 10),
(106, '自动确认收货(天)', 'order_auto_receive_days', '7', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":1,"message":"必须大于 0"}]', NULL, '订单发货后超过该天数未确认收货时，定时任务自动确认收货', 20),
(107, '售后期限(天)', 'refund_after_sale_days', '0', 'number', NULL, '[{"type":"required","message":"不能为空"},{"type":"min","value":0,"message":"不能小于 0"}]', NULL, '订单收货后多少天内可申请售后；设置为 0 表示不限制', 10),
(107, '退货收货人姓名', 'refund_return_receiver_name', '', 'input', NULL, NULL, NULL, NULL, 20),
(107, '退货收货人电话', 'refund_return_receiver_phone', '', 'input', NULL, NULL, NULL, NULL, 30),
(107, '退货收货人地址', 'refund_return_receiver_address', '', 'textarea', NULL, NULL, NULL, NULL, 40),
(107, '售后原因选项', 'refund_reason_options', '[{"value":"MISTAKEN_ORDER","label":"订单拍错"},{"value":"QUALITY_ISSUE","label":"商品质量问题"},{"value":"NO_LONGER_WANTED","label":"不想要了"},{"value":"OTHER","label":"其他"}]', 'option_list', NULL, NULL, '请输入原因名称', '客户端售后申请页与后端校验共用；后台只维护原因名称，编码由系统自动维护', 50),
(107, '常用驳回原因', 'refund_reject_reason_options', '[{"value":"商品已签收，不符合退款条件","label":"商品已签收，不符合退款条件"},{"value":"买家申请理由不成立","label":"买家申请理由不成立"},{"value":"已超过售后期限","label":"已超过售后期限"},{"value":"需提供相关凭证后重新申请","label":"需提供相关凭证后重新申请"}]', 'option_list', NULL, NULL, '请输入驳回原因', '后台驳回售后申请时快捷选择，买家可见；可继续手动补充详细说明', 60)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `type` = VALUES(`type`),
  `options` = VALUES(`options`),
  `rules` = VALUES(`rules`),
  `placeholder` = VALUES(`placeholder`),
  `remark` = VALUES(`remark`),
  `sort` = VALUES(`sort`);
