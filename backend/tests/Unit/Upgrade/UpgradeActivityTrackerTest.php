<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\RedisServerIncarnation;
use app\service\upgrade\RedisUpgradeActivityTracker;
use app\service\upgrade\UpgradeActivityLedgerBackend;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeQueueInventory;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeOwnerLiveness;
use app\service\upgrade\UpgradeState;
use PHPUnit\Framework\TestCase;

final class UpgradeActivityTrackerTest extends TestCase
{
    public function testHttpCannotRegisterAfterDrainingAndExistingLeaseRequiresRelease(): void
    {
        [$tracker, $ledger, $gate] = $this->tracker(UpgradeState::Normal);
        $lease = $tracker->tryBeginHttp('request-1', $this->runtimeOwner('http'));

        self::assertNotNull($lease);
        self::assertSame(1, $tracker->snapshot()->activeHttp);
        $ledger->now += 100_000;
        self::assertSame(1, $tracker->snapshot()->activeHttp, 'wall-clock age cannot erase live work');

        $gate->state = UpgradeState::Draining;
        self::assertNull($tracker->tryBeginHttp('request-2', $this->runtimeOwner('http')));
        $lease->release();
        $lease->release();
        self::assertSame(0, $tracker->snapshot()->activeHttp);
    }

    public function testCronCallbackAndQueueUseDifferentLegalLanes(): void
    {
        [$tracker, , $gate] = $this->tracker(UpgradeState::Draining);
        $owner = $this->runtimeOwner('queue');

        self::assertNull($tracker->tryBeginCron('cron-1', $this->runtimeOwner('cron')));
        $callback = $tracker->tryBeginExternalCallback('callback-1', $this->runtimeOwner('http'));
        self::assertNotNull($callback);
        $pop = $tracker->beginQueuePop('worker-1', 'redis', ['default'], self::ATTEMPT_A, $owner);
        self::assertNotNull($pop);
        self::assertSame(1, $tracker->snapshot()->activeCallbacks);
        self::assertSame(1, $tracker->snapshot()->queuePopInProgress);

        $bound = $tracker->bindQueueJob($pop, 'redis', 'default', 'job-1');
        self::assertSame(self::ATTEMPT_A, $bound->executionAttemptId);
        self::assertSame(0, $tracker->snapshot()->queuePopInProgress);
        self::assertSame(1, $tracker->snapshot()->activeQueue);

        $gate->state = UpgradeState::Paused;
        self::assertNull($tracker->tryBeginExternalCallback('callback-2', $this->runtimeOwner('http')));
        self::assertNull($tracker->beginQueuePop('worker-1', 'redis', ['default'], self::ATTEMPT_B, $owner));

        $gate->state = UpgradeState::Reconciling;
        $reconcileCallback = $tracker->tryBeginExternalCallback('callback-3', $this->runtimeOwner('http'));
        self::assertNotNull($reconcileCallback);

        $callback->release();
        $bound->release();
        $reconcileCallback->release();
    }

    public function testOverlappingQueueAttemptsNeverOverwriteEachOther(): void
    {
        [$tracker] = $this->tracker(UpgradeState::Draining);
        $ownerA = $this->runtimeOwner('queue', '018f5d35-3f42-7a31-a731-9e45df3356c2');
        $ownerB = $this->runtimeOwner('queue', '118f5d35-3f42-7a31-a731-9e45df3356c2');
        $first = $tracker->beginQueuePop('worker-a', 'redis', ['default'], self::ATTEMPT_A, $ownerA);
        $second = $tracker->beginQueuePop('worker-b', 'redis', ['default'], self::ATTEMPT_B, $ownerB);
        self::assertNotNull($first);
        self::assertNotNull($second);

        $second = $tracker->bindQueueJob($second, 'redis', 'default', 'same-job');
        self::assertSame(1, $tracker->snapshot()->queuePopInProgress);
        self::assertSame(1, $tracker->snapshot()->activeQueue);
        $first = $tracker->bindQueueJob($first, 'redis', 'default', 'same-job');
        self::assertSame(2, $tracker->snapshot()->activeQueue);

        $first->release();
        self::assertSame(1, $tracker->snapshot()->activeQueue);
        $second->release();
    }

