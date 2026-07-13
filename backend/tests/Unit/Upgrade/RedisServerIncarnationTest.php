<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\RedisServerIncarnation;
use app\service\upgrade\UpgradeRedisConnectionFactory;
use app\service\upgrade\UpgradeStateConflict;
use PHPUnit\Framework\TestCase;

final class RedisServerIncarnationTest extends TestCase
{
    public function testConnectionIdentityPinsRunIdAndClientId(): void
    {
        $redis = new TestConnectionIdentityRedis([str_repeat('a', 40), str_repeat('a', 40)], [17, 17]);

        $identity = (new RedisServerIncarnation($redis))->connectionIdentity();

        self::assertSame(str_repeat('a', 40), $identity->runId);
        self::assertSame(17, $identity->clientId);
    }

    public function testConnectionIdentityRejectsRunIdChangeWhileReading(): void
    {
        $redis = new TestConnectionIdentityRedis([str_repeat('a', 40), str_repeat('b', 40)], [17, 17]);

        $this->expectException(UpgradeStateConflict::class);
        $this->expectExceptionMessage('UPGRADE_REDIS_CONNECTION_CHANGED');
        (new RedisServerIncarnation($redis))->connectionIdentity();
    }

    public function testConnectionIdentityRejectsClientReconnectWhileReading(): void
    {
        $redis = new TestConnectionIdentityRedis([str_repeat('a', 40), str_repeat('a', 40)], [17, 18]);

        $this->expectException(UpgradeStateConflict::class);
        $this->expectExceptionMessage('UPGRADE_REDIS_CONNECTION_CHANGED');
        (new RedisServerIncarnation($redis))->connectionIdentity();
    }

    public function testFactoryConnectionIsClosedAfterEveryIdentityRead(): void
    {
        $factory = new TestConnectionIdentityFactory();
        $incarnation = new RedisServerIncarnation($factory);

        self::assertSame(str_repeat('a', 40), $incarnation->current());
        self::assertSame(str_repeat('a', 40), $incarnation->connectionIdentity()->runId);
        self::assertCount(2, $factory->connections);
        self::assertTrue($factory->connections[0]->closed);
        self::assertTrue($factory->connections[1]->closed);
    }
}

final class TestConnectionIdentityFactory implements UpgradeRedisConnectionFactory
{
    /** @var list<TestConnectionIdentityRedis> */
    public array $connections = [];

    public function create(): object
    {
        $connection = new TestConnectionIdentityRedis(
            [str_repeat('a', 40), str_repeat('a', 40)],
            [17, 17],
        );
        $this->connections[] = $connection;

        return $connection;
    }
}

final class TestConnectionIdentityRedis
{
    public bool $closed = false;

    /** @param list<string> $runIds @param list<int> $clientIds */
    public function __construct(private array $runIds, private array $clientIds)
    {
    }

    /** @return array{run_id:string} */
    public function info(string $section): array
    {
        self::assertSame('server', $section);

        return ['run_id' => array_shift($this->runIds) ?? ''];
    }

    public function client(string $command): int
    {
        self::assertSame('ID', $command);

        return array_shift($this->clientIds) ?? 0;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    private static function assertSame(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException('unexpected test command');
        }
    }
}
