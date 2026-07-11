<?php
declare(strict_types=1);

namespace app\service\user;

use app\extension\contracts\OrderPriceContributorInterface;
use app\extension\order\OrderPriceContext;

class UserPointsRewardPriceContributor implements OrderPriceContributorInterface
{
    public function code(): string
    {
        return 'user_points.reward';
    }

    public function priority(): int
    {
        return 300;
    }

    public function enabled(OrderPriceContext $context): bool
    {
        return $context->userId() > 0;
    }

    public function apply(OrderPriceContext $context): OrderPriceContext
    {
        /** @var UserPointsAccountService $service */
        $service = app()->make(UserPointsAccountService::class);

        return $context->withPointsReward($service->rewardQuote($context->pointsRewardQuoteItems()));
    }
}
