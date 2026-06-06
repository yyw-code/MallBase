-- ============================================
-- 演示数据：用户分组与用户标签
-- ============================================

INSERT INTO `mb_user_group` (`name`, `code`, `description`, `color`, `sort`) VALUES
('VIP用户', 'vip', '高价值用户群体', 'gold', 1),
('新用户', 'new', '注册7天内用户', 'blue', 2),
('活跃用户', 'active', '7天内有登录', 'green', 3),
('流失用户', 'churn', '30天未登录', 'red', 4);

INSERT INTO `mb_user_tag` (`name`, `color`, `sort`) VALUES
('高消费', 'red', 1),
('价格敏感', 'orange', 2),
('高频购买', 'green', 3),
('潜在流失', 'volcano', 4);
