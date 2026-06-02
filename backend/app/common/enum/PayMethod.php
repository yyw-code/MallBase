<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 支付方式枚举
 */
class PayMethod
{
    /** 微信支付 */
    public const WECHAT = 1;

    /** 支付宝 */
    public const ALIPAY = 2;

    /** 余额支付 */
    public const BALANCE = 3;

    /** Mock 测试支付（历史数据展示用，不再作为客户端支付入口） */
    public const MOCK = 9;

    private const TEXTS = [
        self::WECHAT  => '微信支付',
        self::ALIPAY  => '支付宝',
        self::BALANCE => '余额支付',
        self::MOCK    => 'Mock支付',
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
