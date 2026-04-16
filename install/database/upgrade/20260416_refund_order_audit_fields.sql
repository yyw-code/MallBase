-- ============================================
-- Migration: mb_refund_order 增加审计字段
-- Date    : 2026-04-16
-- Commit  : feat(refund): 状态机白名单 + 表结构补齐 + 订单号生成器
--
-- 背景：售后 MVP 引入 RefundOrderStatusMachine，
--       同事务需要落审核意见、审核人、审核/退款/取消时间戳。
--
-- 执行方式：
--   mysql -h127.0.0.1 -u<user> -p <db_name> < install/database/migrations/20260416_refund_order_audit_fields.sql
--
-- 幂等性：使用 IF NOT EXISTS 语义（MySQL 8 原生支持）；
--         MySQL 5.7 环境请按需手动判断后执行。
-- ============================================

ALTER TABLE `mb_refund_order`
  ADD COLUMN `admin_remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '审核意见/驳回原因（由后台审核时写入）' AFTER `reason`,
  ADD COLUMN `reviewed_by` INT(11) UNSIGNED NULL DEFAULT NULL COMMENT '审核管理员ID' AFTER `admin_remark`,
  ADD COLUMN `reviewed_at` DATETIME NULL DEFAULT NULL COMMENT '审核时间（approve/reject 两路径均写）' AFTER `reviewed_by`,
  ADD COLUMN `refunded_at` DATETIME NULL DEFAULT NULL COMMENT '退款完成时间（COMPLETED 态时写入）' AFTER `reviewed_at`,
  ADD COLUMN `canceled_at` DATETIME NULL DEFAULT NULL COMMENT '买家撤销时间（CLOSED 态时写入）' AFTER `refunded_at`,
  ADD INDEX `idx_reviewed_at` (`reviewed_at`);
