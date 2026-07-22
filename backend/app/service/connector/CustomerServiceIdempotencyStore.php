<?php
declare(strict_types=1);

namespace app\service\connector;

use RuntimeException;
use think\facade\Cache;

/**
 * 客服连接器严格幂等 Redis 存储。
 *
 * 只提供原子抢占、读取和持有者条件状态迁移，不提供无条件释放能力。
 */
class CustomerServiceIdempotencyStore
{
    private const CLAIM_SCRIPT = <<<'LUA'
if redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2], 'NX') then
    return 1
end
return 0
LUA;

    private const READ_SCRIPT = <<<'LUA'
return redis.call('GET', KEYS[1])
LUA;

    private const TRANSITION_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then
    return 0
end
redis.call('SET', KEYS[1], ARGV[2], 'EX', ARGV[3])
return 1
LUA;

    public function claim(string $logicalKey, string $processingState, int $ttl): bool
    {
        $this->assertArguments($logicalKey, $ttl);

        try {
            [$store, $redisKey, $predis] = $this->connection($logicalKey);
            $result = $predis
                ? $this->predisCommand($store, 'eval', [
                    self::CLAIM_SCRIPT,
                    1,
                    $redisKey,
                    $processingState,
                    (string) $ttl,
                ])
                : $this->rawCommand($store, ['SET', $redisKey, $processingState, 'EX', (string) $ttl, 'NX']);

            return $predis
                ? (int) $result === 1
                : $result === true || strtoupper((string) $result) === 'OK';
        } catch (\Throwable $error) {
            throw new RuntimeException('客服连接器幂等 Redis 抢占失败', 0, $error);
        }
    }

    public function read(string $logicalKey): ?string
    {
        $this->assertArguments($logicalKey, 1);

        try {
            [$store, $redisKey, $predis] = $this->connection($logicalKey);
            $result = $predis
                ? $this->predisCommand($store, 'eval', [self::READ_SCRIPT, 1, $redisKey])
                : $this->rawCommand($store, ['GET', $redisKey]);
        } catch (\Throwable $error) {
            throw new RuntimeException('客服连接器幂等 Redis 读取失败', 0, $error);
        }

        if ($result === false || $result === null) {
            return null;
        }
        if (!is_string($result) && !$result instanceof \Stringable) {
            throw new RuntimeException('客服连接器幂等 Redis 返回了无效数据');
        }

        return (string) $result;
    }

    public function transition(
        string $logicalKey,
        string $expectedProcessingState,
        string $terminalState,
        int $ttl
    ): bool {
        $this->assertArguments($logicalKey, $ttl);

        try {
            [$store, $redisKey, $predis] = $this->connection($logicalKey);
            $arguments = [
                self::TRANSITION_SCRIPT,
                1,
                $redisKey,
                $expectedProcessingState,
                $terminalState,
                (string) $ttl,
            ];
            $result = $predis
                ? $this->predisCommand($store, 'eval', $arguments)
                : $this->rawCommand($store, [
                    'EVAL',
                    self::TRANSITION_SCRIPT,
                    '1',
                    $redisKey,
                    $expectedProcessingState,
                    $terminalState,
                    (string) $ttl,
                ]);

            return (int) $result === 1;
        } catch (\Throwable $error) {
            throw new RuntimeException('客服连接器幂等 Redis 状态迁移失败', 0, $error);
        }
    }

    /**
     * @return array{0: object, 1: string, 2: bool}
     */
    protected function connection(string $logicalKey): array
    {
        $store = $this->cacheStore();
        $handler = $store->handler();
        if (!is_object($handler)) {
            throw new RuntimeException('默认缓存驱动未提供 Redis 客户端');
        }

        $predis = $this->isPredisHandler($handler);
        $redisKey = $predis ? $logicalKey : (string) $store->getCacheKey($logicalKey);
        if ($redisKey === '') {
            throw new RuntimeException('客服连接器幂等 Redis 键无效');
        }

        return [$store, $redisKey, $predis];
    }

    protected function cacheStore(): object
    {
        return Cache::store();
    }

    protected function isPredisHandler(object $handler): bool
    {
        return is_a($handler, 'Predis\\Client');
    }

    /**
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    protected function predisCommand(object $store, string $command, array $arguments)
    {
        return $store->{$command}(...$arguments);
    }

    /**
     * @param array<int, mixed> $command
     * @return mixed
     */
    protected function rawCommand(object $store, array $command)
    {
        return $store->rawCommand(...$command);
    }

    private function assertArguments(string $logicalKey, int $ttl): void
    {
        if ($logicalKey === '' || $ttl <= 0) {
            throw new RuntimeException('客服连接器幂等存储参数无效');
        }
    }
}
