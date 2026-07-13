<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\FileUpgradeCheckpointRepository;
use app\service\upgrade\RedisUpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRedisConnectionFactory;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeState;
use app\service\upgrade\UpgradeStateConflict;
use PHPUnit\Framework\TestCase;

final class UpgradeGateRepositoryTest extends TestCase
{
    private string $root;
    private TestUpgradeRedis $redis;
    private FileUpgradeCheckpointRepository $checkpoints;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-upgrade-gate-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['config', 'run', 'state', 'jobs', 'backups'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
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
        $identity = new UpgradeRuntimeIdentity('1.1.0', self::SOURCE_DEPLOYMENT_ID, 0, 3);
        $this->checkpoints = new FileUpgradeCheckpointRepository(
            $files,
            static fn(): UpgradeRuntimeIdentity => $identity,
            static fn(): int => 1_000,
        );
        $this->redis = new TestUpgradeRedis();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testOnlyExpectedRevisionCanAdvanceState(): void
    {
        $repository = $this->repository();
        $initial = $repository->snapshot();

        self::assertSame(UpgradeState::Normal, $initial->state);
        self::assertSame(self::SOURCE_DEPLOYMENT_ID, $initial->requiredDeploymentId);
        self::assertSame(3, $initial->requiredStorageLayoutGeneration);

        $updated = $repository->compareAndSet(
            expectedRevision: $initial->revision,
            expectedState: UpgradeState::Normal,
            nextState: UpgradeState::Preparing,
            jobId: '018f5d35-3f42-7a31-a731-9e45df3356c2',
        );

        self::assertSame(UpgradeState::Preparing, $updated->state);
        self::assertSame($initial->revision + 1, $updated->revision);

        $this->expectException(UpgradeStateConflict::class);
        $repository->compareAndSet(
            expectedRevision: $initial->revision,
            expectedState: UpgradeState::Normal,
            nextState: UpgradeState::Draining,
            jobId: $updated->jobId,
        );
    }

    public function testIllegalTransitionAndDirectTerminalReopenAreRejected(): void
    {
        $repository = $this->repository();
        $initial = $repository->snapshot();

        try {
            $repository->compareAndSet($initial->revision, UpgradeState::Normal, UpgradeState::Draining, self::JOB_ID);
            self::fail('illegal transition was accepted');
        } catch (UpgradeStateConflict) {
        }

        $preparing = $repository->compareAndSet($initial->revision, UpgradeState::Normal, UpgradeState::Preparing, self::JOB_ID);
        $failed = $repository->compareAndSet($preparing->revision, UpgradeState::Preparing, UpgradeState::FailedPreApply, self::JOB_ID);

        try {
            $repository->compareAndSet($failed->revision, UpgradeState::FailedPreApply, UpgradeState::Normal, self::JOB_ID);
            self::fail('terminal state reopened through generic transition');
        } catch (UpgradeStateConflict) {
        }

        $normal = $repository->returnToNormal($failed->revision, UpgradeState::FailedPreApply, self::JOB_ID, true);
        self::assertSame(UpgradeState::Normal, $normal->state);
        self::assertTrue($normal->platformSyncPending);
        self::assertSame(self::SOURCE_DEPLOYMENT_ID, $normal->requiredDeploymentId);
    }

    public function testPausedCannotEnterBackingUpThroughTheGenericTransitionBoundary(): void
    {
        $repository = $this->repository();
        $snapshot = $repository->snapshot();
        foreach ([
            UpgradeState::Preparing,
            UpgradeState::ReadyToDrain,
            UpgradeState::Draining,
            UpgradeState::Paused,
        ] as $next) {
            $snapshot = $repository->compareAndSet(
                $snapshot->revision,
                $snapshot->state,
                $next,
                self::JOB_ID,
            );
        }

        try {
            $repository->compareAndSet(
                $snapshot->revision,
                UpgradeState::Paused,
                UpgradeState::BackingUp,
                self::JOB_ID,
            );
            self::fail('generic gate transition bypassed the drain safety boundary');
        } catch (UpgradeStateConflict) {
        }

        $backingUp = $repository->enterBackingUpAfterDrain($snapshot->revision, self::JOB_ID);
        self::assertSame(UpgradeState::BackingUp, $backingUp->state);
        self::assertSame($snapshot->revision + 1, $backingUp->revision);
    }

    public function testMissingRedisAfterSideEffectsRecoversAsFailedMaintenance(): void
    {
        $repository = $this->repository();
        $snapshot = $repository->snapshot();
        foreach ([
            UpgradeState::Preparing,
            UpgradeState::ReadyToDrain,
            UpgradeState::Draining,
            UpgradeState::Paused,
            UpgradeState::BackingUp,
            UpgradeState::Applying,
        ] as $next) {
            $snapshot = $next === UpgradeState::BackingUp
                ? $repository->enterBackingUpAfterDrain($snapshot->revision, self::JOB_ID)
                : $repository->compareAndSet($snapshot->revision, $snapshot->state, $next, self::JOB_ID);
        }

        $this->redis->clear();
        $recovered = $this->repository()->snapshot();

        self::assertSame(UpgradeState::FailedMaintenance, $recovered->state);
        self::assertSame($snapshot->revision + 1, $recovered->revision);
        self::assertTrue($recovered->uncertain);
        self::assertSame(self::JOB_ID, $recovered->jobId);

        $second = $this->repository()->snapshot();
        self::assertSame($recovered->revision, $second->revision);
        self::assertSame($recovered->redisIncarnation, $second->redisIncarnation);
    }

    public function testRedisLossBeforeSideEffectsRestoresOneDurableRevision(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $preparing = $repository->compareAndSet($normal->revision, UpgradeState::Normal, UpgradeState::Preparing, self::JOB_ID);

        $this->redis->clear();
        $first = $this->repository()->snapshot();
        $second = $this->repository()->snapshot();

        self::assertSame(UpgradeState::Preparing, $first->state);
        self::assertSame($first->revision, $second->revision);
        self::assertSame($preparing->activityGeneration, $first->activityGeneration);
        self::assertSame($first->redisIncarnation, $second->redisIncarnation);
        self::assertTrue($first->uncertain, 'pre-side-effect Redis loss must remain durable uncertainty');

        try {
            $this->repository()->compareAndSet(
                $first->revision,
                UpgradeState::Preparing,
                UpgradeState::ReadyToDrain,
                self::JOB_ID,
            );
            self::fail('uncertain pre-side-effect recovery was allowed to continue upgrading');
        } catch (UpgradeStateConflict) {
        }
    }

    public function testFactoryMirrorRejectsRedisFailoverBetweenReadAndWrite(): void
    {
        $factory = new GateTestRedisFactory($this->redis, str_repeat('a', 40));
        $repository = new RedisUpgradeGateRepository(
            $factory,
            $this->checkpoints,
            'mbs_test_namespace',
            static fn(): int => 1_000,
        );
        $initial = $repository->snapshot();
        $factory->connectionRunIds = [str_repeat('a', 40), str_repeat('b', 40)];

        $this->expectException(UpgradeStateConflict::class);
        $this->expectExceptionMessage('UPGRADE_REDIS_INCARNATION_CHANGED');
        $repository->compareAndSet(
            $initial->revision,
            UpgradeState::Normal,
            UpgradeState::Preparing,
            self::JOB_ID,
        );
    }

    public function testFactoryRedisRestartPersistsTheNewIncarnationAsUncertain(): void
    {
        $factory = new GateTestRedisFactory($this->redis, str_repeat('a', 40));
        $repository = new RedisUpgradeGateRepository(
            $factory,
            $this->checkpoints,
            'mbs_test_namespace',
            static fn(): int => 1_000,
        );
        $repository->snapshot();
        $this->redis->clear();
        $factory->defaultRunId = str_repeat('b', 40);

        $recovered = $repository->snapshot();

        self::assertTrue($recovered->uncertain);
        self::assertSame(str_repeat('b', 40), $recovered->redisIncarnation);
        self::assertGreaterThanOrEqual(4, $factory->closedConnections);
    }

    public function testRedisGateLossInNormalFailsOpenButFencesUpgradeEntry(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();

        $this->redis->clear();
        $recovered = $this->repository()->snapshot();

        self::assertSame(UpgradeState::Normal, $recovered->state, 'normal application traffic must remain fail-open');
        self::assertSame($normal->activityGeneration, $recovered->activityGeneration);
        self::assertTrue($recovered->uncertain, 'lost Redis gate cannot prove that the old activity ledger was empty');

        try {
            $this->repository()->compareAndSet(
                $recovered->revision,
                UpgradeState::Normal,
                UpgradeState::Preparing,
                self::JOB_ID,
            );
            self::fail('Redis gate loss in normal state was allowed to enter upgrade');
        } catch (UpgradeStateConflict) {
        }
    }

    public function testNormalUncertaintyKeepsTrafficFailOpenButFencesUpgradeEntry(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $boot = '218f5d35-3f42-7a31-a731-9e45df3356c2:318f5d35-3f42-7a31-a731-9e45df3356c2:http';

        $uncertain = $repository->recordActivityUncertainty($normal->revision, [$boot]);
        self::assertSame(UpgradeState::Normal, $uncertain->state);
        self::assertTrue($uncertain->uncertain);
        self::assertContains($boot, $uncertain->taintedBoots);

        $reloaded = $this->repository()->snapshot();
        self::assertTrue($reloaded->uncertain);
        self::assertContains($boot, $reloaded->taintedBoots);

        try {
            $this->repository()->compareAndSet(
                $reloaded->revision,
                UpgradeState::Normal,
                UpgradeState::Preparing,
                self::JOB_ID,
            );
            self::fail('durably uncertain normal state was allowed to enter upgrade');
        } catch (UpgradeStateConflict) {
        }
    }

    public function testUncertaintyRevisionIsStableUntilExplicitReplacementBarrier(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $firstBoot = 'runtime-a:boot-a:http';
        $secondBoot = 'runtime-b:boot-b:queue';

        $first = $repository->recordActivityUncertainty($normal->revision, [$firstBoot]);
        self::assertTrue($first->uncertain);
        self::assertSame($first->revision, $first->uncertainRevision);
        self::assertNull($first->replacementBarrierRevision);
        self::assertFalse($first->taintedBootsOverflow);

        $second = $repository->recordActivityUncertainty($first->revision, [$secondBoot]);
        self::assertGreaterThan($first->revision, $second->revision);
        self::assertSame($first->uncertainRevision, $second->uncertainRevision);
        self::assertNull($second->replacementBarrierRevision, 'late boot union cannot implicitly start replacement');
        self::assertSame([$firstBoot, $secondBoot], $second->taintedBoots);

        $reloaded = $this->repository()->snapshot();
        self::assertSame($second->uncertainRevision, $reloaded->uncertainRevision);
        self::assertNull($reloaded->replacementBarrierRevision);
    }

    public function testRuntimeRegistrationAcknowledgementIsNoOpOnCleanGate(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $record = $this->runtimeRecord('http', $normal);

        $acknowledged = $repository->acknowledgeRuntimeRegistration($normal->revision, $record);

        self::assertEquals($normal->toDocument(), $acknowledged->toDocument());
        self::assertSame($normal->revision, $acknowledged->revision);
        self::assertFalse($acknowledged->uncertain);
        self::assertSame([], $acknowledged->taintedBoots);
    }

    public function testUncertainGateTaintsPreBarrierRegistrationUsingExactOwnerKey(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $record = $this->runtimeRecord('http', $normal);
        $uncertain = $repository->recordActivityUncertainty($normal->revision, []);

        $acknowledged = $repository->acknowledgeRuntimeRegistration($uncertain->revision, $record);

        self::assertSame($uncertain->revision + 1, $acknowledged->revision);
        self::assertSame([$this->recordOwnerKey($record)], $acknowledged->taintedBoots);
        self::assertTrue($acknowledged->uncertain);

        $replayed = $repository->acknowledgeRuntimeRegistration($acknowledged->revision, $record);
        self::assertSame($acknowledged->revision, $replayed->revision);
        self::assertSame([$this->recordOwnerKey($record)], $replayed->taintedBoots);
    }

    public function testRuntimeRegistrationAcknowledgementRejectsNonActiveOrMalformedRecord(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $active = $this->runtimeRecord('http', $normal);
        $retired = $active;
        $retired['state'] = 'retired';
        $retired['retired_at'] = 1_001;
        $wrongSchema = $active;
        $wrongSchema['schema_version'] = 1;
        $missingLineage = $active;
        unset($missingLineage['redis_incarnation']);

        foreach ([
            'retired record' => $retired,
            'wrong schema' => $wrongSchema,
            'missing immutable lineage' => $missingLineage,
        ] as $name => $record) {
            try {
                $repository->acknowledgeRuntimeRegistration($normal->revision, $record);
                self::fail($name . ' was accepted as a runtime registration');
            } catch (UpgradeStateConflict) {
            }
            self::assertSame($normal->revision, $repository->snapshot()->revision, $name);
        }
    }

    public function testUncertainBarrierTaintsEveryNonCleanRegistrationLineage(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $uncertain = $repository->recordActivityUncertainty($normal->revision, []);
        $current = $repository->beginActivityRecovery($uncertain->revision, str_repeat('a', 40));
        $barrier = (int) $current->replacementBarrierRevision;
        $cases = [
            'pre barrier registration' => ['http', [
                'boot_id' => '318f5d35-3f42-7a31-a731-9e45df3356c2',
                'boot_registration_revision' => $barrier - 1,
            ]],
            'old activity generation' => ['queue', [
                'boot_id' => '418f5d35-3f42-7a31-a731-9e45df3356c2',
                'activity_generation' => $current->activityGeneration - 1,
            ]],
            'old redis incarnation' => ['cron', [
                'boot_id' => '518f5d35-3f42-7a31-a731-9e45df3356c2',
                'redis_incarnation' => str_repeat('b', 40),
            ]],
            'wrong runtime tuple' => ['http', [
                'boot_id' => '618f5d35-3f42-7a31-a731-9e45df3356c2',
                'deployment_id' => self::WRONG_DEPLOYMENT_ID,
            ]],
        ];

        foreach ($cases as $name => [$role, $overrides]) {
            $record = $this->runtimeRecord($role, $current, $overrides);
            $previousRevision = $current->revision;
            $current = $repository->acknowledgeRuntimeRegistration($previousRevision, $record);

            self::assertSame($previousRevision + 1, $current->revision, $name);
            self::assertContains($this->recordOwnerKey($record), $current->taintedBoots, $name);
        }
    }

    public function testBarrierSnapshotRegistrationDoesNotTaint(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $uncertain = $repository->recordActivityUncertainty($normal->revision, []);
        $barrier = $repository->beginActivityRecovery($uncertain->revision, str_repeat('a', 40));
        $record = $this->runtimeRecord('http', $barrier, [
            'boot_registration_revision' => $barrier->replacementBarrierRevision,
        ]);

        $acknowledged = $repository->acknowledgeRuntimeRegistration($barrier->revision, $record);

        self::assertSame($barrier->revision, $acknowledged->revision);
        self::assertSame([], $acknowledged->taintedBoots);
        self::assertTrue($acknowledged->uncertain);
    }

    public function testRuntimeRegistrationRevisionRaceConflictsUntilCallerRetriesCurrentGate(): void
    {
        $repository = $this->repository();
        $stale = $repository->snapshot();
        $record = $this->runtimeRecord('http', $stale);
        $uncertain = $repository->recordActivityUncertainty($stale->revision, []);

        try {
            $repository->acknowledgeRuntimeRegistration($stale->revision, $record);
            self::fail('a stale registration acknowledgement crossed the gate transition');
        } catch (UpgradeStateConflict) {
        }

        $reloaded = $repository->snapshot();
        self::assertSame($uncertain->revision, $reloaded->revision);
        $acknowledged = $repository->acknowledgeRuntimeRegistration($reloaded->revision, $record);
        self::assertContains($this->recordOwnerKey($record), $acknowledged->taintedBoots);
    }

    public function testGateDocumentRejectsForgedReplacementBarrierOrdering(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $uncertain = $repository->recordActivityUncertainty($normal->revision, ['runtime-a:boot-a:http']);
        $document = $uncertain->toDocument();
        $document->replacement_barrier_revision = (int) $uncertain->uncertainRevision - 1;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UPGRADE_GATE_SNAPSHOT_INVALID');
        \app\service\upgrade\UpgradeGateSnapshot::fromDocument($document);
    }

    public function testTaintedBootOverflowIsDurableAndDoesNotDiscardKnownBoots(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $known = [];
        for ($index = 0; $index < 100; $index++) {
            $known[] = sprintf('runtime-%03d:boot-%03d:http', $index, $index);
        }
        $full = $repository->recordActivityUncertainty($normal->revision, $known);

        $overflow = $repository->recordActivityUncertainty($full->revision, ['runtime-overflow:boot-overflow:http']);

        self::assertTrue($overflow->uncertain);
        self::assertTrue($overflow->taintedBootsOverflow, 'capacity exhaustion must be durable evidence');
        self::assertSame($known, $overflow->taintedBoots, 'known tainted boots cannot be evicted');
        self::assertGreaterThan($full->revision, $overflow->revision);

        $reloaded = $this->repository()->snapshot();
        self::assertTrue($reloaded->taintedBootsOverflow);
        self::assertSame($known, $reloaded->taintedBoots);
    }

    public function testBeginActivityRecoveryPublishesExplicitReplacementBarrier(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $ownerKey = $this->ownerKey('http');
        $uncertain = $repository->recordActivityUncertainty($normal->revision, [$ownerKey]);

        $barrier = $repository->beginActivityRecovery($uncertain->revision, str_repeat('a', 40));

        self::assertTrue($barrier->uncertain);
        self::assertSame($uncertain->uncertainRevision, $barrier->uncertainRevision);
        self::assertSame($barrier->revision, $barrier->replacementBarrierRevision);
        self::assertNotSame($uncertain->activityGeneration, $barrier->activityGeneration);
        self::assertSame(str_repeat('a', 40), $barrier->redisIncarnation);
        self::assertSame([$ownerKey], $barrier->taintedBoots);

        $reloaded = $this->repository()->snapshot();
        self::assertEquals($barrier->toDocument(), $reloaded->toDocument());

        $this->expectException(UpgradeStateConflict::class);
        $repository->beginActivityRecovery($uncertain->revision, str_repeat('a', 40));
    }

    public function testRetiredTaintedOwnerMustMatchFullRuntimeBootAndRoleKey(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $httpOwner = $this->ownerKey('http');
        $queueOwner = $this->ownerKey('queue');
        $uncertain = $repository->recordActivityUncertainty($normal->revision, [$httpOwner, $queueOwner]);
        $barrier = $repository->beginActivityRecovery($uncertain->revision, str_repeat('a', 40));

        $roleless = $repository->recordRetiredTaintedOwner(
            $barrier->revision,
            '218f5d35-3f42-7a31-a731-9e45df3356c2:318f5d35-3f42-7a31-a731-9e45df3356c2',
        );
        self::assertSame($barrier->revision, $roleless->revision);
        self::assertSame([$httpOwner, $queueOwner], $roleless->taintedBoots);

        $retiredHttp = $repository->recordRetiredTaintedOwner($barrier->revision, $httpOwner);
        self::assertSame([$queueOwner], $retiredHttp->taintedBoots);
        self::assertSame($barrier->replacementBarrierRevision, $retiredHttp->replacementBarrierRevision);
        $retiredQueue = $repository->recordRetiredTaintedOwner($retiredHttp->revision, $queueOwner);
        self::assertSame([], $retiredQueue->taintedBoots);
        self::assertTrue($retiredQueue->uncertain);
    }

    public function testClearActivityUncertaintyRequiresBarrierCleanRolesAndExactTuple(): void
    {
        [$repository, $ready] = $this->activityRecoveryReadyToClear();
        $barrier = (int) $ready->replacementBarrierRevision;
        $http = $this->cleanRoleRecord('http', $ready, ['queues' => ['default'], 'cron_enabled' => true]);
        $queue = $this->cleanRoleRecord('queue', $ready, ['queues' => ['default'], 'cron_enabled' => true]);
        $cron = $this->cleanRoleRecord('cron', $ready, ['queues' => ['default'], 'cron_enabled' => true]);
        $missingImmutableField = $http;
        unset($missingImmutableField['activity_generation']);
        $extraLegacyField = $http;
        $extraLegacyField['gate_revision'] = $ready->revision;

        foreach ([
            'missing http' => [[$queue, $cron]],
            'enabled queue missing' => [[$http, $cron]],
            'enabled cron missing' => [[$http, $queue]],
            'pre barrier boot despite late observation' => [[...[$http, $queue], $this->cleanRoleRecord('cron', $ready, [
                'queues' => ['default'], 'cron_enabled' => true,
                'boot_registration_revision' => $barrier - 1,
                'observed_gate_revision' => $ready->revision + 10,
            ])]],
            'wrong activity generation' => [[...[$http, $queue], $this->cleanRoleRecord('cron', $ready, [
                'queues' => ['default'], 'cron_enabled' => true,
                'activity_generation' => $ready->activityGeneration + 1,
            ])]],
            'wrong redis incarnation' => [[...[$http, $queue], $this->cleanRoleRecord('cron', $ready, [
                'queues' => ['default'], 'cron_enabled' => true,
                'redis_incarnation' => str_repeat('b', 40),
            ])]],
            'wrong required tuple' => [[...[$http, $queue], $this->cleanRoleRecord('cron', $ready, [
                'queues' => ['default'], 'cron_enabled' => true, 'deployment_id' => self::WRONG_DEPLOYMENT_ID,
            ])]],
            'wrong observed deployment epoch' => [[...[$http, $queue], $this->cleanRoleRecord('cron', $ready, [
                'queues' => ['default'], 'cron_enabled' => true, 'observed_deployment_epoch' => 2,
            ])]],
            'fenced role' => [[...[$http, $queue], $this->cleanRoleRecord('cron', $ready, [
                'queues' => ['default'], 'cron_enabled' => true, 'identity_fenced' => true,
            ])]],
            'missing immutable field' => [[$missingImmutableField, $queue, $cron]],
            'extra legacy field' => [[$extraLegacyField, $queue, $cron]],
        ] as $name => [$records]) {
            try {
                $repository->clearActivityUncertainty($ready->revision, ['http', 'queue', 'cron'], $records);
                self::fail($name . ' clean-role evidence cleared uncertainty');
            } catch (UpgradeStateConflict) {
            }
            self::assertTrue($this->repository()->snapshot()->uncertain, $name);
        }

        $cleared = $repository->clearActivityUncertainty(
            $ready->revision,
            ['http', 'queue', 'cron'],
            [$http, $queue, $cron],
        );
        self::assertFalse($cleared->uncertain);
        self::assertNull($cleared->uncertainRevision);
        self::assertNull($cleared->replacementBarrierRevision);
        self::assertFalse($cleared->taintedBootsOverflow);
        self::assertSame([], $cleared->taintedBoots);
        self::assertNull($cleared->failureCode);
    }

    public function testRedisLossIsDetectedBeforeAFirstIrreversibleTransition(): void
    {
        $repository = $this->repository();
        $snapshot = $repository->snapshot();
        foreach ([
            UpgradeState::Preparing,
            UpgradeState::ReadyToDrain,
            UpgradeState::Draining,
            UpgradeState::Paused,
        ] as $next) {
            $snapshot = $next === UpgradeState::BackingUp
                ? $repository->enterBackingUpAfterDrain($snapshot->revision, self::JOB_ID)
                : $repository->compareAndSet($snapshot->revision, $snapshot->state, $next, self::JOB_ID);
        }

        $this->redis->clear();
        try {
            $repository->compareAndSet(
                $snapshot->revision,
                UpgradeState::Paused,
                UpgradeState::BackingUp,
                self::JOB_ID,
            );
            self::fail('Redis loss allowed the first irreversible transition');
        } catch (UpgradeStateConflict) {
        }

        $recovered = $this->repository()->snapshot();
        self::assertSame(UpgradeState::Paused, $recovered->state);
        self::assertTrue($recovered->uncertain);
        self::assertSame($snapshot->activityGeneration, $recovered->activityGeneration);
    }

    public function testRuntimeFenceAdvancesOnlyFromExactCurrentTupleAndSurvivesNormal(): void
    {
        $repository = $this->repository();
        $snapshot = $repository->snapshot();
        foreach ([
            UpgradeState::Preparing,
            UpgradeState::ReadyToDrain,
            UpgradeState::Draining,
            UpgradeState::Paused,
            UpgradeState::BackingUp,
            UpgradeState::Applying,
        ] as $next) {
            $snapshot = $next === UpgradeState::BackingUp
                ? $repository->enterBackingUpAfterDrain($snapshot->revision, self::JOB_ID)
                : $repository->compareAndSet($snapshot->revision, $snapshot->state, $next, self::JOB_ID);
        }
        $current = new UpgradeRuntimeIdentity('1.1.0', self::SOURCE_DEPLOYMENT_ID, 0, 3);
        $target = new UpgradeRuntimeIdentity('1.2.0', self::TARGET_DEPLOYMENT_ID, 1, 4);

        try {
            $repository->advanceRuntimeFence(
                $snapshot->revision,
                new UpgradeRuntimeIdentity('1.1.0', self::WRONG_DEPLOYMENT_ID, 0, 3),
                $target,
                self::JOB_ID,
            );
            self::fail('wrong current identity advanced the fence');
        } catch (UpgradeStateConflict) {
        }

        $advanced = $repository->advanceRuntimeFence($snapshot->revision, $current, $target, self::JOB_ID);
        self::assertSame(2, $advanced->deploymentEpoch);
        self::assertTrue($advanced->acceptsRuntime($target));
        self::assertFalse($advanced->acceptsRuntime($current));

        $forced = $repository->forceMaintenance($advanced->revision, self::JOB_ID, 'TARGET_VERIFY_FAILED');
        self::assertSame(UpgradeState::FailedMaintenance, $forced->state);
        self::assertSame(self::TARGET_DEPLOYMENT_ID, $forced->requiredDeploymentId);
    }

    public function testInvalidRedisGatePersistsNormalUncertaintyAndRepairsMirror(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $this->redis->putRaw('mallbase:mbs_test_namespace:upgrade:gate', '{invalid');

        $recovered = $repository->snapshot();

        self::assertSame(UpgradeState::Normal, $recovered->state);
        self::assertTrue($recovered->uncertain);
        self::assertSame('REDIS_GATE_INVALID', $recovered->failureCode);
        self::assertSame($normal->revision + 1, $recovered->revision);
        self::assertEquals($recovered->toDocument(), $repository->snapshot()->toDocument());
    }

    public function testDivergedNormalRedisGatePersistsUncertaintyInsteadOfThrowing(): void
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $diverged = get_object_vars($normal->toDocument());
        $diverged['revision'] = $normal->revision + 5;
        $diverged['updated_at']++;
        $this->redis->putRaw(
            'mallbase:mbs_test_namespace:upgrade:gate',
            json_encode($diverged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        $recovered = $repository->snapshot();

        self::assertSame(UpgradeState::Normal, $recovered->state);
        self::assertTrue($recovered->uncertain);
        self::assertSame('REDIS_GATE_DIVERGED', $recovered->failureCode);
        self::assertSame($diverged['revision'] + 1, $recovered->revision);
    }

    /** @return array{RedisUpgradeGateRepository,\app\service\upgrade\UpgradeGateSnapshot} */
    private function activityRecoveryReadyToClear(): array
    {
        $repository = $this->repository();
        $normal = $repository->snapshot();
        $owner = $this->ownerKey('http');
        $uncertain = $repository->recordActivityUncertainty($normal->revision, [$owner]);
        $barrier = $repository->beginActivityRecovery($uncertain->revision, str_repeat('a', 40));
        $ready = $repository->recordRetiredTaintedOwner($barrier->revision, $owner);

        return [$repository, $ready];
    }

    private function ownerKey(string $role): string
    {
        return '218f5d35-3f42-7a31-a731-9e45df3356c2'
            . ':318f5d35-3f42-7a31-a731-9e45df3356c2:' . $role;
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function cleanRoleRecord(string $role, UpgradeGateSnapshot $gate, array $overrides = []): array
    {
        return $this->runtimeRecord($role, $gate, [
            'boot_registration_revision' => (int) $gate->replacementBarrierRevision,
            ...$overrides,
        ]);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function runtimeRecord(string $role, UpgradeGateSnapshot $gate, array $overrides = []): array
    {
        $bootId = match ($role) {
            'http' => '318f5d35-3f42-7a31-a731-9e45df3356c2',
            'queue' => '418f5d35-3f42-7a31-a731-9e45df3356c2',
            'cron' => '518f5d35-3f42-7a31-a731-9e45df3356c2',
            default => throw new \LogicException('invalid test role'),
        };

        return [
            'schema_version' => 2,
            'state' => 'active',
            'runtime_instance_id' => '218f5d35-3f42-7a31-a731-9e45df3356c2',
            'boot_id' => $bootId,
            'role' => $role,
            'app_version' => $gate->requiredRuntimeVersion,
            'deployment_id' => $gate->requiredDeploymentId,
            'storage_layout_version' => $gate->requiredStorageLayoutVersion,
            'storage_layout_generation' => $gate->requiredStorageLayoutGeneration,
            'observed_deployment_epoch' => $gate->deploymentEpoch,
            'boot_registration_revision' => $gate->revision,
            'activity_generation' => $gate->activityGeneration,
            'redis_incarnation' => $gate->redisIncarnation,
            'queues' => [],
            'cron_enabled' => false,
            'observed_gate_revision' => $gate->revision,
            'identity_fenced' => false,
            'paused_ack_revision' => null,
            'slot_id' => 'slot-' . $role,
            'registered_at' => 1_000,
            'last_seen_at' => 1_000,
            'retired_at' => null,
            ...$overrides,
        ];
    }

    /** @param array<string,mixed> $record */
    private function recordOwnerKey(array $record): string
    {
        return $record['runtime_instance_id'] . ':' . $record['boot_id'] . ':' . $record['role'];
    }

    private function repository(): RedisUpgradeGateRepository
    {
        return new RedisUpgradeGateRepository($this->redis, $this->checkpoints, 'mbs_test_namespace', static fn(): int => 1_000);
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

    /** @return array<string, callable> */
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

    private const JOB_ID = '018f5d35-3f42-7a31-a731-9e45df3356c2';
    private const AGENT_UID = 31001;
    private const SHARED_GID = 31002;
    private const PHP_UID = 31003;
    private const SOURCE_DEPLOYMENT_ID = 'd475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
    private const TARGET_DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
    private const WRONG_DEPLOYMENT_ID = 'b475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}

final class TestUpgradeRedis
{
    /** @var array<string, string> */
    private array $values = [];

    public function info(string $section): array
    {
        if ($section !== 'server') {
            throw new \RuntimeException('unexpected info section');
        }

        return ['run_id' => str_repeat('a', 40)];
    }

    public function get(string $key): string|false
    {
        return $this->values[$key] ?? false;
    }

    /** @param list<string|int> $arguments */
    public function eval(string $script, array $arguments, int $numberOfKeys): array
    {
        unset($script);
        if ($numberOfKeys !== 1 || count($arguments) !== 3) {
            throw new \RuntimeException('unexpected redis script contract');
        }
        [$key, $expectedRevision, $next] = $arguments;
        $current = $this->values[(string) $key] ?? null;
        if ($current === $next) {
            return [1, $next];
        }
        if ($current !== null) {
            try {
                $decoded = json_decode($current, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                if ((int) $expectedRevision === -2) {
                    $this->values[(string) $key] = (string) $next;

                    return [1, $next];
                }

                return [-1, $current];
            }
        }
        $currentRevision = $current === null ? -1 : (int) ($decoded['revision'] ?? -1);
        if ($currentRevision !== (int) $expectedRevision) {
            return [0, $current ?? ''];
        }
        $this->values[(string) $key] = (string) $next;

        return [1, $next];
    }

    public function clear(): void
    {
        $this->values = [];
    }

    public function putRaw(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }
}

final class GateTestRedisFactory implements UpgradeRedisConnectionFactory
{
    /** @var list<string> */
    public array $connectionRunIds = [];
    public int $closedConnections = 0;

    public function __construct(
        private readonly TestUpgradeRedis $storage,
        public string $defaultRunId,
    ) {
    }

    public function create(): object
    {
        $runId = array_shift($this->connectionRunIds) ?? $this->defaultRunId;

        return new GateTestRedisConnection($this->storage, $runId, function (): void {
            $this->closedConnections++;
        });
    }
}

final readonly class GateTestRedisConnection
{
    public function __construct(
        private TestUpgradeRedis $storage,
        private string $runId,
        private \Closure $onClose,
    ) {
    }

    /** @return array{run_id:string} */
    public function info(string $section): array
    {
        if ($section !== 'server') {
            throw new \RuntimeException('unexpected info section');
        }

        return ['run_id' => $this->runId];
    }

    public function client(string $command): int
    {
        if ($command !== 'ID') {
            throw new \RuntimeException('unexpected client command');
        }

        return 17;
    }

    public function get(string $key): string|false
    {
        return $this->storage->get($key);
    }

    public function eval(string $script, array $arguments, int $numberOfKeys): array
    {
        return $this->storage->eval($script, $arguments, $numberOfKeys);
    }

    public function close(): void
    {
        ($this->onClose)();
    }
}
