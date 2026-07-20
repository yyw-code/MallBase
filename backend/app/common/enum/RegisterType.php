<?php

declare(strict_types=1);

namespace app\common\enum;

/**
 * 用户注册来源
 *
 * 用于 mb_user.register_type，标识用户从哪个客户端完成注册。
 * 注册来源 ≠ 登录方式：同一用户可能在不同客户端登录，但首次完成账号建立的来源被记录在此字段。
 *
 * 取值约定：
 *  - mobile           手机号注册（来自移动设备，非微信生态）
 *  - wechat_miniapp   微信小程序登录注册
 *  - wechat_official  微信公众号 OAuth 注册
 *  - h5              纯网页（非微信内嵌）注册
 */
class RegisterType
{
    /** 手机号注册（移动设备） */
    public const MOBILE = 'mobile';

    /** 微信小程序 */
    public const WECHAT_MINIAPP = 'wechat_miniapp';

    /** 微信公众号 */
    public const WECHAT_OFFICIAL = 'wechat_official';

    /** 纯网页 */
    public const H5 = 'h5';

    private const TEXTS = [
        self::MOBILE          => '手机号',
        self::WECHAT_MINIAPP  => '微信小程序',
        self::WECHAT_OFFICIAL => '微信公众号',
        self::H5              => '网页',
    ];

    /**
     * 当前业务允许写入的取值清单
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_keys(self::TEXTS);
    }

    public static function textOf(string $type): string
    {
        return self::TEXTS[$type] ?? '未知';
    }

    public static function isValid(string $type): bool
    {
        return array_key_exists($type, self::TEXTS);
    }
}
