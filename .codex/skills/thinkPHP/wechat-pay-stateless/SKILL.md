---
name: wechat-pay-stateless
description: MallBase 微信支付 SDK 在 Swoole 常驻进程下的无状态构造规则；接入或重构微信支付 / 公众号 / 小程序 SDK 调用时使用。
---

# ThinkPHP 规则：微信支付 SDK 无状态（Swoole）

## 适用范围

- `backend/app/service/client/payment/WechatPayFactory.php`
- `backend/app/service/client/payment/WechatPayClient.php`
- 任何 `new EasyWeChat\Pay\Application(...)` 或同类 SDK 实例化点

属于 `service-stateless-swoole` 的子规则，针对支付 SDK 特殊点单独沉淀。

## 强制规则

1. Factory 每次调用返回**新实例**：
   - 禁止把 `Pay\Application` 缓存到类属性、static、容器单例
   - 禁止把已构造的 SDK Application 跨请求/跨协程复用
   - EasyWeChat 内部的 HTTP Client、签名上下文、解密缓存均为请求级状态，复用会串数据

2. 配置必须**每次重新读取**：
   - `mchid / api_v3_key / cert_serial_no / private_key 路径 / platform_public_key 路径 / platform_public_key_id` 全部通过 `getSystemSetting()` 读取
   - 不读 `.env`、不读 `config/wechat.php`
   - 后台改完即时生效，不依赖重启

3. AppID 按 scene 切换，**Factory 不缓存 scene**：
   - scene=mini → `wechat_mini_appid`
   - scene=offi → `wechat_offi_appid`
   - scene=h5  → 不需要 appid，但需 `payer_client_ip`
   - 任一渠道凭据缺失立即抛 `BusinessException`，不让 SDK 用空字符串发请求

4. 证书文件读取规则：
   - 路径必须落在 `backend/storage/cert/`，权限 0600
   - 读到的证书内容用 readonly DTO 在**请求生命周期内**传递
   - 禁止用 `static` 缓存证书内容，避免凭据轮换后旧 worker 仍持有旧证书

5. 凭据校验前置：
   - Factory 构造前先 `assertCredentials()`，缺一项即抛业务异常
   - 错误信息引导到「后台 → 设置 → 支付配置」，不暴露字段细节

## 范式

参考 `backend/app/service/client/WechatAppFactory.php`，等价模式：

```php
class WechatPayFactory
{
    public function jsapi(int $scene): Application
    {
        $config = $this->loadConfig($scene); // 每次读 mb_setting
        $this->assertCredentials($config);
        return new Application($config);     // 每次 new
    }
}
```

## 禁止项

- ❌ `private static ?Application $app = null;`
- ❌ `bind(Application::class, fn() => new Application(...))`（容器单例）
- ❌ 在 Factory 构造函数里加载证书
- ❌ 跨方法复用同一个 `Application` 实例

## 自检清单

- [ ] Factory 类无 SDK 实例属性
- [ ] Factory 类无 `static` 缓存
- [ ] 证书读取在请求生命周期内完成且不写类静态
- [ ] AppID 按 scene 动态选择，无硬编码
- [ ] 缺凭据时抛 `BusinessException`，不传空字符串给 SDK

## When to Use

在以下场景触发此模式：
- 编写或修改任何支付 SDK Factory / Client
- 新增支付渠道（支付宝、银联）的 Factory
- 排查 Swoole 下「第一次请求正常、后续请求签名错」类问题

## Related

- `.codex/skills/thinkPHP/service-stateless-swoole/SKILL.md` — 上位通用规则
- `backend/app/service/client/WechatAppFactory.php` — 范式参考
- `.codex/skills/thinkPHP/payment-notify-idempotency/SKILL.md` — 支付回调幂等姊妹规则
