<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Connector;

use app\service\connector\CustomerServiceIdempotencyStore;
use PHPUnit\Framework\TestCase;

final class CustomerServiceIdempotencyStoreTest extends TestCase
{
    public function testPhpRedisRawCommandsUseTheCacheDriverPhysicalPrefixAndOwnerCas(): void
    {
        $handler = new StatefulRawRedisHandler();
        $cacheStore = new FakeCacheStore($handler, 'mallbase-test:');
        $store = new TestableCustomerServiceIdempotencyStore($cacheStore, false);

        $this->assertTrue($store->claim('connector:idem:1', 'processing-owner-a', 60));
        $this->assertFalse($store->claim('connector:idem:1', 'processing-owner-b', 60));
        $this->assertSame('processing-owner-a', $store->read('connector:idem:1'));

        $this->assertFalse($store->transition(
            'connector:idem:1',
            'processing-owner-b',
            'succeeded-by-stale-owner',
            60
        ));
        $this->assertSame('processing-owner-a', $store->read('connector:idem:1'));

        $this->assertTrue($store->transition(
            'connector:idem:1',
            'processing-owner-a',
            'succeeded-owner-a',
            60
        ));
        $this->assertSame('succeeded-owner-a', $store->read('connector:idem:1'));

        $this->assertSame(
            ['SET', 'mallbase-test:connector:idem:1', 'processing-owner-a', 'EX', '60', 'NX'],
            $handler->commands[0]
        );
        $this->assertSame(
            array_fill(0, count($handler->commands), 'rawCommand'),
            array_column($cacheStore->forwardedCalls, 0)
        );
        $this->assertArrayHasKey('mallbase-test:connector:idem:1', $handler->values);
        $this->assertArrayNotHasKey('connector:idem:1', $handler->values);

        $evalCommands = array_values(array_filter(
            $handler->commands,
            static fn(array $command): bool => ($command[0] ?? null) === 'EVAL'
        ));
        $this->assertCount(2, $evalCommands);
        $this->assertSame('mallbase-test:connector:idem:1', $evalCommands[0][3]);
        $this->assertSame('processing-owner-b', $evalCommands[0][4]);
        $this->assertSame('processing-owner-a', $evalCommands[1][4]);
    }

    public function testPredisUsesLuaEvalThroughTheCacheStoreProxyAndAppliesTheClientPrefix(): void
    {
        $handler = new StatefulPredisHandler('mallbase-predis:');
        $cacheStore = new FakeCacheStore($handler, 'driver-prefix-must-not-be-doubled:');
        $store = new TestableCustomerServiceIdempotencyStore($cacheStore, true);

        $this->assertTrue($store->claim('connector:idem:2', 'processing-owner-a', 90));
        $this->assertFalse($store->claim('connector:idem:2', 'processing-owner-b', 90));
        $this->assertSame('processing-owner-a', $store->read('connector:idem:2'));
        $this->assertTrue($store->transition(
            'connector:idem:2',
            'processing-owner-a',
            'uncertain-owner-a',
            90
        ));
        $this->assertSame('uncertain-owner-a', $store->read('connector:idem:2'));

        $this->assertSame(array_fill(0, 5, 'eval'), array_column($handler->commands, 0));
        $this->assertSame(array_fill(0, 5, 'eval'), array_column($cacheStore->forwardedCalls, 0));
        $this->assertSame('connector:idem:2', $handler->commands[0][3]);
        $this->assertSame('processing-owner-a', $handler->commands[0][4]);
        $this->assertSame('90', $handler->commands[0][5]);
        $this->assertArrayHasKey('mallbase-predis:connector:idem:2', $handler->values);
        $this->assertArrayNotHasKey('connector:idem:2', $handler->values);
        $this->assertArrayNotHasKey(
            'driver-prefix-must-not-be-doubled:connector:idem:2',
            $handler->values
        );
    }

    public function testCacheStoreMagicProxyLetsRedisCommandExceptionsReachTheStoreWrapper(): void
    {
        $handler = new ThrowingRawRedisHandler();
        $cacheStore = new FakeCacheStore($handler, 'mallbase-test:');
        $store = new TestableCustomerServiceIdempotencyStore($cacheStore, false);

        try {
            $store->claim('connector:idem:error', 'processing-owner', 60);
            self::fail('A Redis command exception was swallowed by the cache store proxy.');
        } catch (\RuntimeException $error) {
            $this->assertSame('客服连接器幂等 Redis 抢占失败', $error->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $error->getPrevious());
            $this->assertSame('redis connection lost', $error->getPrevious()?->getMessage());
        }

        $this->assertSame([['rawCommand', ['SET', 'mallbase-test:connector:idem:error', 'processing-owner', 'EX', '60', 'NX']]], $cacheStore->forwardedCalls);
        $this->assertSame(1, $handler->calls);
    }

