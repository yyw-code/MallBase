<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 支付方式枚举
 *
 * MVP 仅提供 Mock 支付，微信/支付宝值占位，真实渠道接入后在对应 Adapter 实现
 */
final class PayMethod
{
    /** 微信支付 */
    public const WECHAT = 1;

    /** 支付宝 */
    public const ALIPAY = 2;

    /** Mock 测试支付（MVP 下单即视为已支付用） */
    public const MOCK = 9;

    private const TEXTS = [
        self::WECHAT => '微信支付',
        self::ALIPAY => '支付宝',
        self::MOCK   => 'Mock支付',
    ];

    public static function textOf(int $method): string
    {
        return self::TEXTS[$method] ?? '未知';
    }

    public static function isValid(int $method): bool
    {
        return array_key_exists($method, self::TEXTS);
    }

    /**
     * @return array<int, array{value:int, label:string}>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::TEXTS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }
}
