<?php
declare(strict_types=1);

namespace app\extension\pipeline;

use app\extension\order\OrderPriceContext;
use app\extension\registry\ExtensionRegistry;

class OrderPricePipeline
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null)
    {
    }

    public function apply(OrderPriceContext $context): OrderPriceContext
    {
        foreach ($this->registry()->orderPriceContributors() as $contributor) {
            if (!$contributor->enabled($context)) {
                continue;
            }
            $context = $contributor->apply($context);
        }

        return $context;
    }

    private function registry(): ExtensionRegistry
    {
        return $this->registry ?? app()->make(ExtensionRegistry::class);
    }
}
