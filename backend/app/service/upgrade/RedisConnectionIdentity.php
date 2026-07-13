<?php

declare(strict_types=1);

namespace app\service\upgrade;

use InvalidArgumentException;

final readonly class RedisConnectionIdentity
{
    public function __construct(public string $runId, public int $clientId)
    {
        if (preg_match('/^[0-9a-f]{40}$/D', $this->runId) !== 1 || $this->clientId < 1) {
            throw new InvalidArgumentException('UPGRADE_REDIS_CONNECTION_IDENTITY_INVALID');
        }
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->runId, $other->runId) && $this->clientId === $other->clientId;
    }
}
