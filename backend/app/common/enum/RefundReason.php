<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 售后申请原因枚举
 *
 * 设计说明：
 *  - 以字符串常量落库，避免后续语义漂移（对比 tinyint，更利于日志与数据分析）
 *  - 前端下拉仅展示本枚举范围，后端 Validate 同样用 in: 规则收敛
 *  - MVP 仅覆盖最典型四类原因；新增时务必同步更新 TEXTS 与 options()
 */
final class RefundReason
{
    /** 订单下错了（买家误操作） */
    public const MISTAKEN_ORDER = 'MISTAKEN_ORDER';

    /** 商品质量问题 */
    public const QUALITY_ISSUE = 'QUALITY_ISSUE';

    /** 不想要了 */
    public const NO_LONGER_WANTED = 'NO_LONGER_WANTED';

    /** 其他 */
    public const OTHER = 'OTHER';

    private const TEXTS = [
        self::MISTAKEN_ORDER    => '订单拍错',
        self::QUALITY_ISSUE     => '商品质量问题',
        self::NO_LONGER_WANTED  => '不想要了',
        self::OTHER             => '其他',
    ];

    public static function textOf(string $reason): string
    {
        return self::TEXTS[$reason] ?? '未知';
    }

    public static function isValid(string $reason): bool
    {
        return array_key_exists($reason, self::TEXTS);
    }

    /**
     * 可用原因值集合（供 Validate in: 规则使用）
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_keys(self::TEXTS);
    }

    /**
     * @return array<int, array{value:string, label:string}>
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
