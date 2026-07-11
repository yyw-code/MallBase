<?php
declare(strict_types=1);

namespace app\extension\contracts;

use app\extension\order\OrderPriceContext;

interface OrderPriceContributorInterface
{
    public function code(): string;

    public function priority(): int;

    public function enabled(OrderPriceContext $context): bool;

    public function apply(OrderPriceContext $context): OrderPriceContext;
}
