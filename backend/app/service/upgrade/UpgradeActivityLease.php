<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;

final class UpgradeActivityLease
{
    /** @var Closure(string,string):void */
    private readonly Closure $releaser;
    private bool $released = false;

    /** @param Closure(string,string):void $releaser */
    public function __construct(
        public readonly string $entryId,
        private string $token,
        Closure $releaser,
        public readonly bool $untracked = false,
        public readonly string $executionAttemptId = '',
        public readonly int $activityGeneration = 0,
        public readonly string $redisIncarnation = '',
    ) {
        $this->releaser = $releaser;
    }

    public static function untracked(): self
    {
        return new self('untracked', '', static function (): void {
        }, true);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $releaser = $this->releaser;
        $releaser($this->entryId, $this->token);
        $this->released = true;
    }

    public function replaceToken(string $token): void
    {
        if ($this->released || $this->untracked || preg_match('/^[0-9a-f]{32}$/D', $token) !== 1) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_LEASE_INVALID');
        }
        $this->token = $token;
    }

    public function token(): string
    {
        return $this->token;
    }
}
