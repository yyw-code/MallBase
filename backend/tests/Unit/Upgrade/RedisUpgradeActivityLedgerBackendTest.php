<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\RedisUpgradeActivityLedgerBackend;
use app\service\upgrade\RedisUpgradeActivityTracker;
use app\service\upgrade\RedisUpgradeRuntimeHeartbeatStore;
use app\service\upgrade\RedisServerIncarnation;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeState;
use app\service\upgrade\UpgradeStateConflict;
use PHPUnit\Framework\TestCase;

final class RedisUpgradeActivityLedgerBackendTest extends TestCase
{
    private mixed $process = null;
    private ?\Redis $redis = null;
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->redis instanceof \Redis) {
            try {
                $this->redis->close();
            } catch (\Throwable) {
            }
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process, 15);
            usleep(20_000);
            $status = proc_get_status($this->process);
            if (is_array($status) && ($status['running'] ?? false) === true) {
                proc_terminate($this->process, 9);
            }
            proc_close($this->process);
        }
        if ($this->root !== '' && is_dir($this->root)) {
            foreach (array_diff(scandir($this->root) ?: [], ['.', '..']) as $entry) {
                @unlink($this->root . '/' . $entry);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testLuaLedgerAtomicallyChecksGateIdentityAndIntegrity(): void
    {
        $this->startRedis();
        $runId = (string) ($this->redis?->info('server')['run_id'] ?? '');
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $runId);

        $backend = new RedisUpgradeActivityLedgerBackend($this->redis, 'mbs_lua_test');
        $gate = $this->gate($runId, UpgradeState::Normal);
        $this->redis?->set('mallbase:mbs_lua_test:upgrade:gate', json_encode($gate->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $backend->initialize(7, $runId);
        $owner = new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_ID,
            'http',
            new UpgradeRuntimeIdentity('1.2.0', self::DEPLOYMENT_ID, 1, 4),
            2,
        );
        $payload = [
            'kind' => 'http',
            'id' => 'request-1',
            'owner' => $owner->toArray(),
            'gate_epoch' => 2,
            'started_at' => 1000,
        ];

        $token = $backend->begin($gate, 'http:request-1', $payload, [UpgradeState::Normal]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $token);
        self::assertSame([$payload], $backend->snapshot(7, $runId));

        $draining = $this->gate($runId, UpgradeState::Draining);
        $this->redis?->set('mallbase:mbs_lua_test:upgrade:gate', json_encode($draining->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        self::assertNull($backend->begin($draining, 'http:request-2', [
            ...$payload,
            'id' => 'request-2',
        ], [UpgradeState::Normal]));

        $backend->release(7, $runId, 'http:request-1', (string) $token);
        $backend->release(7, $runId, 'http:request-1', (string) $token);
        self::assertSame([], $backend->snapshot(7, $runId));

        $raw = (string) $this->redis?->get('mallbase:mbs_lua_test:upgrade:activity-ledger');
        $document = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $document['expected_count'] = 1;
        $this->redis?->set('mallbase:mbs_lua_test:upgrade:activity-ledger', json_encode($document, JSON_THROW_ON_ERROR));
        $this->expectException(UpgradeStateConflict::class);
        $this->expectExceptionMessage('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        $backend->snapshot(7, $runId);
    }

    public function testPausedGateWinsAtomicRaceAgainstStaleCallbackAdmission(): void
    {
        $this->startRedis();
        $runId = (string) ($this->redis?->info('server')['run_id'] ?? '');
        $backend = new RedisUpgradeActivityLedgerBackend($this->redis, 'mbs_callback_race');
        $staleNormal = $this->gate($runId, UpgradeState::Normal);
        $this->redis?->set(
            'mallbase:mbs_callback_race:upgrade:gate',
            json_encode($staleNormal->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $backend->initialize(7, $runId);
        $owner = new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_ID,
            'http',
            new UpgradeRuntimeIdentity('1.2.0', self::DEPLOYMENT_ID, 1, 4),
            2,
        );
        $payload = [
            'kind' => 'callback',
            'id' => 'callback-race',
            'owner' => $owner->toArray(),
            'gate_epoch' => 2,
            'started_at' => 1_000,
        ];

        $paused = $this->gate($runId, UpgradeState::Paused);
        $this->redis?->set(
            'mallbase:mbs_callback_race:upgrade:gate',
            json_encode($paused->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        self::assertNull($backend->begin($staleNormal, 'callback:callback-race', $payload, [
            UpgradeState::Normal,
            UpgradeState::Preparing,
            UpgradeState::ReadyToDrain,
            UpgradeState::Draining,
            UpgradeState::Reconciling,
        ]));
        self::assertSame([], $backend->snapshot(7, $runId));
    }

    public function testConcurrentQueueBindCannotOverwriteWinningAttempt(): void
    {
        $this->startRedis();
        $runId = (string) ($this->redis?->info('server')['run_id'] ?? '');
        $backend = new RedisUpgradeActivityLedgerBackend($this->redis, 'mbs_queue_bind_race');
        $gate = $this->gate($runId, UpgradeState::Draining);
        $this->redis?->set(
            'mallbase:mbs_queue_bind_race:upgrade:gate',
            json_encode($gate->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $backend->initialize(7, $runId);
        $owner = new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_ID,
            'queue',
            new UpgradeRuntimeIdentity('1.2.0', self::DEPLOYMENT_ID, 1, 4),
            2,
        );
        $attempt = '418f5d35-3f42-7a31-a731-9e45df3356c2';
        $entryId = 'queue:' . $attempt;
        $pop = [
            'kind' => 'queue',
            'phase' => 'pop_in_progress',
            'execution_attempt_id' => $attempt,
            'worker_id' => 'worker-1',
            'connector_type' => 'redis',
            'queues' => ['default'],
            'owner' => $owner->toArray(),
            'gate_epoch' => 2,
            'started_at' => 1_000,
        ];
        $token = $backend->begin($gate, $entryId, $pop, [UpgradeState::Draining]);
        self::assertNotNull($token);

        $winner = [
            ...$pop,
            'phase' => 'bound',
            'connection' => 'redis',
            'queue' => 'default',
            'job_id' => 'job-winner',
            'bound_at' => 1_001,
        ];
        $loser = [
            ...$winner,
            'job_id' => 'job-stale-overwrite',
            'bound_at' => 1_002,
        ];

        self::assertNotNull($backend->bind(7, $runId, $entryId, (string) $token, $winner));
        self::assertNull($backend->bind(7, $runId, $entryId, (string) $token, $loser));
        self::assertSame([$winner], $backend->snapshot(7, $runId));
    }

    public function testWrongReleaseTokenIsReportedAndCannotEraseLiveEntry(): void
    {
        $this->startRedis();
        $runId = (string) ($this->redis?->info('server')['run_id'] ?? '');
        $backend = new RedisUpgradeActivityLedgerBackend($this->redis, 'mbs_release_conflict');
        $gate = $this->gate($runId, UpgradeState::Normal);
        $this->redis?->set(
            'mallbase:mbs_release_conflict:upgrade:gate',
            json_encode($gate->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $backend->initialize(7, $runId);
        $owner = new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_ID,
            'http',
            new UpgradeRuntimeIdentity('1.2.0', self::DEPLOYMENT_ID, 1, 4),
            2,
        );
        $payload = [
            'kind' => 'http',
            'id' => 'request-release-conflict',
            'owner' => $owner->toArray(),
            'gate_epoch' => 2,
            'started_at' => 1_000,
        ];
        $token = $backend->begin($gate, 'http:request-release-conflict', $payload, [UpgradeState::Normal]);
        self::assertNotNull($token);

        try {
            $backend->release(7, $runId, 'http:request-release-conflict', str_repeat('f', 32));
            self::fail('wrong release token was silently accepted');
        } catch (UpgradeStateConflict) {
        }
        self::assertSame([$payload], $backend->snapshot(7, $runId));
    }

    public function testExpiredWorkerHeartbeatEvidenceIsMarkedButNotDeleted(): void
    {
        $this->startRedis();
        $runId = (string) ($this->redis?->info('server')['run_id'] ?? '');
        $backend = new RedisUpgradeActivityLedgerBackend($this->redis, 'mbs_worker_evidence');
        $gate = $this->gate($runId, UpgradeState::Normal);
        $this->redis?->set(
            'mallbase:mbs_worker_evidence:upgrade:gate',
            json_encode($gate->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $backend->initialize(7, $runId);
        $owner = new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_ID,
            'queue',
            new UpgradeRuntimeIdentity('1.2.0', self::DEPLOYMENT_ID, 1, 4),
            2,
        );
        $worker = [
            'worker_id' => 'worker-1',
            'connector_type' => 'redis',
            'queues' => ['default'],
            ...$owner->toArray(),
            'owner_key' => $owner->key(),
            'activity_generation' => 7,
            'redis_incarnation' => $runId,
            'gate_revision' => $gate->revision,
            'paused_revision' => null,
            'last_seen_at' => 1_000,
            'expires_at' => 1_015,
        ];

        $backend->heartbeatWorker($gate, 'worker-1', $worker);
        $records = $backend->liveWorkers($gate, 1_031);

        self::assertCount(1, $records);
        self::assertTrue($records[0]['expired']);
        self::assertSame(1_015, $records[0]['expires_at']);
        self::assertIsString($this->redis?->hGet('mallbase:mbs_worker_evidence:upgrade:workers', 'worker-1'));
    }

    public function testTrackerPublishesAWorkerPayloadAcceptedByTheRealLedger(): void
    {
        $this->startRedis();
        $runId = (string) ($this->redis?->info('server')['run_id'] ?? '');
        $gate = $this->gate($runId, UpgradeState::Normal);
        $this->redis?->set(
            'mallbase:mbs_tracker_integration:upgrade:gate',
            json_encode($gate->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $incarnation = new RedisServerIncarnation($this->redis);
        $backend = new RedisUpgradeActivityLedgerBackend($this->redis, 'mbs_tracker_integration', $incarnation);
        $tracker = new RedisUpgradeActivityTracker(
            $backend,
            new BackendIntegrationGateRepository($gate),
            $incarnation,
            static fn(): int => 1_000,
        );
        $tracker->initializeLedger();
        $owner = new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_ID,
            'queue',
            new UpgradeRuntimeIdentity('1.2.0', self::DEPLOYMENT_ID, 1, 4),
            2,
        );

        $tracker->heartbeatWorker('worker-1', 'redis', ['default'], $owner, 15);
        $workers = $tracker->liveWorkers();

        self::assertCount(1, $workers);
        self::assertSame($owner->key(), $workers[0]['owner_key']);
        self::assertSame(7, $workers[0]['activity_generation']);
        self::assertSame($runId, $workers[0]['redis_incarnation']);
    }

    public function testRuntimeHeartbeatAtomicallyBindsRealGateAndLedgerAndPreservesOldEvidence(): void
    {
        $this->startRedis();
        $runId = (string) ($this->redis?->info('server')['run_id'] ?? '');
        $namespace = 'mbs_runtime_heartbeat_atomic';
        $gate = $this->gate($runId, UpgradeState::Normal);
        $gateKey = 'mallbase:' . $namespace . ':upgrade:gate';
        $ledgerKey = 'mallbase:' . $namespace . ':upgrade:activity-ledger';
        $this->redis?->set(
            $gateKey,
            json_encode($gate->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $incarnation = new RedisServerIncarnation($this->redis);
        $ledger = new RedisUpgradeActivityLedgerBackend($this->redis, $namespace, $incarnation);
        $ledger->initialize($gate->activityGeneration, $runId);
        $store = new RedisUpgradeRuntimeHeartbeatStore($this->redis, $namespace, $incarnation);
        $record = [
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
            'boot_registration_revision' => $gate->revision,
            'activity_generation' => $gate->activityGeneration,
            'redis_incarnation' => $runId,
            'queues' => [],
            'cron_enabled' => false,
            'observed_gate_revision' => $gate->revision,
            'identity_fenced' => false,
            'paused_ack_revision' => null,
            'slot_id' => 'slot-http',
            'registered_at' => 900,
            'last_seen_at' => 900,
            'retired_at' => null,
        ];
        $ownerKey = self::RUNTIME_ID . ':' . self::BOOT_ID . ':http';

        $first = $store->heartbeat($gate, $record, 1_000, 15);
        $second = $store->heartbeat($gate, $record, 1_001, 15);
        self::assertSame(1, $first['heartbeat_seq']);
        self::assertSame(2, $second['heartbeat_seq']);

        $newerGate = $this->gate($runId, UpgradeState::Draining);
        $this->redis?->set(
            $gateKey,
            json_encode($newerGate->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        try {
            $store->heartbeat($gate, $record, 1_002, 15);
            self::fail('a stale gate heartbeat crossed the atomic Redis check');
        } catch (UpgradeStateConflict) {
        }
        $afterGateRace = $store->find($ownerKey, $runId);
        self::assertSame(2, $afterGateRace['heartbeat_seq'] ?? null);
        self::assertSame(1_001, $afterGateRace['last_seen_at'] ?? null);

        $this->redis?->set(
            $gateKey,
            json_encode($gate->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $this->redis?->del($ledgerKey);
        try {
            $store->heartbeat($gate, $record, 1_003, 15);
            self::fail('a heartbeat was accepted without the bound activity ledger');
        } catch (UpgradeStateConflict) {
        }
        $afterLedgerLoss = $store->find($ownerKey, $runId);
        self::assertSame(2, $afterLedgerLoss['heartbeat_seq'] ?? null);
        self::assertSame(1_001, $afterLedgerLoss['last_seen_at'] ?? null);
        self::assertSame(1_016, $afterLedgerLoss['expires_at'] ?? null);
    }

    private function startRedis(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is unavailable');
        }
        $binary = trim((string) shell_exec('command -v redis-server 2>/dev/null'));
        if ($binary === '' || !is_executable($binary)) {
            self::markTestSkipped('redis-server is unavailable');
        }
        $this->root = sys_get_temp_dir() . '/mallbase-activity-redis-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $socket = $this->root . '/redis.sock';
        $log = $this->root . '/redis.log';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];
        $this->process = proc_open([
            $binary,
            '--save', '',
            '--appendonly', 'no',
            '--port', '0',
            '--unixsocket', $socket,
            '--unixsocketperm', '700',
        ], $descriptors, $pipes, null, [], ['bypass_shell' => true]);
        if (!is_resource($this->process)) {
            self::fail('failed to start redis-server');
        }
        $deadline = hrtime(true) + 2_000_000_000;
        while (!file_exists($socket) && hrtime(true) < $deadline) {
            usleep(10_000);
        }
        if (!file_exists($socket)) {
            self::fail('redis-server did not create its socket');
        }
        $this->redis = new \Redis();
        self::assertTrue($this->redis->connect($socket, 0, 1.0));
    }

    private function gate(string $runId, UpgradeState $state): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $state,
            $state === UpgradeState::Normal ? 1 : 2,
            $state === UpgradeState::Normal ? null : self::JOB_ID,
            '1.2.0',
            self::DEPLOYMENT_ID,
            1,
            4,
            2,
            7,
            $runId,
            false,
            [],
            false,
            null,
            1_000,
        );
    }

    private const JOB_ID = '018f5d35-3f42-7a31-a731-9e45df3356c2';
    private const RUNTIME_ID = '218f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_ID = '318f5d35-3f42-7a31-a731-9e45df3356c2';
    private const DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}

final readonly class BackendIntegrationGateRepository implements UpgradeGateRepository
{
    public function __construct(private UpgradeGateSnapshot $snapshot)
    {
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        return $this->snapshot;
    }

    public function compareAndSet(int $expectedRevision, UpgradeState $expectedState, UpgradeState $nextState, string $jobId): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function returnToNormal(int $expectedRevision, UpgradeState $terminalState, string $jobId, bool $platformSyncPending): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function advanceRuntimeFence(int $expectedRevision, UpgradeRuntimeIdentity $current, UpgradeRuntimeIdentity $target, string $jobId): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function forceMaintenance(int $expectedRevision, string $jobId, string $failureCode): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function clearActivityUncertainty(int $expectedRevision, array $requiredRoles, array $cleanRoleRecords): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }
}
