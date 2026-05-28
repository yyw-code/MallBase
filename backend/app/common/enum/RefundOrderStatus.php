<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 售后订单状态枚举
 *
 * 设计原则：
 *  - 独立于订单主状态，主订单列表通过聚合 refund_order 得到 after_sale_tag_text
 *  - 终态为 COMPLETED / REJECTED / CLOSED
 *  - 微信退款 PROCESSING 进入 REFUNDING，SUCCESS 进入 COMPLETED
 */
class RefundOrderStatus
{
    /** 待审核 */
    public const PENDING = 0;

    /** 已同意（待退款执行） */
    public const APPROVED = 1;

    /** 退款中 */
    public const REFUNDING = 2;

    /** 已完成（终态） */
    public const COMPLETED = 10;

    /** 已拒绝（终态） */
    public const REJECTED = 20;

    /** 已关闭（终态：用户主动撤销等） */
    public const CLOSED = 90;

    /** 类型：仅退款 */
    public const TYPE_REFUND_ONLY = 0;

    /** 类型：退货退款 */
    public const TYPE_RETURN_REFUND = 1;

    private const TEXTS = [
        self::PENDING   => '待审核',
        self::APPROVED  => '已同意',
        self::REFUNDING => '退款中',
        self::COMPLETED => '已完成',
        self::REJECTED  => '已拒绝',
        self::CLOSED    => '已关闭',
    ];

    private const TYPE_TEXTS = [
        self::TYPE_REFUND_ONLY   => '仅退款',
        self::TYPE_RETURN_REFUND => '退货退款',
    ];

    /**
     * 聚合到主订单列表的售后标签文案
     *
     * 给 OrderService 列表聚合时使用：若订单下存在任一非终态售后单，显示对应文案；否则为空
     */
    private const ACTIVE_STATUSES = [
        self::PENDING,
        self::APPROVED,
        self::REFUNDING,
    ];

    public static function textOf(int $status): string
    {
        return self::TEXTS[$status] ?? '未知';
    }

    public static function typeTextOf(int $type): string
    {
        return self::TYPE_TEXTS[$type] ?? '未知';
    }

    public static function isValid(int $status): bool
    {
        return array_key_exists($status, self::TEXTS);
    }

    public static function isActive(int $status): bool
    {
        return in_array($status, self::ACTIVE_STATUSES, true);
    }

    /**
     * 返回所有"进行中"的售后状态（供 OrderService::adminList 聚合 after_sale_tag 使用）
     *
     * @return array<int, int>
     */
    public static function activeStatuses(): array
    {
        return self::ACTIVE_STATUSES;
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

    /**
     * @return array<int, array{value:int, label:string}>
     */
    public static function typeOptions(): array
    {
        $options = [];
        foreach (self::TYPE_TEXTS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }

    /**
     * 终态集合（COMPLETED / REJECTED / CLOSED）
     */
    private const TERMINAL_STATUSES = [
        self::COMPLETED,
        self::REJECTED,
        self::CLOSED,
    ];

    public static function isTerminal(int $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    /**
     * 售后状态流转白名单
     *
     * PENDING(0)
     *   ├─ 微信退款处理中        → REFUNDING(2)
     *   ├─ 微信退款成功          → COMPLETED(10)
     *   ├─ 管理员驳回           → REJECTED(20)
     *   └─ 买家主动取消          → CLOSED(90)
     * REFUNDING(2)
     *   └─ 微信退款成功          → COMPLETED(10)
     *
     * COMPLETED / REJECTED / CLOSED 为终态，任何方向都不可再流转。
     *
     * APPROVED(1) 保留常量，当前审核路径暂不启用。
     *
     * @var array<int, array<int, int>>
     */
    private const TRANSITIONS = [
        self::PENDING => [
            self::REFUNDING,
            self::COMPLETED,
            self::REJECTED,
            self::CLOSED,
        ],
        self::REFUNDING => [
            self::COMPLETED,
        ],
    ];

    /**
     * 判断售后状态是否允许流转
     */
    public static function canTransit(int $from, int $to): bool
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }

        $allowed = self::TRANSITIONS[$from] ?? [];
        return in_array($to, $allowed, true);
    }
}
