<?php

declare(strict_types=1);

namespace app\service\upgrade;

final readonly class UpgradeRuntimeRegistration
{
    /** @param array<string,mixed> $record */
    public function __construct(
        public array $record,
        public UpgradeGateSnapshot $gate,
        public bool $mayAcceptBusinessWork,
    ) {
    }
}