    public function testQueueBindSnapshotLossPersistsUncertainty(): void
    {
        [$tracker, $ledger, $gate] = $this->tracker(UpgradeState::Draining);
        $pop = $tracker->beginQueuePop(
            'worker-bind-loss',
            'redis',
            ['default'],
            self::ATTEMPT_A,
            $this->runtimeOwner('queue'),
        );
        self::assertNotNull($pop);
        $ledger->drop();

        try {
            $tracker->bindQueueJob($pop, 'redis', 'default', 'job-bind-loss');
            self::fail('queue bind continued after its ledger snapshot was lost');
        } catch (\app\service\upgrade\UpgradeStateConflict $exception) {
            self::assertSame('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN', $exception->getMessage());
        }
        self::assertTrue($gate->snapshot()->uncertain);
        self::assertSame(UpgradeState::FailedMaintenance, $gate->snapshot()->state);
    }

    public function testWrongRuntimeIdentityIsFencedBeforeActivityRegistration(): void
    {
        [$tracker] = $this->tracker(UpgradeState::Normal);
        $wrong = new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_ID,
            'http',
            new UpgradeRuntimeIdentity('1.1.0', self::WRONG_DEPLOYMENT_ID, 0, 3),
            1,
        );

        self::assertNull($tracker->tryBeginHttp('request-1', $wrong));
        self::assertSame(0, $tracker->snapshot()->activeHttp);
    }

    public function testWholeLedgerLossAndRedisRestartBecomeExplicitUncertainty(): void
    {
        [$tracker, $ledger] = $this->tracker(UpgradeState::Normal);
        $lease = $tracker->tryBeginHttp('request-1', $this->runtimeOwner('http'));
        self::assertNotNull($lease);

        $ledger->drop();
        $snapshot = $tracker->snapshot();
        self::assertTrue($snapshot->uncertain);
        self::assertSame(0, $snapshot->activeHttp);
        self::assertTrue($tracker->tryBeginHttp('request-untracked', $this->runtimeOwner('http'))?->untracked);

        [$otherTracker, $otherLedger] = $this->tracker(UpgradeState::Normal);
        $otherLedger->serverRunId = str_repeat('b', 40);
        self::assertTrue($otherTracker->snapshot()->uncertain);
    }

    public function testPartialLedgerLossIsDetectedByCountAndDigest(): void
    {
        [$tracker, $ledger, $gate] = $this->tracker(UpgradeState::Normal);
        self::assertNotNull($tracker->tryBeginHttp('request-1', $this->runtimeOwner('http')));

        $ledger->corruptCount();

        self::assertTrue($tracker->snapshot()->uncertain);
        self::assertTrue($gate->snapshot()->uncertain, 'partial loss must be persisted outside Redis');

        $late = $tracker->tryBeginHttp('request-late-partial', $this->runtimeOwner('http', self::LATE_BOOT_ID));
        self::assertTrue($late?->untracked, 'normal traffic remains fail-open after durable uncertainty');
        self::assertContains(
            self::RUNTIME_ID . ':' . self::LATE_BOOT_ID . ':http',
            $gate->snapshot()->taintedBoots,
            'a boot admitted after loss must be durably tainted',
        );
    }

    public function testSemanticallyCorruptLedgerIsDurablyMarkedUncertain(): void
    {
        [$tracker, $ledger, $gate] = $this->tracker(UpgradeState::Normal);
        self::assertNotNull($tracker->tryBeginHttp('request-semantic-corruption', $this->runtimeOwner('http')));

        $ledger->corruptEntrySemantics();
        $snapshot = $tracker->snapshot();

        self::assertTrue($snapshot->uncertain);
        self::assertSame([['code' => 'ACTIVITY_LEDGER_INVALID']], $snapshot->blockers);
        self::assertTrue($gate->snapshot()->uncertain, 'semantic ledger corruption must be persisted outside Redis');
    }

    public function testLedgerLossIsDurablyRecordedAndLateBootBecomesTainted(): void
    {
        [$tracker, $ledger, $gate] = $this->tracker(UpgradeState::Normal);
        self::assertNotNull($tracker->tryBeginHttp('request-1', $this->runtimeOwner('http')));
        $ledger->drop();

        self::assertTrue($tracker->snapshot()->uncertain);
        self::assertTrue($gate->snapshot()->uncertain);
        self::assertGreaterThan(0, $gate->uncertaintyWrites);

        $late = $tracker->tryBeginHttp('request-late', $this->runtimeOwner('http', self::LATE_BOOT_ID));
        self::assertTrue($late?->untracked);
        self::assertContains(self::RUNTIME_ID . ':' . self::LATE_BOOT_ID . ':http', $gate->snapshot()->taintedBoots);
    }

    public function testReleaseFailureRetainsWorkAndDurablyTaintsTracking(): void
    {
        [$tracker, $ledger, $gate] = $this->tracker(UpgradeState::Normal);
        $lease = $tracker->tryBeginHttp('request-release-failure', $this->runtimeOwner('http'));
        self::assertNotNull($lease);

        $ledger->failRelease = true;
        $lease->release();

        self::assertTrue($gate->snapshot()->uncertain);
        self::assertSame(1, $tracker->snapshot()->activeHttp, 'failed release must not erase live work');
        $late = $tracker->tryBeginHttp('request-after-release-failure', $this->runtimeOwner('http', self::LATE_BOOT_ID));
        self::assertTrue($late?->untracked);
        self::assertContains(self::RUNTIME_ID . ':' . self::LATE_BOOT_ID . ':http', $gate->snapshot()->taintedBoots);
    }

    public function testReleaseFailureCannotBeForgottenWhenUncertaintyPersistenceAlsoFails(): void
    {
        [$tracker, $ledger, $gate] = $this->tracker(UpgradeState::Normal);
        $lease = $tracker->tryBeginHttp('request-release-persistence-failure', $this->runtimeOwner('http'));
        self::assertNotNull($lease);
        $ledger->failRelease = true;
        $gate->failUncertaintyWrites = true;

        try {
            $lease->release();
            self::fail('release was marked complete without ledger removal or durable uncertainty');
        } catch (\app\service\upgrade\UpgradeStateConflict $exception) {
            self::assertSame('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN', $exception->getMessage());
        }

        $ledger->failRelease = false;
        $gate->failUncertaintyWrites = false;
        $lease->release();
        self::assertSame(0, $tracker->snapshot()->activeHttp, 'failed release must remain retryable');
    }

    public function testTaintedBootCapacityUsesDurableOverflowBeforeNormalFailOpen(): void
    {
        [$tracker, , $gate] = $this->tracker(UpgradeState::Normal);
        $boots = [];
        for ($index = 0; $index < 100; $index++) {
            $boots[] = sprintf('runtime-%03d:boot-%03d:http', $index, $index);
        }
        $gate->recordActivityUncertainty($gate->snapshot()->revision, $boots);

        $late = $tracker->tryBeginHttp('request-over-taint-capacity', $this->runtimeOwner('http', self::LATE_BOOT_ID));

        self::assertTrue($late?->untracked, 'durable overflow is the fallback evidence for normal fail-open');
        self::assertTrue($gate->snapshot()->uncertain);
        self::assertTrue($gate->snapshot()->taintedBootsOverflow);
        self::assertCount(100, $gate->snapshot()->taintedBoots);
        self::assertNotContains(self::RUNTIME_ID . ':' . self::LATE_BOOT_ID . ':http', $gate->snapshot()->taintedBoots);
    }

    public function testWorkersHeartbeatAndPausedAcknowledgementRemainBoundToIdentity(): void
    {
        [$tracker] = $this->tracker(UpgradeState::Paused);
        $owner = $this->runtimeOwner('queue');

        $tracker->heartbeatWorker('worker-1', 'redis', ['default'], $owner, 15);
        $tracker->ackPaused('worker-1', $owner, 1, 15);
        $workers = $tracker->liveWorkers();

        self::assertCount(1, $workers);
        self::assertSame(1, $workers[0]['paused_revision']);
        self::assertSame(self::TARGET_DEPLOYMENT_ID, $workers[0]['deployment_id']);
    }

    public function testReconciliationRequiresOwnerDeathAndEmptyQueueEvidence(): void
    {
        [$tracker] = $this->tracker(UpgradeState::Draining);
        $lease = $tracker->beginQueuePop('worker-1', 'redis', ['default'], self::ATTEMPT_A, $this->runtimeOwner('queue'));
        self::assertNotNull($lease);
        $lease = $tracker->bindQueueJob($lease, 'redis', 'default', 'job-1');
        $inventory = new UpgradeQueueInventory([], [], []);

        $tracker->reconcileQueueLeases($inventory, new TestOwnerLiveness(false));
        self::assertSame(1, $tracker->snapshot()->activeQueue);

        $tracker->reconcileQueueLeases($inventory, new TestOwnerLiveness(true));
        self::assertSame(0, $tracker->snapshot()->activeQueue);
    }

    public function testGenericOrphanReconciliationRequiresExactOwnerRetirement(): void
    {
        [$tracker, $ledger] = $this->tracker(UpgradeState::Normal);
        self::assertNotNull($tracker->tryBeginHttp('request-orphan', $this->runtimeOwner('http')));

        $ledger->now += 100_000;
        $tracker->reconcileOrphanActivityLeases(new TestOwnerLiveness(false));
        self::assertSame(1, $tracker->snapshot()->activeHttp, 'elapsed TTL alone cannot retire an orphan');

        $tracker->reconcileOrphanActivityLeases(new TestOwnerLiveness(true));
        self::assertSame(0, $tracker->snapshot()->activeHttp);
    }

    /** @return array{RedisUpgradeActivityTracker,TestActivityLedger,TestGateRepository} */
    private function tracker(UpgradeState $state): array
    {
        $gate = new TestGateRepository($this->gateSnapshot($state));
        $ledger = new TestActivityLedger();
        $redis = new class {
        };
        $incarnation = new RedisServerIncarnation($redis, fn(): string => $ledger->serverRunId);
        $tracker = new RedisUpgradeActivityTracker($ledger, $gate, $incarnation, static fn(): int => 1_000);
        $tracker->initializeLedger();

        return [$tracker, $ledger, $gate];
    }

    private function gateSnapshot(UpgradeState $state): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $state,
            1,
            $state === UpgradeState::Normal ? null : self::JOB_ID,
            '1.2.0',
            self::TARGET_DEPLOYMENT_ID,
            1,
            4,
            2,
            7,
            str_repeat('a', 40),
            false,
            [],
            false,
            null,
            1_000,
        );
    }

    private function runtimeOwner(string $role, string $bootId = self::BOOT_ID): UpgradeRuntimeInstance
    {
        return new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            $bootId,
            $role,
            new UpgradeRuntimeIdentity('1.2.0', self::TARGET_DEPLOYMENT_ID, 1, 4),
            2,
        );
    }

    public const JOB_ID = '018f5d35-3f42-7a31-a731-9e45df3356c2';
    private const RUNTIME_ID = '218f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_ID = '318f5d35-3f42-7a31-a731-9e45df3356c2';
    private const LATE_BOOT_ID = '618f5d35-3f42-7a31-a731-9e45df3356c2';
    private const ATTEMPT_A = '418f5d35-3f42-7a31-a731-9e45df3356c2';
    private const ATTEMPT_B = '518f5d35-3f42-7a31-a731-9e45df3356c2';
    private const TARGET_DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
    private const WRONG_DEPLOYMENT_ID = 'b475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}

