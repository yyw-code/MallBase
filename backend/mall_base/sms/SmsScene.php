<?php

declare(strict_types=1);

namespace mall_base\sms;

/**
 * 短信验证码场景
 *
 * 区分不同业务场景，便于:
 *  - 模板 ID 映射(阿里云每个场景一个模板)
 *  - 频控按场景独立计算
 *  - 验证码 Redis key 按场景分桶
 */
final class SmsScene
{
    /** 登录验证码 */
    public const LOGIN = 'login';

    /** 注册验证码 */
    public const REGISTER = 'register';

    /** 找回密码 */
    public const RESET_PASSWORD = 'reset_password';

    /** 绑定/换绑手机号 */
    public const BIND_MOBILE = 'bind_mobile';

    /** 公众号 OAuth 后强制绑定手机号 */
    public const WECHAT_OFFICIAL_BIND = 'wechat_official_bind';

    private const TEXTS = [
        self::LOGIN                => '登录验证码',
        self::REGISTER             => '注册验证码',
        self::RESET_PASSWORD       => '找回密码',
        self::BIND_MOBILE          => '绑定手机号',
        self::WECHAT_OFFICIAL_BIND => '公众号绑定手机号',
    ];

    public static function isValid(string $scene): bool
    {
        return array_key_exists($scene, self::TEXTS);
    }

    public static function textOf(string $scene): string
    {
        return self::TEXTS[$scene] ?? '未知场景';
    }

    /**
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_keys(self::TEXTS);
    }
}
