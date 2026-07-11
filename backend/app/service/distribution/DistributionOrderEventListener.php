<?php
declare(strict_types=1);

namespace app\service\distribution;

use app\extension\contracts\OrderEventListenerInterface;
use app\extension\order\OrderEvent;
use app\extension\order\OrderEventContext;

class DistributionOrderEventListener implements OrderEventListenerInterface
{
    public function code(): string
    {
        return 'distribution.order_event';
    }

    public function supports(string $event): bool
    {
        return in_array($event, [
            OrderEvent::ORDER_PAID,
            OrderEvent::ORDER_COMPLETED,
            OrderEvent::ORDER_CLOSED,
            OrderEvent::REFUND_COMPLETED,
        ], true);
    }

    public function priority(string $event): int
    {
        return match ($event) {
            OrderEvent::ORDER_PAID,
            OrderEvent::ORDER_CLOSED => 100,
            OrderEvent::REFUND_COMPLETED => 200,
            default => 300,
        };
    }

    public function enabled(OrderEventContext $context): bool
    {
        return true;
    }

    public function handle(OrderEventContext $context): void
    {
        /** @var DistributionOrderEventService $service */
        $service = app()->make(DistributionOrderEventService::class);

        match ($context->event()) {
            OrderEvent::ORDER_PAID => $service->handleOrderPaid($context->requireOrder()),
            OrderEvent::ORDER_COMPLETED => $service->handleOrderCompleted($context->requireOrder()),
            OrderEvent::ORDER_CLOSED => $service->handleOrderClosed($context->requireOrder()),
            OrderEvent::REFUND_COMPLETED => $service->handleRefundCompleted($context->requireRefund()),
            default => null,
        };
    }
}
