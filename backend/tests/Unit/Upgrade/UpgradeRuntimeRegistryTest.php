<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\FileUpgradeRuntimeRegistry;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeState;
use PHPUnit\Framework\TestCase;

final class UpgradeRuntimeRegistryTest extends TestCase
{
    private string $root;
    private FileUpgradeRuntimeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-runtime-registry-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['config', 'run', 'state', 'jobs', 'backups'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        mkdir($this->root . '/run/runtime-instances', 02770);
        chmod($this->root . '/run/runtime-instances', 02770);
        mkdir($this->root . '/staging', 0750);
        chmod($this->root . '/staging', 0750);
        $files = new UpgradeSharedFileStore(
            $this->root,
            self::AGENT_UID,
            self::SHARED_GID,
            self::PHP_UID,
            65536,
            100,
            $this->statOperations(),
        );
        $this->registry = new FileUpgradeRuntimeRegistry($files, static fn(): int => 1000);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testMultipleBootsAndRolesRemainDistinctAndDurable(): void
    {
        $httpA = $this->owner(self::BOOT_A, 'http');
        $httpB = $this->owner(self::BOOT_B, 'http');
        $queueA = $this->owner(self::BOOT_A, 'queue');
        $gate = $this->gate(7);

        $this->registry->register($httpA, [], false, $gate, 'slot-a');
        $this->registry->register($httpB, [], false, $gate, 'slot-b');
        $this->registry->register($queueA, ['default'], false, $gate, 'slot-c');

        $records = $this->registry->active();
        self::assertCount(3, $records);
        self::assertSame(['http', 'queue', 'http'], array_column($records, 'role'));
        self::assertNotSame($records[0]['boot_id'], $records[2]['boot_id']);
        foreach ($records as $record) {
            self::assertSame(self::DEPLOYMENT_ID, $record['deployment_id']);
            self::assertSame(4, $record['storage_layout_generation']);
            self::assertSame(7, $record['boot_registration_revision']);
            self::assertSame(11, $record['activity_generation']);
            self::assertSame(str_repeat('a', 40), $record['redis_incarnation']);
        }
    }

    public function testHeartbeatNeverReplacesImmutableBootIdentity(): void
    {
        $owner = $this->owner(self::BOOT_A, 'http');
        $this->registry->register($owner, [], false, $this->gate(7), 'slot-a');
        $updated = $this->registry->heartbeat($owner, [], false, $this->gate(8), false, null);
        self::assertSame(8, $updated['observed_gate_revision']);
        self::assertSame(7, $updated['boot_registration_revision']);
        self::assertSame(11, $updated['activity_generation']);
        self::assertSame(str_repeat('a', 40), $updated['redis_incarnation']);
        self::assertSame(1000, $updated['last_seen_at']);

        $wrong = new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_A,
            'http',
            new UpgradeRuntimeIdentity('1.2.0', self::WRONG_DEPLOYMENT_ID, 1, 4),
            2,
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_RUNTIME_IDENTITY_CONFLICT');
        $this->registry->heartbeat($wrong, [], false, $this->gate(9), true, null);
    }

    public function testHeartbeatFencesGenerationMismatchWithoutRewritingBootRegistration(): void
    {
        $owner = $this->owner(self::BOOT_A, 'http');
        $this->registry->register($owner, [], false, $this->gate(7), 'slot-a');

        $mismatched = $this->registry->heartbeat(
            $owner,
            [],
            false,
            $this->gate(9, 12, str_repeat('b', 40)),
            false,
            null,
        );
        self::assertTrue($mismatched['identity_fenced']);
        self::assertSame(7, $mismatched['boot_registration_revision']);
        self::assertSame(11, $mismatched['activity_generation']);
        self::assertSame(str_repeat('a', 40), $mismatched['redis_incarnation']);
        self::assertSame(9, $mismatched['observed_gate_revision']);

        $matchingAgain = $this->registry->heartbeat($owner, [], false, $this->gate(10), false, null);
        self::assertTrue($matchingAgain['identity_fenced'], 'a fenced boot cannot be healed by a later heartbeat');
        self::assertSame(7, $matchingAgain['boot_registration_revision']);
        self::assertSame(11, $matchingAgain['activity_generation']);
        self::assertSame(str_repeat('a', 40), $matchingAgain['redis_incarnation']);
    }

    public function testIdempotentRegisterCannotRewriteImmutableBootLineage(): void
    {
        $owner = $this->owner(self::BOOT_A, 'http');
        $first = $this->registry->register($owner, [], false, $this->gate(7), 'slot-a');
        $replayed = $this->registry->register(
            $owner,
            [],
            false,
            $this->gate(99, 12, str_repeat('b', 40)),
            'slot-a',
        );

        self::assertSame($first, $replayed);
        self::assertSame(7, $replayed['boot_registration_revision']);
        self::assertSame(11, $replayed['activity_generation']);
        self::assertSame(str_repeat('a', 40), $replayed['redis_incarnation']);
    }

    public function testPreBarrierBootCannotBecomeCleanThroughALateHeartbeat(): void
    {
        $owner = $this->owner(self::BOOT_A, 'http');
        $beforeBarrier = $this->gate(
            7,
            uncertain: true,
            uncertainRevision: 7,
        );
        $registered = $this->registry->register($owner, [], false, $beforeBarrier, 'slot-a');
        self::assertFalse($registered['identity_fenced']);

        $barrier = $this->gate(
            8,
            12,
            str_repeat('b', 40),
            uncertain: true,
            uncertainRevision: 7,
            replacementBarrierRevision: 8,
        );
        $observed = $this->registry->heartbeat($owner, [], false, $barrier, false, null);

        self::assertTrue($observed['identity_fenced']);
        self::assertSame(7, $observed['boot_registration_revision']);
        self::assertSame(11, $observed['activity_generation']);
        self::assertSame(str_repeat('a', 40), $observed['redis_incarnation']);
        self::assertSame(8, $observed['observed_gate_revision']);
    }

    public function testBootRegisteredFromBarrierSnapshotIsACleanReplacement(): void
    {
        $owner = $this->owner(self::BOOT_B, 'http');
        $barrier = $this->gate(
            8,
            12,
            str_repeat('b', 40),
            uncertain: true,
            uncertainRevision: 7,
            replacementBarrierRevision: 8,
        );

        $registered = $this->registry->register($owner, [], false, $barrier, 'slot-b');

        self::assertFalse(
            $registered['identity_fenced'],
            'a boot registered from the atomically published barrier snapshot is a clean replacement',
        );
        self::assertSame(8, $registered['boot_registration_revision']);
        self::assertSame(12, $registered['activity_generation']);
        self::assertSame(str_repeat('b', 40), $registered['redis_incarnation']);
    }

    public function testPausedAckAndRetirementAreMonotonicTombstones(): void
    {
        $owner = $this->owner(self::BOOT_A, 'queue');
        $this->registry->register($owner, ['default'], false, $this->gate(7), 'slot-a');
        $acked = $this->registry->heartbeat(
            $owner,
            ['default'],
            false,
            $this->gate(8, state: UpgradeState::Paused),
            false,
            8,
        );
        self::assertSame(8, $acked['paused_ack_revision']);

        $retired = $this->registry->retire($owner, 1100);
        self::assertSame('retired', $retired['state']);
        self::assertSame(1100, $retired['retired_at']);
        self::assertSame([], $this->registry->active());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_RUNTIME_RETIRED');
        $this->registry->register($owner, ['default'], false, $this->gate(9), 'slot-a');
    }

    public function testMalformedSharedRuntimeDocumentFailsClosed(): void
    {
        $owner = $this->owner(self::BOOT_A, 'http');
        $this->registry->register($owner, [], false, $this->gate(7), 'slot-a');
        $file = $this->root . '/run/runtime-instances/' . $this->fileName($owner);
        file_put_contents($file, '{"schema_version":2}');
        chmod($file, 0660);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_RUNTIME_RECORD_INVALID');
        $this->registry->active();
    }

    private function owner(string $boot, string $role): UpgradeRuntimeInstance
    {
        return new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            $boot,
            $role,
            new UpgradeRuntimeIdentity('1.2.0', self::DEPLOYMENT_ID, 1, 4),
            2,
        );
    }

