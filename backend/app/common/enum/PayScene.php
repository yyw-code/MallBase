<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 支付场景枚举
 *
 * 与支付方式 {@see PayMethod} 正交：PayMethod 表达「走哪个渠道（微信/支付宝）」，
 * PayScene 表达「在哪种宿主里发起（小程序/公众号/外部 H5）」。
 *
 * 三种场景对应微信支付 V3 的 trade_type：
 *  - MINI / OFFI → JSAPI（需要 openid）
 *  - H5         → MWEB（需要 client_ip）
 */
class PayScene
{
    /** 无外部支付场景（余额支付等站内支付使用） */
    public const NONE = 0;

    /** 微信小程序（trade_type=JSAPI，openid 取 user.wx_miniapp_openid） */
    public const MINI = 1;

    /** 微信公众号 H5（trade_type=JSAPI，openid 取 user.wx_official_openid） */
    public const OFFI = 2;

    /** 外部浏览器 H5（trade_type=MWEB） */
    public const H5 = 3;

    private const TEXTS = [
        self::MINI => '小程序',
        self::OFFI => '公众号',
        self::H5   => 'H5',
    ];

    /** code（前端传参用）→ value 映射 */
    private const CODE_MAP = [
        'mini' => self::MINI,
        'offi' => self::OFFI,
        'h5'   => self::H5,
    ];

    public static function textOf(int $scene): string
    {
        return self::TEXTS[$scene] ?? '未知';
    }

    public static function isValid(int $scene): bool
    {
        return array_key_exists($scene, self::TEXTS);
    }

    /**
     * 前端传 'mini'/'offi'/'h5' 字符串，统一映射到 int
     */
    public static function fromCode(string $code): ?int
    {
        return self::CODE_MAP[$code] ?? null;
    }
}
