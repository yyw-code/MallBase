<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\RedisServerIncarnation;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeRuntimeHeartbeatStore;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeLockPool;
use app\service\upgrade\UpgradeRuntimeRegistry;
use app\service\upgrade\UpgradeRuntimeRetirementEvidenceStore;
use app\service\upgrade\VerifiedUpgradeRuntimeRetirementGuard;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class VerifiedUpgradeRuntimeRetirementGuardTest extends TestCase
{
    public function testSigstopProcessHoldingLiveSharedLockCannotBeRetired(): void
    {
        $events = new GuardEventLog();
        $registry = new GuardRuntimeRegistry($this->runtimeRecord(), $events);
        $heartbeats = new GuardHeartbeatStore($this->redisRecord(), $events);
        $evidence = new GuardEvidenceStore($events);
        $guard = $this->guard(
            $registry,
            $heartbeats,
            $evidence,
            new GuardLockPool($events, false),
        );
        $callbackRan = false;

        $retired = $guard->retireIfProven(
            $registry->record,
            1_031,
            static function () use (&$callbackRan): void {
                $callbackRan = true;
            },
        );

        self::assertFalse($retired);
        self::assertFalse($callbackRan);
        self::assertSame(0, $evidence->tombstoneCalls);
        self::assertSame([
            'registry.active',
            'heartbeat.find',
            'evidence.observe',
            'lock.try',
            'lock.live_shared',
        ], $events->events);
    }

    public function testExclusiveLockRecheckRejectsFileOrRedisMovement(): void
    {
        $events = new GuardEventLog();
        $registry = new GuardRuntimeRegistry($this->runtimeRecord(), $events);
        $heartbeats = new GuardHeartbeatStore($this->redisRecord(), $events);
        $evidence = new GuardEvidenceStore($events);
        $lock = new GuardLockPool(
            $events,
            true,
            static function () use ($registry, $heartbeats, $events): void {
                $registry->record['last_seen_at']++;
                $heartbeats->record['heartbeat_seq']++;
                $events->add('concurrent.heartbeat');
            },
        );
        $guard = $this->guard($registry, $heartbeats, $evidence, $lock);
        $callbackRan = false;

        $retired = $guard->retireIfProven(
            $registry->record,
            1_031,
            static function () use (&$callbackRan): void {
                $callbackRan = true;
            },
        );

        self::assertFalse($retired);
        self::assertFalse($callbackRan);
        self::assertSame(1, $evidence->tombstoneCalls);
        self::assertNotContains('evidence.durable_tombstone', $events->events);
        self::assertSame([
            'registry.active',
            'heartbeat.find',
            'evidence.observe',
            'lock.try',
            'lock.exclusive',
            'concurrent.heartbeat',
            'registry.active',
            'heartbeat.find',
            'evidence.tombstone_recheck',
            'lock.release',
        ], $events->events);
    }

    public function testCallbackRunsOnlyAfterExclusiveRecheckAndDurableTombstone(): void
    {
        $events = new GuardEventLog();
        $registry = new GuardRuntimeRegistry($this->runtimeRecord(), $events);
        $heartbeats = new GuardHeartbeatStore($this->redisRecord(), $events);
        $evidence = new GuardEvidenceStore($events);
        $guard = $this->guard(
            $registry,
            $heartbeats,
            $evidence,
            new GuardLockPool($events, true),
        );

        $retired = $guard->retireIfProven(
            $registry->record,
            1_031,
            static function () use ($events): void {
                $events->add('retirement.callback');
            },
        );

        self::assertTrue($retired);
        self::assertSame([
            'registry.active',
            'heartbeat.find',
            'evidence.observe',
            'lock.try',
            'lock.exclusive',
            'registry.active',
            'heartbeat.find',
            'evidence.tombstone_recheck',
            'evidence.durable_tombstone',
            'retirement.callback',
            'lock.release',
        ], $events->events);
    }

    private function guard(
        GuardRuntimeRegistry $registry,
        GuardHeartbeatStore $heartbeats,
        GuardEvidenceStore $evidence,
        GuardLockPool $locks,
    ): VerifiedUpgradeRuntimeRetirementGuard {
        return new VerifiedUpgradeRuntimeRetirementGuard(
            $registry,
            $heartbeats,
            $evidence,
            $locks,
            new RedisServerIncarnation(new stdClass(), static fn(): string => self::RUN_ID),
            15,
        );
    }

    /** @return array<string,mixed> */
    private function runtimeRecord(): array
    {
        return [
            'schema_version' => 2,
            'state' => 'active',
            'runtime_instance_id' => self::RUNTIME_ID,
            'boot_id' => self::BOOT_ID,
            'role' => 'http',
            'app_version' => '1.2.0',
            'deployment_id' => self::DEPLOYMENT_ID,
            'storage_layout_version' => 1,
            'storage_layout_generation' => 4,
            'observed_deployment_epoch' => 2,
            'boot_registration_revision' => 7,
            'activity_generation' => 11,
            'redis_incarnation' => self::RUN_ID,
            'queues' => [],
            'cron_enabled' => false,
            'observed_gate_revision' => 7,
            'identity_fenced' => false,
            'paused_ack_revision' => null,
            'slot_id' => 'slot-http',
            'registered_at' => 900,
            'last_seen_at' => 1_000,
            'retired_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function redisRecord(): array
    {
        return [
            'owner_key' => self::RUNTIME_ID . ':' . self::BOOT_ID . ':http',
            'last_seen_at' => 1_000,
            'expires_at' => 1_015,
            'heartbeat_seq' => 1,
            'redis_incarnation' => self::RUN_ID,
        ];
    }

    private const RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const RUNTIME_ID = '218f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_ID = '318f5d35-3f42-7a31-a731-9e45df3356c2';
    private const DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}

final class GuardEventLog
{
    /** @var list<string> */
    public array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }
}

final class GuardRuntimeRegistry implements UpgradeRuntimeRegistry
{
    /** @param array<string,mixed> $record */
    public function __construct(
        public array $record,
        private readonly GuardEventLog $events,
    ) {
    }

    public function active(): array
    {
        $this->events->add('registry.active');

        return [$this->record];
    }

    public function register(UpgradeRuntimeInstance $instance, array $queues, bool $cronEnabled, UpgradeGateSnapshot $gate, string $slotId): array
    {
        throw new LogicException('not used');
    }

    public function heartbeat(UpgradeRuntimeInstance $instance, array $queues, bool $cronEnabled, UpgradeGateSnapshot $gate, bool $identityFenced, ?int $pausedAckRevision): array
    {
        throw new LogicException('not used');
    }

    public function retire(UpgradeRuntimeInstance $instance, int $retiredAt): array
    {
        throw new LogicException('not used');
    }
}

final class GuardHeartbeatStore implements UpgradeRuntimeHeartbeatStore
{
    /** @param array<string,mixed> $record */
    public function __construct(
        public array $record,
        private readonly GuardEventLog $events,
    ) {
    }

    public function heartbeat(UpgradeGateSnapshot $gate, array $runtimeRecord, int $now, int $ttl): array
    {
        throw new LogicException('not used');
    }

    public function find(string $ownerKey, string $expectedServerRunId): ?array
    {
        $this->events->add('heartbeat.find');

        return $this->record;
    }
}

final class GuardEvidenceStore implements UpgradeRuntimeRetirementEvidenceStore
{
    private ?string $fingerprint = null;
    public int $tombstoneCalls = 0;

    public function __construct(private readonly GuardEventLog $events)
    {
    }

    public function observe(array $runtimeRecord, ?array $redisRecord, int $now, int $windowSeconds): bool
    {
        $this->events->add('evidence.observe');
        $this->fingerprint = $this->fingerprint($runtimeRecord, $redisRecord);

        return true;
    }

    public function prepareIfUnchanged(array $runtimeRecord, ?array $redisRecord, int $now, int $windowSeconds): bool
    {
        $this->tombstoneCalls++;
        $this->events->add('evidence.tombstone_recheck');
        if ($this->fingerprint !== $this->fingerprint($runtimeRecord, $redisRecord)) {
            return false;
        }
        $this->events->add('evidence.durable_tombstone');

        return true;
    }

    public function pending(): array
    {
        return [];
    }

    public function advance(string $ownerKey, string $expectedState, string $nextState, int $now): void
    {
    }

    public function reset(string $ownerKey): void
    {
        $this->fingerprint = null;
    }

    private function fingerprint(array $runtimeRecord, ?array $redisRecord): string
    {
        return json_encode([
            $runtimeRecord['last_seen_at'] ?? null,
            $runtimeRecord['observed_gate_revision'] ?? null,
            $redisRecord['last_seen_at'] ?? null,
            $redisRecord['heartbeat_seq'] ?? null,
        ], JSON_THROW_ON_ERROR);
    }
}

final readonly class GuardLockPool implements UpgradeRuntimeLockPool
{
    public function __construct(
        private GuardEventLog $events,
        private bool $lockable,
        private ?Closure $afterExclusive = null,
    ) {
    }

    public function tryRetire(array $runtimeRecord, Closure $retire): bool
    {
        $this->events->add('lock.try');
        if (!$this->lockable) {
            $this->events->add('lock.live_shared');

            return false;
        }
        $this->events->add('lock.exclusive');
        if ($this->afterExclusive !== null) {
            ($this->afterExclusive)();
        }
        $retire();
        $this->events->add('lock.release');

        return true;
    }
}