final class TestGateRepository implements UpgradeGateRepository
{
    public UpgradeState $state;
    public int $uncertaintyWrites = 0;
    public bool $failUncertaintyWrites = false;

    public function __construct(private UpgradeGateSnapshot $value)
    {
        $this->state = $value->state;
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        if ($this->state === $this->value->state) {
            return $this->value;
        }

        return new UpgradeGateSnapshot(
            $this->state,
            $this->value->revision,
            $this->state === UpgradeState::Normal ? null : UpgradeActivityTrackerTest::JOB_ID,
            $this->value->requiredRuntimeVersion,
            $this->value->requiredDeploymentId,
            $this->value->requiredStorageLayoutVersion,
            $this->value->requiredStorageLayoutGeneration,
            $this->value->deploymentEpoch,
            $this->value->activityGeneration,
            $this->value->redisIncarnation,
            $this->value->uncertain,
            $this->value->taintedBoots,
            $this->value->platformSyncPending,
            $this->value->failureCode,
            1_000,
            $this->value->uncertainRevision,
            $this->value->replacementBarrierRevision,
            $this->value->taintedBootsOverflow,
        );
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

    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot
    {
        if ($this->failUncertaintyWrites) {
            throw new \RuntimeException('simulated uncertainty persistence failure');
        }
        $current = $this->snapshot();
        if ($current->revision !== $expectedRevision) {
            throw new \RuntimeException('revision conflict');
        }
        $merged = array_values(array_unique([...$current->taintedBoots, ...$taintedBoots]));
        sort($merged, SORT_STRING);
        $overflow = count($merged) > 100;
        if ($overflow) {
            $merged = $current->taintedBoots;
        }
        $this->uncertaintyWrites++;
        $nextRevision = $current->revision + 1;
        $this->value = new UpgradeGateSnapshot(
            $current->state === UpgradeState::Normal ? UpgradeState::Normal : UpgradeState::FailedMaintenance,
            $nextRevision,
            $current->jobId,
            $current->requiredRuntimeVersion,
            $current->requiredDeploymentId,
            $current->requiredStorageLayoutVersion,
            $current->requiredStorageLayoutGeneration,
            $current->deploymentEpoch,
            $current->activityGeneration,
            $current->redisIncarnation,
            true,
            $merged,
            $current->platformSyncPending,
            'ACTIVITY_TRACKING_UNCERTAIN',
            1_000,
            $current->uncertainRevision ?? $nextRevision,
            $current->replacementBarrierRevision,
            $current->taintedBootsOverflow || $overflow,
        );
        $this->state = $this->value->state;

        return $this->value;
    }

    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

}

final class TestActivityLedger implements UpgradeActivityLedgerBackend
{
    public int $now = 1_000;
    public string $serverRunId;
    public bool $failRelease = false;

