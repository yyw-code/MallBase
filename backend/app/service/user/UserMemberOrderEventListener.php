<?php
declare(strict_types=1);

namespace app\service\user;

use app\extension\contracts\OrderEventListenerInterface;
use app\extension\order\OrderEvent;
use app\extension\order\OrderEventContext;

class UserMemberOrderEventListener implements OrderEventListenerInterface
{
    public function code(): string
    {
        return 'user_member.order_event';
    }

    public function supports(string $event): bool
    {
        return $event === OrderEvent::ORDER_COMPLETED;
    }

    public function priority(string $event): int
    {
        return 200;
    }

    public function enabled(OrderEventContext $context): bool
    {
        return true;
    }

    public function handle(OrderEventContext $context): void
    {
        /** @var UserMemberService $service */
        $service = app()->make(UserMemberService::class);
        $service->rewardOrderCompleted($context->requireOrder());
    }
}
