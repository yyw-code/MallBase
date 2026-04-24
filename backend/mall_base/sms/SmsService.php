<?php

declare(strict_types=1);

namespace mall_base\sms;

/**
 * 短信验证码服务(业务入口)
 *
 * 职责:
 *  - 生成 6 位数字验证码
 *  - 走频控
 *  - 调适配器发送
 *  - 验证码存 SmsCache(独立于适配器,方便 mock 模式联调)
 *  - 提供 verifyCode(mobile, scene, code) 业务校验接口
 *
 * 验证码生命周期:
 *  - 写入 5 分钟 TTL(可由构造参数 codeTtl 覆盖)
 *  - 验证成功后立即删除,防止重放
 *
 * 业务调用示例:
 *  ```php
 *  $sms = app()->make(SmsService::class);
 *  $sms->sendCode($mobile, SmsScene::LOGIN, request()->ip());
 *  $sms->verifyCode($mobile, SmsScene::LOGIN, $userInputCode);
 *  ```
 */
final class SmsService
{
    private const CODE_KEY_PREFIX = 'sms:code:';

    public function __construct(
        private readonly SmsAdapter $adapter,
        private readonly SmsRateLimiter $rateLimiter,
        private readonly SmsCache $cache,
        private readonly int $codeTtl = 300,
    ) {
    }

    /**
     * 发送验证码
     *
     * @throws SmsException 频控命中或渠道发送失败
     */
    public function sendCode(string $mobile, string $scene, string $ip = '', array $extra = []): void
    {
        $this->assertScene($scene);
        $this->assertMobile($mobile);

        $this->rateLimiter->assertCanSend($mobile, $ip);

        $code = $this->generateCode();

        $this->adapter->send($mobile, $scene, $code, $extra);

        // 发送成功才记录频控 + 写入 cache,否则用户因渠道异常拿不到码也不会被 60s 锁住
        $this->rateLimiter->record($mobile, $ip);
        $this->cache->set($this->codeKey($mobile, $scene), $code, $this->codeTtl);
    }

    /**
     * 校验验证码
     *
     * 校验通过后立即删除缓存中的验证码,防止重放
     *
     * @throws SmsException 验证码不存在/过期/不匹配
     */
    public function verifyCode(string $mobile, string $scene, string $code): void
    {
        $this->assertScene($scene);
        $this->assertMobile($mobile);

        $key = $this->codeKey($mobile, $scene);
        $stored = $this->cache->get($key);
        if (empty($stored)) {
            throw new SmsException('验证码已过期,请重新获取');
        }
        if ((string) $stored !== trim($code)) {
            throw new SmsException('验证码错误');
        }

        $this->cache->delete($key);
    }

    /**
     * 仅 mock 驱动下使用:取出当前验证码用于自动化测试
     *
     * @internal 不要在生产代码中调用
     */
    public function peekCodeForTesting(string $mobile, string $scene): ?string
    {
        $value = $this->cache->get($this->codeKey($mobile, $scene));
        return $value === null ? null : (string) $value;
    }

    private function codeKey(string $mobile, string $scene): string
    {
        return self::CODE_KEY_PREFIX . $scene . ':' . $mobile;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function assertScene(string $scene): void
    {
        if (!SmsScene::isValid($scene)) {
            throw new SmsException('无效的短信场景');
        }
    }

    private function assertMobile(string $mobile): void
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            throw new SmsException('手机号格式不正确');
        }
    }
}