    /** @var array<string,array{token:string,payload:array<string,mixed>}> */
    private array $entries = [];
    /** @var array<string,array<string,mixed>> */
    private array $workers = [];
    private ?int $generation = null;
    private int $expectedCount = 0;
    private string $digest = '';

    public function __construct()
    {
        $this->serverRunId = str_repeat('a', 40);
    }

    public function initialize(int $generation, string $serverRunId): void
    {
        if ($this->generation !== null) {
            return;
        }
        $this->generation = $generation;
        $this->serverRunId = $serverRunId;
        $this->refreshIntegrity();
    }

    public function begin(UpgradeGateSnapshot $gate, string $entryId, array $payload, array $allowedStates): ?string
    {
        $this->verify($gate->activityGeneration, $gate->redisIncarnation);
        if (!in_array($gate->state, $allowedStates, true) || isset($this->entries[$entryId])) {
            return null;
        }
        $token = bin2hex(random_bytes(16));
        $this->entries[$entryId] = ['token' => $token, 'payload' => $payload];
        $this->refreshIntegrity();

        return $token;
    }

    public function bind(int $generation, string $serverRunId, string $entryId, string $token, array $payload): ?string
    {
        $this->verify($generation, $serverRunId);
        if (($this->entries[$entryId]['token'] ?? null) !== $token) {
            return null;
        }
        $next = bin2hex(random_bytes(16));
        $this->entries[$entryId] = ['token' => $next, 'payload' => $payload];
        $this->refreshIntegrity();

        return $next;
    }

