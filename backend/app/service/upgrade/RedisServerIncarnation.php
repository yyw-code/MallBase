<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use Throwable;

final class RedisServerIncarnation
{
    /** @var Closure():string */
    private readonly Closure $reader;

    public function __construct(private readonly object $redis, ?Closure $reader = null)
    {
        if ($reader === null && !$this->redis instanceof UpgradeRedisConnectionFactory
            && !method_exists($this->redis, 'info')) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_CONNECTION_IDENTITY_UNAVAILABLE');
        }
        $this->reader = $reader ?? function (): string {
            return $this->withConnection(fn(object $redis): string => $this->readNative($redis));
        };
    }

    public function current(): string
    {
        try {
            $reader = $this->reader;
            $runId = $reader();
        } catch (Throwable) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_INCARNATION_UNAVAILABLE');
        }
        if (!is_string($runId) || preg_match('/^[0-9a-f]{40}$/D', $runId) !== 1) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_INCARNATION_INVALID');
        }

        return $runId;
    }

    public function connectionIdentity(): RedisConnectionIdentity
    {
        return $this->withConnection(function (object $redis): RedisConnectionIdentity {
            try {
                $beforeRunId = $this->readNative($redis);
                $beforeClientId = $this->readClientId($redis);
                $afterRunId = $this->readNative($redis);
                $afterClientId = $this->readClientId($redis);
            } catch (Throwable $exception) {
                if ($exception instanceof UpgradeStateConflict) {
                    throw $exception;
                }
                throw new UpgradeStateConflict('UPGRADE_REDIS_CONNECTION_IDENTITY_UNAVAILABLE');
            }

            if (!hash_equals($beforeRunId, $afterRunId) || $beforeClientId !== $afterClientId) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_CONNECTION_CHANGED');
            }

            return new RedisConnectionIdentity($beforeRunId, $beforeClientId);
        });
    }

    private function readNative(object $redis): string
    {
        if (!method_exists($redis, 'info')) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_INCARNATION_UNAVAILABLE');
        }
        $info = $redis->info('server');
        if (!is_array($info)) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_INCARNATION_UNAVAILABLE');
        }

        return (string) ($info['run_id'] ?? $info['redis_run_id'] ?? '');
    }

    private function readClientId(object $redis): int
    {
        $clientId = $redis->client('ID');
        if (!is_int($clientId) && !(is_string($clientId) && ctype_digit($clientId))) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_CONNECTION_IDENTITY_UNAVAILABLE');
        }

        $value = (int) $clientId;
        if ($value < 1) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_CONNECTION_IDENTITY_UNAVAILABLE');
        }

        return $value;
    }

    /** @template T @param Closure(object):T $callback @return T */
    private function withConnection(Closure $callback): mixed
    {
        $owned = $this->redis instanceof UpgradeRedisConnectionFactory;
        $redis = $owned ? $this->redis->create() : $this->redis;
        try {
            return $callback($redis);
        } finally {
            if ($owned && method_exists($redis, 'close')) {
                try {
                    $redis->close();
                } catch (Throwable) {
                }
            }
        }
    }
}
