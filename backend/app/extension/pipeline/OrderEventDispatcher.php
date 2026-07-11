<?php
declare(strict_types=1);

namespace app\extension\pipeline;

use app\extension\order\OrderEventContext;
use app\extension\registry\ExtensionRegistry;

class OrderEventDispatcher
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null)
    {
    }

    public function dispatch(OrderEventContext $context): void
    {
        foreach ($this->registry()->orderEventListeners($context->event()) as $listener) {
            if (!$listener->enabled($context)) {
                continue;
            }
            $listener->handle($context);
        }
    }

    private function registry(): ExtensionRegistry
    {
        return $this->registry ?? app()->make(ExtensionRegistry::class);
    }
}