    public function release(int $generation, string $serverRunId, string $entryId, string $token): void
    {
        $this->verify($generation, $serverRunId);
        if ($this->failRelease) {
            throw new \RuntimeException('simulated release failure');
        }
        if (($this->entries[$entryId]['token'] ?? null) === $token) {
            unset($this->entries[$entryId]);
            $this->refreshIntegrity();
        }
    }

    public function snapshot(int $generation, string $serverRunId): array
    {
        $this->verify($generation, $serverRunId);

        return array_map(static fn(array $entry): array => $entry['payload'], array_values($this->entries));
    }

    public function heartbeatWorker(UpgradeGateSnapshot $gate, string $workerId, array $worker): void
    {
        $this->verify($gate->activityGeneration, $gate->redisIncarnation);
        $this->workers[$workerId] = $worker;
    }

    public function ackPaused(
        UpgradeGateSnapshot $gate,
        string $workerId,
        UpgradeRuntimeInstance $owner,
        int $revision,
        int $expiresAt,
    ): void
    {
        $this->verify($gate->activityGeneration, $gate->redisIncarnation);
        if (isset($this->workers[$workerId])) {
            if (($this->workers[$workerId]['runtime_instance_id'] ?? null) !== $owner->runtimeInstanceId
                || ($this->workers[$workerId]['boot_id'] ?? null) !== $owner->bootId) {
                throw new \RuntimeException('owner mismatch');
            }
            $this->workers[$workerId]['paused_revision'] = $revision;
            $this->workers[$workerId]['expires_at'] = $expiresAt;
        }
    }

