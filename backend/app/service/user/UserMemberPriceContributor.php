<?php
declare(strict_types=1);

namespace app\service\user;

use app\extension\contracts\OrderPriceContributorInterface;
use app\extension\order\OrderPriceContext;

class UserMemberPriceContributor implements OrderPriceContributorInterface
{
    public function code(): string
    {
        return 'user_member.price';
    }

    public function priority(): int
    {
        return 100;
    }

    public function enabled(OrderPriceContext $context): bool
    {
        return $context->userId() > 0;
    }

    public function apply(OrderPriceContext $context): OrderPriceContext
    {
        /** @var UserMemberService $service */
        $service = app()->make(UserMemberService::class);

        return $context->withMemberDiscount($service->pricingQuote($context->userId(), $context->items()));
    }
}
