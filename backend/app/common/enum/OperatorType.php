<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 订单日志操作者类型
 *
 * 用于 mb_order_log.operator_type，标识状态变更是谁触发的
 */
final class OperatorType
{
    /** 系统（定时任务、回调等） */
    public const SYSTEM = 0;

    /** 买家 */
    public const BUYER = 1;

    /** 后台管理员 */
    public const ADMIN = 2;

    private const TEXTS = [
        self::SYSTEM => '系统',
        self::BUYER  => '买家',
        self::ADMIN  => '管理员',
    ];

    public static function textOf(int $type): string
    {
        return self::TEXTS[$type] ?? '未知';
    }

    public static function isValid(int $type): bool
    {
        return array_key_exists($type, self::TEXTS);
    }
}