    public function liveWorkers(UpgradeGateSnapshot $gate, int $now): array
    {
        $this->verify($gate->activityGeneration, $gate->redisIncarnation);
        return array_values(array_filter($this->workers, static fn(array $worker): bool => ($worker['expires_at'] ?? 0) >= $now));
    }

    public function reconcileQueue(
        int $generation,
        string $serverRunId,
        UpgradeQueueInventory $inventory,
        UpgradeRuntimeOwnerLiveness $owners,
    ): void
    {
        $this->verify($generation, $serverRunId);
        foreach ($this->entries as $id => $entry) {
            $payload = $entry['payload'];
            if (($payload['kind'] ?? '') !== 'queue' || ($payload['phase'] ?? '') !== 'bound') {
                continue;
            }
            $owner = UpgradeRuntimeInstance::fromArray($payload['owner']);
            if ($owners->canRetire($owner)
                && !$inventory->contains((string) $payload['connection'], (string) $payload['queue'], (string) $payload['job_id'])) {
                unset($this->entries[$id]);
            }
        }
        $this->refreshIntegrity();
    }

    public function reconcileOrphans(int $generation, string $serverRunId, UpgradeRuntimeOwnerLiveness $owners): void
    {
        $this->verify($generation, $serverRunId);
        foreach ($this->entries as $id => $entry) {
            $payload = $entry['payload'];
            if (($payload['kind'] ?? '') === 'queue' && ($payload['phase'] ?? '') === 'bound') {
                continue;
            }
            $owner = UpgradeRuntimeInstance::fromArray($payload['owner']);
            if ($owners->canRetire($owner)) {
                unset($this->entries[$id]);
            }
        }
        $this->refreshIntegrity();
    }

    public function drop(): void
    {
        $this->generation = null;
        $this->entries = [];
    }

    public function corruptCount(): void
    {
        $this->expectedCount++;
    }

    public function corruptEntrySemantics(): void
    {
        $entryId = array_key_first($this->entries);
        if (!is_string($entryId)) {
            throw new \LogicException('test ledger has no entry');
        }
        $this->entries[$entryId]['payload']['kind'] = 'unknown';
        $this->refreshIntegrity();
    }

    private function verify(int $generation, string $serverRunId): void
    {
        if ($this->generation !== $generation || $this->serverRunId !== $serverRunId
            || $this->expectedCount !== count($this->entries) || $this->digest !== $this->calculateDigest()) {
            throw new \RuntimeException('ACTIVITY_TRACKING_UNCERTAIN');
        }
    }

    private function refreshIntegrity(): void
    {
        $this->expectedCount = count($this->entries);
        $this->digest = $this->calculateDigest();
    }

    private function calculateDigest(): string
    {
        ksort($this->entries, SORT_STRING);

        return hash('sha256', json_encode($this->entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}

final readonly class TestOwnerLiveness implements UpgradeRuntimeOwnerLiveness
{
    public function __construct(private bool $retired)
    {
    }

    public function canRetire(UpgradeRuntimeInstance $owner): bool
    {
        return $this->retired;
    }
}