    private function gate(
        int $revision,
        int $activityGeneration = 11,
        string $redisIncarnation = '',
        UpgradeState $state = UpgradeState::Normal,
        bool $uncertain = false,
        ?int $uncertainRevision = null,
        ?int $replacementBarrierRevision = null,
    ): UpgradeGateSnapshot {
        return new UpgradeGateSnapshot(
            $state,
            $revision,
            $state === UpgradeState::Normal ? null : self::JOB_ID,
            '1.2.0',
            self::DEPLOYMENT_ID,
            1,
            4,
            2,
            $activityGeneration,
            $redisIncarnation === '' ? str_repeat('a', 40) : $redisIncarnation,
            $uncertain,
            [],
            false,
            $uncertain ? 'ACTIVITY_STATE_UNCERTAIN' : null,
            1_000,
            $uncertainRevision,
            $replacementBarrierRevision,
        );
    }

    private function fileName(UpgradeRuntimeInstance $owner): string
    {
        return $owner->runtimeInstanceId . '-' . $owner->bootId . '-' . $owner->role . '.json';
    }

    /** @return array<string,callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => static function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = self::SHARED_GID;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? self::AGENT_UID : self::PHP_UID;
                }

                return $stat;
            },
            'fstat' => static function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $stat['gid'] = self::SHARED_GID;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? self::AGENT_UID : self::PHP_UID;
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
    private const JOB_ID = '018f5d35-3f42-7a31-a731-9e45df3356c2';
    private const RUNTIME_ID = '218f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_A = '318f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_B = '418f5d35-3f42-7a31-a731-9e45df3356c2';
    private const DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
    private const WRONG_DEPLOYMENT_ID = 'b475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}
