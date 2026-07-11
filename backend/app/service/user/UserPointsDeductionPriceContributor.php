<?php
declare(strict_types=1);

namespace app\service\user;

use app\extension\contracts\OrderPriceContributorInterface;
use app\extension\order\OrderPriceContext;
use mall_base\exception\BusinessException;

class UserPointsDeductionPriceContributor implements OrderPriceContributorInterface
{
    public function code(): string
    {
        return 'user_points.deduction';
    }

    public function priority(): int
    {
        return 200;
    }

    public function enabled(OrderPriceContext $context): bool
    {
        return $context->userId() > 0;
    }

    public function apply(OrderPriceContext $context): OrderPriceContext
    {
        /** @var UserPointsAccountService $service */
        $service = app()->make(UserPointsAccountService::class);

        $pointsRequested = $context->usePoints() || $context->pointsUsed() > 0;
        if (!$service->isDeductionEnabled()) {
            if ($pointsRequested) {
                throw new BusinessException('积分抵扣未开启');
            }
            return $context;
        }

        return $context->withPointsDeduction($service->deductionQuote(
            $context->userId(),
            $context->pointsEligibleAmount(),
            $context->usePoints(),
            max(0, $context->pointsUsed())
        ));
    }
}
