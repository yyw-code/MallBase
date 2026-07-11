<?php
declare(strict_types=1);

namespace app\extension\order;

/**
 * 订单域扩展事件名。
 */
final class OrderEvent
{
    public const ORDER_PAID = 'order.paid';
    public const ORDER_COMPLETED = 'order.completed';
    public const ORDER_CLOSED = 'order.closed';
    public const REFUND_COMPLETED = 'refund.completed';

    private function __construct()
    {
    }
}
