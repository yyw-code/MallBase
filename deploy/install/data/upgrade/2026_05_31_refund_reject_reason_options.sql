-- 售后配置：后台常用驳回原因（可重复执行）

SET NAMES utf8mb4;

INSERT INTO `mb_setting` (`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`) VALUES
(107, '常用驳回原因', 'refund_reject_reason_options', '[{"value":"商品已签收，不符合退款条件","label":"商品已签收，不符合退款条件"},{"value":"买家申请理由不成立","label":"买家申请理由不成立"},{"value":"已超过售后期限","label":"已超过售后期限"},{"value":"需提供相关凭证后重新申请","label":"需提供相关凭证后重新申请"}]', 'option_list', NULL, NULL, '请输入驳回原因', '后台驳回售后申请时快捷选择，买家可见；可继续手动补充详细说明', 60)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `type` = VALUES(`type`),
  `options` = VALUES(`options`),
  `rules` = VALUES(`rules`),
  `placeholder` = VALUES(`placeholder`),
  `remark` = VALUES(`remark`),
  `sort` = VALUES(`sort`);
