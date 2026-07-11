<?php
declare(strict_types=1);

namespace app\service\user;

use app\common\enum\OrderStatus;
use app\extension\contracts\OrderEventListenerInterface;
use app\extension\order\OrderEvent;
use app\extension\order\OrderEventContext;

class UserPointsOrderEventListener implements OrderEventListenerInterface
{
    public function code(): string
    {
        return 'user_points.order_event';
    }

    public function supports(string $event): bool
    {
        return in_array($event, [
            OrderEvent::ORDER_COMPLETED,
            OrderEvent::ORDER_CLOSED,
            OrderEvent::REFUND_COMPLETED,
        ], true);
    }

    public function priority(string $event): int
    {
        return match ($event) {
            OrderEvent::ORDER_CLOSED => 300,
            default => 100,
        };
    }

    public function enabled(OrderEventContext $context): bool
    {
        if ($context->event() !== OrderEvent::ORDER_CLOSED) {
            return true;
        }
        return $context->fromStatus() === OrderStatus::PENDING_PAY;
    }

    public function handle(OrderEventContext $context): void
    {
        /** @var UserPointsAccountService $service */
        $service = app()->make(UserPointsAccountService::class);

        match ($context->event()) {
            OrderEvent::ORDER_COMPLETED => $service->rewardOrderCompleted($context->requireOrder()),
            OrderEvent::ORDER_CLOSED => $service->returnOrderDeduction($context->requireOrder()),
            OrderEvent::REFUND_COMPLETED => $service->rollbackRefundCompleted($context->requireRefund()),
            default => null,
        };
    }
}
