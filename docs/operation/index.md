# 操作文档

操作文档面向后台管理员、运营人员和实施人员，说明功能在哪里、怎么配置、配置后前台有什么变化。

## 推荐阅读顺序

| 顺序 | 文档 | 说明 |
|------|------|------|
| 1 | [basic-config.md](./basic-config.md) | 后台基础配置、支付、客户端配置、上传配置入口 |
| 2 | [member.md](./member.md) | 会员开关、会员等级、手动设置会员 |
| 3 | [points.md](./points.md) | 积分开关、积分规则、积分商城、兑换单 |
| 4 | [wallet.md](./wallet.md) | 余额支付、充值套餐、余额调整和流水 |
| 5 | [client-diy.md](./client-diy.md) | 首页、个人中心、底部导航、悬浮按钮和主题装修 |

## 按问题查找

| 问题 | 推荐文档 |
|------|----------|
| 不知道某个功能入口在哪里 | [basic-config.md](./basic-config.md) |
| 要配置会员等级和手动保级 | [member.md](./member.md) |
| 要配置积分规则、积分商品和兑换单 | [points.md](./points.md) |
| 要处理余额支付、退款、后台余额调整 | [wallet.md](./wallet.md) |
| 要改首页、个人中心、底部导航、主题 | [client-diy.md](./client-diy.md) |

## 操作文档边界

- 本目录只描述后台操作和前台影响。
- 计算公式、状态机、幂等、冻结释放等规则放到 [../logic/index.md](../logic/index.md)。
- 表结构、接口、Service、测试入口放到 [../development/index.md](../development/index.md)。
