<?php
declare(strict_types=1);

namespace app\extension\contracts;

use app\extension\order\OrderEventContext;

interface OrderEventListenerInterface
{
    public function code(): string;

    public function supports(string $event): bool;

    public function priority(string $event): int;

    public function enabled(OrderEventContext $context): bool;

    public function handle(OrderEventContext $context): void;
}
