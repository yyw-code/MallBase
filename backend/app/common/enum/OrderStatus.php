<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 订单主状态枚举
 *
 * 设计原则：
 *  - 使用 tinyint 存储，业务代码禁止裸数字
 *  - 数值以 10 为间隔，便于后续在中间插入新状态
 *  - 流转白名单 TRANSITIONS 声明合法路径，由 OrderStatusMachine 校验
 */
class OrderStatus
{
    /** 待支付 */
    public const PENDING_PAY = 0;

    /** 已支付 */
    public const PAID = 10;

    /** 已发货 */
    public const SHIPPED = 20;

    /** 已收货 */
    public const RECEIVED = 30;

    /** 已完成（终态） */
    public const COMPLETED = 40;

    /** 已关闭（终态：超时/取消等） */
    public const CLOSED = 90;

    /**
     * 状态 → 文案映射
     */
    private const TEXTS = [
        self::PENDING_PAY => '待支付',
        self::PAID        => '已支付',
        self::SHIPPED     => '已发货',
        self::RECEIVED    => '已收货',
        self::COMPLETED   => '已完成',
        self::CLOSED      => '已关闭',
    ];

    /**
     * 状态流转白名单：from => [to, to, ...]
     * 终态（COMPLETED / CLOSED）不出现在 key 中
     */
    private const TRANSITIONS = [
        self::PENDING_PAY => [self::PAID, self::CLOSED],
        self::PAID        => [self::SHIPPED, self::CLOSED],
        self::SHIPPED     => [self::RECEIVED, self::CLOSED],
        self::RECEIVED    => [self::COMPLETED, self::CLOSED],
    ];

    /**
     * 获取状态文案
     */
    public static function textOf(int $status): string
    {
        return self::TEXTS[$status] ?? '未知';
    }

    /**
     * 枚举是否合法
     */
    public static function isValid(int $status): bool
    {
        return array_key_exists($status, self::TEXTS);
    }

    /**
     * 是否终态
     */
    public static function isTerminal(int $status): bool
    {
        return in_array($status, [self::COMPLETED, self::CLOSED], true);
    }

    /**
     * 是否允许从 from 流转到 to
     */
    public static function canTransit(int $from, int $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * 给前端下拉用的选项列表
     *
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