    public function testCacheStoreMagicProxyLetsPredisEvalExceptionsReachTheStoreWrapper(): void
    {
        $handler = new ThrowingPredisHandler();
        $cacheStore = new FakeCacheStore($handler, 'unused-driver-prefix:');
        $store = new TestableCustomerServiceIdempotencyStore($cacheStore, true);

        try {
            $store->read('connector:idem:error');
            self::fail('A Predis eval exception was swallowed by the cache store proxy.');
        } catch (\RuntimeException $error) {
            $this->assertSame('客服连接器幂等 Redis 读取失败', $error->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $error->getPrevious());
            $this->assertSame('predis connection lost', $error->getPrevious()?->getMessage());
        }

        $this->assertCount(1, $cacheStore->forwardedCalls);
        $this->assertSame('eval', $cacheStore->forwardedCalls[0][0]);
        $this->assertSame(1, $handler->calls);
    }
}

final class TestableCustomerServiceIdempotencyStore extends CustomerServiceIdempotencyStore
{
    public function __construct(
        private readonly object $store,
        private readonly bool $predis
    ) {
    }

    protected function cacheStore(): object
    {
        return $this->store;
    }

    protected function isPredisHandler(object $handler): bool
    {
        return $this->predis;
    }
}

final class FakeCacheStore
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $forwardedCalls = [];

    public function __construct(
        private readonly object $redisHandler,
        private readonly string $prefix
    ) {
    }

    public function handler(): object
    {
        return $this->redisHandler;
    }

    public function getCacheKey(string $logicalKey): string
    {
        return $this->prefix . $logicalKey;
    }

    /**
     * Match ThinkPHP's cache Driver proxy: Redis commands not implemented by
     * the Driver are forwarded to the underlying client through __call().
     *
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments)
    {
        $this->forwardedCalls[] = [$method, $arguments];
        return $this->redisHandler->{$method}(...$arguments);
    }
}

final class StatefulRawRedisHandler
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var array<int, array<int, mixed>> */
    public array $commands = [];

    public function rawCommand(mixed ...$command): mixed
    {
        $this->commands[] = $command;
        $name = strtoupper((string) ($command[0] ?? ''));

        if ($name === 'SET') {
            $key = (string) $command[1];
            if (in_array('NX', $command, true) && array_key_exists($key, $this->values)) {
                return false;
            }
            $this->values[$key] = (string) $command[2];
            return 'OK';
        }
        if ($name === 'GET') {
            return $this->values[(string) $command[1]] ?? false;
        }
        if ($name === 'EVAL') {
            $key = (string) $command[3];
            $expected = (string) $command[4];
            if (($this->values[$key] ?? null) !== $expected) {
                return 0;
            }
            $this->values[$key] = (string) $command[5];
            return 1;
        }

        throw new \RuntimeException('Unexpected Redis command: ' . $name);
    }
}

final class StatefulPredisHandler
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var array<int, array<int, mixed>> */
    public array $commands = [];

    public function __construct(private readonly string $prefix)
    {
    }

    public function eval(string $script, int $keyCount, string $key, string ...$arguments): mixed
    {
        $this->commands[] = ['eval', $script, $keyCount, $key, ...$arguments];
        $physicalKey = $this->prefix . $key;

        if (str_contains($script, "'NX'")) {
            if (array_key_exists($physicalKey, $this->values)) {
                return 0;
            }
            $this->values[$physicalKey] = $arguments[0];
            return 1;
        }
        if ($arguments === []) {
            return $this->values[$physicalKey] ?? null;
        }

        [$expected, $terminal] = $arguments;
        if (($this->values[$physicalKey] ?? null) !== $expected) {
            return 0;
        }
        $this->values[$physicalKey] = $terminal;
        return 1;
    }
}

final class ThrowingRawRedisHandler
{
    public int $calls = 0;

    public function rawCommand(mixed ...$command): never
    {
        $this->calls++;
        throw new \RuntimeException('redis connection lost');
    }
}

final class ThrowingPredisHandler
{
    public int $calls = 0;

    public function eval(mixed ...$arguments): never
    {
        $this->calls++;
        throw new \RuntimeException('predis connection lost');
    }
}
