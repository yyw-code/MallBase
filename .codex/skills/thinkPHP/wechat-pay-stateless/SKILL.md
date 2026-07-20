---
name: wechat-pay-stateless
description: MallBase EasyWeChat 6.x 支付 SDK 无状态构造规则；修改 WechatPayFactory、WechatPayClient、支付/退款 Adapter、商户证书、平台公钥、AppID 场景选择，或排查 Swoole 下签名与凭据复用问题时使用。
---

# EasyWeChat 支付无状态构造

## 实例生命周期

1. 每次支付、退款、查单或回调处理通过 `WechatPayFactory::build()` 获取新的 `EasyWeChat\Pay\Application`。
2. 不把 Application、Validator、Server、HTTP Client 或签名上下文缓存到属性、`static` 或容器单例。
3. `WechatPayClient` 继续接收 Application 参数并做薄包装，不在内部持有 SDK 实例。
4. 允许 Factory/Client 作为无请求态的只读依赖被注入；请求态只存在于方法局部变量。

## 当前 Factory 契约

`WechatPayFactory::build()` 每次从 `mb_setting` 读取：

- `pay_wechat_mchid`
- `pay_wechat_api_v3_key`
- `pay_wechat_cert_serial_no`
- `pay_wechat_private_key`
- `pay_wechat_merchant_cert`
- `pay_wechat_platform_public_key`
- `pay_wechat_platform_public_key_id`

EasyWeChat 6.x V3 构造参数保持当前含义：

- `private_key` 指向商户 API 私钥。
- `certificate` 指向商户 API 证书 `apiclient_cert.pem`，不是平台公钥。
- `platform_certs` 使用 `[$platformPublicKeyId => $platformPublicKeyPath]` 关联数组。
- `public_key_id` 使用平台公钥 ID。

不要把平台公钥以普通证书列表传入；SDK 会按 X.509 证书解析并导致构造失败。配置路径可以是后端根目录下的相对路径或现有受控绝对路径，必须是可读文件；证书上传仍应使用私有的 cert 存储模块，不放入公开静态目录。

## 场景 AppID

SDK 构造与场景 AppID 分开：

- 小程序：`appIdOf(PayScene::MINI)` 读取 `wechat_mini_appid`。
- 公众号：`appIdOf(PayScene::OFFI)` 读取 `wechat_offi_appid`。
- 外部浏览器 H5：不走 `appIdOf(PayScene::H5)`；`WechatH5Adapter` 仍必须向 MWEB 下单参数传入商户号关联的 AppID，当前优先读取 `wechat_mini_appid`，为空时回退 `wechat_offi_appid`，并同时校验客户端 IP。

缺少凭据或文件不可读时抛 `BusinessException`，不要用空值继续请求微信。健康检查沿用 `WechatPayFactory::diagnose()`，不要另造一套配置来源。

## 自检

- [ ] 每次操作创建新的 Application。
- [ ] Factory 没有 SDK 或请求态缓存。
- [ ] 商户证书与平台公钥没有互换。
- [ ] `platform_certs` 是以公钥 ID 为 key 的关联数组。
- [ ] AppID 按支付场景读取，H5 使用当前 Adapter 约定的可用 AppID 和客户端 IP。
