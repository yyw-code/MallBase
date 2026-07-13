<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\FileUpgradeRuntimeRetirementEvidenceStore;
use app\service\upgrade\RedisServerIncarnation;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeRuntimeHeartbeatStore;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeLockPool;
use app\service\upgrade\UpgradeRuntimeRegistry;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\VerifiedUpgradeRuntimeRetirementGuard;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class UpgradeRuntimeRetirementEvidenceStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-runtime-retirement-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['config', 'run', 'state', 'jobs', 'backups'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        mkdir($this->root . '/staging', 0750);
        chmod($this->root . '/staging', 0750);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testTwoIndependentWindowsRestartOnAnyFileRedisHeartbeatOrSequenceChange(): void
    {
        $store = new FileUpgradeRuntimeRetirementEvidenceStore($this->files());
        $runtime = $this->runtimeRecord();
        $redis = $this->redisRecord();

        self::assertFalse($store->observe($runtime, $redis, 1_016, 15), 'first stale window only starts observation');
        self::assertFalse($store->observe($runtime, $redis, 1_030, 15));
        self::assertTrue($store->observe($runtime, $redis, 1_031, 15), 'second independent 15 second window is required');

        $runtime['last_seen_at'] = 1_001;
        self::assertFalse($store->observe($runtime, $redis, 1_031, 15), 'file heartbeat movement restarts evidence');
        self::assertFalse($store->observe($runtime, $redis, 1_045, 15));
        self::assertTrue($store->observe($runtime, $redis, 1_046, 15));

        $redis['last_seen_at'] = 1_001;
        $redis['expires_at'] = 1_016;
        self::assertFalse($store->observe($runtime, $redis, 1_046, 15), 'Redis heartbeat movement restarts evidence');
        self::assertFalse($store->observe($runtime, $redis, 1_060, 15));
        self::assertTrue($store->observe($runtime, $redis, 1_061, 15));

        $redis['heartbeat_seq'] = 2;
        self::assertFalse($store->observe($runtime, $redis, 1_061, 15), 'Redis sequence movement restarts evidence');
        self::assertFalse($store->observe($runtime, $redis, 1_075, 15));
        self::assertTrue($store->observe($runtime, $redis, 1_076, 15));
        self::assertTrue($store->prepareIfUnchanged($runtime, $redis, 1_076, 15));
        self::assertSame('prepared', $store->pending()[0]['state']);
    }

    public function testDurabilityFailureBeforeParentFsyncPreventsRetirementCallback(): void
    {
        $parentFsyncCount = 0;
        $operations = $this->statOperations();
        $operations['fault'] = static function (string $checkpoint) use (&$parentFsyncCount): void {
            if ($checkpoint !== 'before_parent_fsync') {
                return;
            }
            $parentFsyncCount++;
            if ($parentFsyncCount === 3) {
                throw new \RuntimeException('injected-before-parent-fsync');
            }
        };
        $evidence = new FileUpgradeRuntimeRetirementEvidenceStore($this->files($operations));
        $runtime = $this->runtimeRecord();
        $redis = $this->redisRecord();
        self::assertFalse($evidence->observe($runtime, $redis, 1_016, 15));

        $guard = new VerifiedUpgradeRuntimeRetirementGuard(
            new EvidenceRuntimeRegistry($runtime),
            new EvidenceHeartbeatStore($redis),
            $evidence,
            new EvidenceLockPool(true),
            new RedisServerIncarnation(new stdClass(), static fn(): string => self::RUN_ID),
            15,
        );
        $callbackRan = false;

        try {
            $guard->retireIfProven($runtime, 1_031, static function () use (&$callbackRan): void {
                $callbackRan = true;
            });
            self::fail('retirement returned after tombstone durability became uncertain');
        } catch (\RuntimeException $exception) {
            self::assertSame('DURABILITY_UNCERTAIN', $exception->getMessage());
        }
        self::assertFalse($callbackRan, 'callback cannot run before file and parent directory fsync complete');
        self::assertSame(3, $parentFsyncCount);
    }

    public function testPreparedRetirementSagaAdvancesIdempotentlyAndOnlyCommittedEvidenceCanReset(): void
    {
        $store = new FileUpgradeRuntimeRetirementEvidenceStore($this->files());
        $runtime = $this->runtimeRecord();
        $redis = $this->redisRecord();
        $ownerKey = self::RUNTIME_ID . ':' . self::BOOT_ID . ':http';

        self::assertFalse($store->observe($runtime, $redis, 1_016, 15));
        self::assertTrue($store->observe($runtime, $redis, 1_031, 15));
        self::assertTrue($store->prepareIfUnchanged($runtime, $redis, 1_031, 15));

        $store->advance($ownerKey, 'prepared', 'registry_retired', 1_032);
        $store->advance($ownerKey, 'prepared', 'registry_retired', 1_032);
        self::assertSame('registry_retired', $store->pending()[0]['state']);
        $store->advance($ownerKey, 'registry_retired', 'gate_retired', 1_033);
        $store->advance($ownerKey, 'gate_retired', 'committed', 1_034);

        self::assertSame([], $store->pending());
        $store->reset($ownerKey);
        self::assertSame([], $store->pending());
    }

    public function testPreparedRetirementRejectsHeartbeatFingerprintMovementAsManualRecovery(): void
    {
        $store = new FileUpgradeRuntimeRetirementEvidenceStore($this->files());
        $runtime = $this->runtimeRecord();
        $redis = $this->redisRecord();

        self::assertFalse($store->observe($runtime, $redis, 1_016, 15));
        self::assertTrue($store->observe($runtime, $redis, 1_031, 15));
        self::assertTrue($store->prepareIfUnchanged($runtime, $redis, 1_031, 15));
        $runtime['last_seen_at'] = 999;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_RUNTIME_RETIREMENT_MANUAL_RECOVERY_REQUIRED');
        $store->observe($runtime, $redis, 1_031, 15);
    }

    /** @param array<string,callable>|null $operations */
    private function files(?array $operations = null): UpgradeSharedFileStore
    {
        return new UpgradeSharedFileStore(
            $this->root,
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            65536,
            100,
            $operations ?? $this->statOperations(),
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

    /** @return array<string,callable> */
    private function statOperations(): array
    {
        $owner = static fn(string $path, bool $directory): int => $directory ? self::AGENT_UID : self::PHP_UID;

        return [
            'lstat' => static function (string $path) use ($owner): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = self::SHARED_GID;
                    $stat['uid'] = $owner($path, ($stat['mode'] & 0170000) === 0040000);
                }

                return $stat;
            },
            'fstat' => static function ($handle) use ($owner): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $uri = (string) (stream_get_meta_data($handle)['uri'] ?? '');
                    $stat['gid'] = self::SHARED_GID;
                    $stat['uid'] = $owner($uri, ($stat['mode'] & 0170000) === 0040000);
                }

                return $stat;
            },
        ];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }

    private const AGENT_UID = 31001;
    private const SHARED_GID = 31002;
    private const PHP_UID = 31003;
    private const RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const RUNTIME_ID = '218f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_ID = '318f5d35-3f42-7a31-a731-9e45df3356c2';
    private const DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}

final readonly class EvidenceRuntimeRegistry implements UpgradeRuntimeRegistry
{
    /** @param array<string,mixed> $record */
    public function __construct(private array $record)
    {
    }

    public function active(): array
    {
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

final readonly class EvidenceHeartbeatStore implements UpgradeRuntimeHeartbeatStore
{
    /** @param array<string,mixed> $record */
    public function __construct(private array $record)
    {
    }

    public function heartbeat(UpgradeGateSnapshot $gate, array $runtimeRecord, int $now, int $ttl): array
    {
        throw new LogicException('not used');
    }

    public function find(string $ownerKey, string $expectedServerRunId): ?array
    {
        return $this->record;
    }
}

final readonly class EvidenceLockPool implements UpgradeRuntimeLockPool
{
    public function __construct(private bool $lockable)
    {
    }

    public function tryRetire(array $runtimeRecord, Closure $retire): bool
    {
        if (!$this->lockable) {
            return false;
        }
        $retire();

        return true;
    }
}
