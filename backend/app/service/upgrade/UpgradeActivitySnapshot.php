<?php

declare(strict_types=1);

namespace app\service\upgrade;

final readonly class UpgradeActivitySnapshot
{
    /** @param list<array<string,mixed>> $blockers */
    public function __construct(
        public int $activeHttp,
        public int $activeCallbacks,
        public int $activeCron,
        public int $queuePopInProgress,
        public int $activeQueue,
        public bool $uncertain,
        public array $blockers = [],
    ) {
    }

    public function activeTotal(): int
    {
        return $this->activeHttp + $this->activeCallbacks + $this->activeCron
            + $this->queuePopInProgress + $this->activeQueue;
    }
}
