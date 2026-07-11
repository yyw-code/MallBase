# MallBase 业务文档常见问题

本页用于快速定位业务操作、业务逻辑和开发接入文档。当前文档仍是仓库内 Markdown，建议用本地搜索辅助查找。

```bash
rg -n "会员|积分|余额|装修|充值|成长值" README.md docs
```

## 快速定位

| 问题 | 推荐文档 |
|------|----------|
| 会员模块整体从哪里看 | [modules/member.md](./modules/member.md) |
| 积分模块整体从哪里看 | [modules/points.md](./modules/points.md) |
| 余额模块整体从哪里看 | [modules/wallet.md](./modules/wallet.md) |
| 客户端装修整体从哪里看 | [modules/client-diy.md](./modules/client-diy.md) |
| 后台基础配置在哪里设置 | [operation/basic-config.md](./operation/basic-config.md) |
| 运营如何配置会员等级和手动设置会员 | [operation/member.md](./operation/member.md) |
| 会员成长值、等级、折扣如何计算 | [logic/member.md](./logic/member.md) |
| 开发会员功能要看哪些表、服务和接口 | [development/member.md](./development/member.md) |
| 运营如何配置积分规则、积分商品和兑换单 | [operation/points.md](./operation/points.md) |
| 积分赠送、冻结、释放、回收、抵扣逻辑是什么 | [logic/points.md](./logic/points.md) |
| 开发积分功能要看哪些表、服务和接口 | [development/points.md](./development/points.md) |
| 运营如何配置余额支付、充值套餐和余额调整 | [operation/wallet.md](./operation/wallet.md) |
| 余额账户、流水和余额支付逻辑是什么 | [logic/wallet.md](./logic/wallet.md) |
| 开发余额功能要看哪些表、服务和接口 | [development/wallet.md](./development/wallet.md) |
| 首页、个人中心、底部导航、悬浮按钮怎么装修 | [operation/client-diy.md](./operation/client-diy.md) |
| 客户端装修方案、默认方案、主题策略如何生效 | [logic/client-diy.md](./logic/client-diy.md) |
| 开发装修功能要看哪些表、服务和前端组件 | [development/client-diy.md](./development/client-diy.md) |

## 文档分类

| 类型 | 入口 | 用途 |
|------|------|------|
| 操作文档 | [operation/index.md](./operation/index.md) | 给运营、管理员和实施人员看，说明后台怎么配置 |
| 逻辑文档 | [logic/index.md](./logic/index.md) | 给产品、测试和开发看，说明业务规则和状态流 |
| 开发文档 | [development/index.md](./development/index.md) | 给开发者看，说明代码结构、表、接口、扩展点和测试 |

## 维护原则

- 新增后台配置项时，同步补操作文档。
- 新增或调整计算规则时，同步补逻辑文档。
- 新增表、接口、Service、前端页面或测试时，同步补开发文档。
- FAQ 只保留入口和高频问题，不替代专题文档。
