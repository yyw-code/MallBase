-- ============================================
-- 演示数据：充值套餐
-- ============================================

INSERT INTO `mb_recharge_package`
  (`name`, `pay_amount_cents`, `gift_amount_cents`, `balance_amount_cents`, `background_image`, `sort`, `status`, `remark`)
VALUES
  ('充50', 5000, 0, 5000, '/static/demo/recharge-dragon-card.png', 10, 1, '演示充值套餐'),
  ('充100送10', 10000, 1000, 11000, '/static/demo/recharge-dragon-card.png', 20, 1, '演示充值套餐'),
  ('充200送30', 20000, 3000, 23000, '/static/demo/recharge-dragon-card.png', 30, 1, '演示充值套餐');
