<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeAgentStateTransitionService;
use app\service\upgrade\UpgradeDrainGateRepository;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeOperationStore;
use app\service\upgrade\UpgradeRecoveryGateRepository;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeAgentStateTransitionServiceTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';
    private const RUNTIME_ID = 'runtime-http-1';
    private const BOOT_ID = 'boot-http-1';

    private string $root;
    private UpgradeSharedFileStore $files;
    private UpgradeOperationStore $operations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-agent-state-transition-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['state', 'run', 'jobs'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        $this->files = new UpgradeSharedFileStore(
            $this->root,
            37001,
            37002,
            37003,
            65536,
            50,
            $this->statOperations(),
        );
        $this->operations = new UpgradeOperationStore($this->files);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testLegalTransitionIsDurableAndExactReplayDoesNotMutateTheGateTwice(): void
    {
        $gate = new AgentTransitionGate($this->snapshot(UpgradeState::Preparing, 7));
        $service = new UpgradeAgentStateTransitionService($this->operations, $gate);

        $first = $service->transition(
            self::JOB_ID,
            7,
            UpgradeState::Preparing,
            UpgradeState::ReadyToDrain,
            false,
            self::RUNTIME_ID,
            self::BOOT_ID,
            1000,
        );
        $replay = $service->transition(
            self::JOB_ID,
            7,
            UpgradeState::Preparing,
            UpgradeState::ReadyToDrain,
            false,
            'runtime-http-2',
            'boot-http-2',
            1001,
        );

        self::assertSame('completed', $first['state']);
        self::assertSame($first, $replay);
        self::assertSame(1, $gate->mutationCount);
        self::assertSame('ready_to_drain', $first['result']['state']);
        self::assertSame(8, $first['result']['revision']);
        self::assertSame(self::JOB_ID, $first['result']['job_id']);
        self::assertSame($first, $this->operations->get($first['operation_id']));
    }

    public function testPausedEntersBackingUpOnlyThroughTheDrainCheckedBoundary(): void
    {
        $gate = new AgentTransitionGate($this->snapshot(UpgradeState::Paused, 8));
        $service = new UpgradeAgentStateTransitionService($this->operations, $gate);

        $result = $service->transition(
            self::JOB_ID,
            8,
            UpgradeState::Paused,
            UpgradeState::BackingUp,
            false,
            self::RUNTIME_ID,
            self::BOOT_ID,
            1005,
        );

        self::assertSame('completed', $result['state']);
        self::assertSame('backing_up', $result['result']['state']);
        self::assertSame(1, $gate->drainBoundaryCount);
    }

    #[DataProvider('terminalStateProvider')]
    public function testTerminalStateReturnsGateToNormalAndPreservesPlatformSyncPending(UpgradeState $terminal): void
    {
        $gate = new AgentTransitionGate($this->snapshot($terminal, 20));
        $service = new UpgradeAgentStateTransitionService($this->operations, $gate);

        $result = $service->transition(
            self::JOB_ID,
            20,
            $terminal,
            UpgradeState::Normal,
            true,
            self::RUNTIME_ID,
            self::BOOT_ID,
            1100,
        );

        self::assertSame('completed', $result['state']);
        self::assertSame('normal', $result['result']['state']);
        self::assertSame(21, $result['result']['revision']);
        self::assertNull($result['result']['job_id']);
        self::assertTrue($result['result']['platform_sync_pending']);
        self::assertSame(UpgradeState::Normal, $gate->snapshot()->state);
        self::assertNull($gate->snapshot()->jobId);
    }

    /** @return iterable<string,array{UpgradeState}> */
    public static function terminalStateProvider(): iterable
    {
        yield 'completed' => [UpgradeState::Completed];
        yield 'cancelled' => [UpgradeState::Cancelled];
        yield 'failed pre-apply' => [UpgradeState::FailedPreApply];
    }

    #[DataProvider('maintenanceFailureSourceProvider')]
    public function testIrreversibleMaintenanceStatesCanTransitionToFailedMaintenance(UpgradeState $source): void
    {
        $gate = new AgentTransitionGate($this->snapshot($source, 25));
        $service = new UpgradeAgentStateTransitionService($this->operations, $gate);

        $result = $service->transition(
            self::JOB_ID,
            25,
            $source,
            UpgradeState::FailedMaintenance,
            false,
            self::RUNTIME_ID,
            self::BOOT_ID,
            1150,
        );

        self::assertSame('completed', $result['state']);
        self::assertSame('failed_maintenance', $result['result']['state']);
        self::assertSame(26, $result['result']['revision']);
        self::assertSame(self::JOB_ID, $result['result']['job_id']);
        self::assertSame(UpgradeState::FailedMaintenance, $gate->snapshot()->state);
        self::assertTrue($gate->snapshot()->state->blocksBusinessTraffic());
    }

    /** @return iterable<string,array{UpgradeState}> */
    public static function maintenanceFailureSourceProvider(): iterable
    {
        yield 'backing up' => [UpgradeState::BackingUp];
        yield 'applying' => [UpgradeState::Applying];
        yield 'awaiting deployment' => [UpgradeState::AwaitingDeployment];
        yield 'verifying' => [UpgradeState::Verifying];
        yield 'reconciling' => [UpgradeState::Reconciling];
    }

    public function testForbiddenTransitionAndPendingPlatformSyncFailBeforeOperationClaim(): void
    {
        $gate = new AgentTransitionGate($this->snapshot(UpgradeState::Preparing, 3));
        $service = new UpgradeAgentStateTransitionService($this->operations, $gate);

        foreach ([
            [UpgradeState::Preparing, UpgradeState::Applying, false],
            [UpgradeState::Preparing, UpgradeState::ReadyToDrain, true],
        ] as [$from, $to, $pending]) {
            try {
                $service->transition(
                    self::JOB_ID,
                    3,
                    $from,
                    $to,
                    $pending,
                    self::RUNTIME_ID,
                    self::BOOT_ID,
                    1200,
                );
                self::fail('forbidden state transition was accepted');
            } catch (RuntimeException $exception) {
                self::assertSame('UPGRADE_STATE_TRANSITION_FORBIDDEN', $exception->getMessage());
            }
        }

        self::assertSame(0, $gate->mutationCount);
        self::assertFileDoesNotExist($this->root . '/state/upgrade-operations.json');
    }

    public function testGateFailurePersistsOneStableFailedOperationAndReplayDoesNotRetryTheMutation(): void
    {
        $gate = new AgentTransitionGate($this->snapshot(UpgradeState::BackingUp, 12));
        $gate->failNextMutation = true;
        $service = new UpgradeAgentStateTransitionService($this->operations, $gate);

        $failed = $service->transition(
            self::JOB_ID,
            12,
            UpgradeState::BackingUp,
            UpgradeState::Applying,
            false,
            self::RUNTIME_ID,
            self::BOOT_ID,
            1300,
        );
        $replay = $service->transition(
            self::JOB_ID,
            12,
            UpgradeState::BackingUp,
            UpgradeState::Applying,
            false,
            'runtime-http-2',
            'boot-http-2',
            1301,
        );

        self::assertSame('failed', $failed['state']);
        self::assertSame('UPGRADE_STATE_TRANSITION_FAILED', $failed['error_code']);
        self::assertSame($failed, $replay);
        self::assertSame(1, $gate->mutationCount);
        self::assertSame(UpgradeState::BackingUp, $gate->snapshot()->state);
    }

    public function testCompletedOperationRejectsTheSameOperationIdWithDifferentImmutableInput(): void
    {
        $gate = new AgentTransitionGate($this->snapshot(UpgradeState::Completed, 30));
        $service = new UpgradeAgentStateTransitionService($this->operations, $gate);
        $service->transition(
            self::JOB_ID,
            30,
            UpgradeState::Completed,
            UpgradeState::Normal,
            false,
            self::RUNTIME_ID,
            self::BOOT_ID,
            1400,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_OPERATION_CONFLICT');
        $service->transition(
            self::JOB_ID,
            30,
            UpgradeState::Completed,
            UpgradeState::Normal,
            true,
            'runtime-http-2',
            'boot-http-2',
            1401,
        );
    }

    public function testExplicitResumeRequiresTheImmutableControlAndStatusProjection(): void
    {
        $requestId = '55555555-5555-4555-8555-555555555555';
        $controlRevision = 9;
        $attempt = \app\service\upgrade\UpgradeOperationAttempt::fromRequestId($requestId);
        $this->files->publishJobControl(self::JOB_ID, $controlRevision, $requestId, (object) [
            'schema_version' => 1,
            'job_id' => self::JOB_ID,
            'action' => 'resume',
            'requested_at' => 1500,
            'expected_revision' => $controlRevision,
            'request_id' => $requestId,
        ]);
        $this->writeAgentStatus($controlRevision, 'failed_maintenance', 'migrate');
        $gate = new AgentTransitionGate($this->snapshot(UpgradeState::FailedMaintenance, 40));
        $service = new UpgradeAgentStateTransitionService($this->operations, $gate, $this->files);

        $first = $service->resume(
            self::JOB_ID,
            40,
            UpgradeState::Applying,
            $controlRevision,
            $requestId,
            $attempt,
            self::RUNTIME_ID,
            self::BOOT_ID,
            1501,
        );
        $replay = $service->resume(
            self::JOB_ID,
            40,
            UpgradeState::Applying,
            $controlRevision,
            $requestId,
            $attempt,
            'runtime-http-2',
            'boot-http-2',
            1502,
        );

        self::assertSame('completed', $first['state']);
        self::assertSame('applying', $first['result']['state']);
        self::assertSame(41, $first['result']['revision']);
        self::assertSame($first, $replay);
        self::assertSame(1, $gate->mutationCount);
    }

    public function testExplicitResumeRejectsAnAttemptNotDerivedFromTheControl(): void
    {
        $requestId = '55555555-5555-4555-8555-555555555555';
        $this->files->publishJobControl(self::JOB_ID, 9, $requestId, (object) [
            'schema_version' => 1,
            'job_id' => self::JOB_ID,
            'action' => 'resume',
            'requested_at' => 1500,
            'expected_revision' => 9,
            'request_id' => $requestId,
        ]);
        $this->writeAgentStatus(9, 'failed_maintenance', 'migrate');
        $gate = new AgentTransitionGate($this->snapshot(UpgradeState::FailedMaintenance, 40));
        $service = new UpgradeAgentStateTransitionService($this->operations, $gate, $this->files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_RESUME_CONTROL_INVALID');
        try {
            $service->resume(
                self::JOB_ID,
                40,
                UpgradeState::Applying,
                9,
                $requestId,
                'aaaaaaaaaaaa',
                self::RUNTIME_ID,
                self::BOOT_ID,
                1501,
            );
        } finally {
            self::assertSame(0, $gate->mutationCount);
        }
    }

    #[DataProvider('pausedExportStatusProvider')]
    public function testExplicitCancelStopsOnlyAReferencedPreApplyState(
        string $nextStep,
        string $failureClass,
    ): void
    {
        $requestId = '66666666-6666-4666-8666-666666666666';
        $controlRevision = 11;
        $attempt = \app\service\upgrade\UpgradeOperationAttempt::fromRequestId($requestId);
        $this->files->publishJobControl(self::JOB_ID, $controlRevision, $requestId, (object) [
            'schema_version' => 1,
            'job_id' => self::JOB_ID,
            'action' => 'cancel',
            'requested_at' => 1600,
            'expected_revision' => $controlRevision,
            'request_id' => $requestId,
        ]);
        $this->writeAgentStatus($controlRevision, 'paused', $nextStep, $failureClass);
        $gate = new AgentTransitionGate($this->snapshot(UpgradeState::Paused, 50));
        $service = new UpgradeAgentStateTransitionService($this->operations, $gate, $this->files);

        $first = $service->cancel(
            self::JOB_ID,
            50,
            $controlRevision,
            $requestId,
            $attempt,
            self::RUNTIME_ID,
            self::BOOT_ID,
            1601,
        );
        $replay = $service->cancel(
            self::JOB_ID,
            50,
            $controlRevision,
            $requestId,
            $attempt,
            'runtime-http-2',
            'boot-http-2',
            1602,
        );

        self::assertSame('completed', $first['state']);
        self::assertSame('cancelled', $first['result']['state']);
        self::assertSame(51, $first['result']['revision']);
        self::assertSame($first, $replay);
        self::assertSame(1, $gate->mutationCount);
    }

    /** @return iterable<string,array{string,string}> */
    public static function pausedExportStatusProvider(): iterable
    {
        yield 'export pending' => ['storage_export_pending', ''];
        yield 'export error before apply' => ['storage_export_retry', 'storage_export_failed'];
    }

    private function snapshot(UpgradeState $state, int $revision): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $state,
            $revision,
            $state === UpgradeState::Normal ? null : self::JOB_ID,
            '1.1.0',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            1,
            1,
            1,
            1,
            str_repeat('a', 40),
            false,
            [],
            false,
            null,
            1000,
        );
    }

    private function writeAgentStatus(
        int $revision,
        string $state,
        string $nextStep,
        string $failureClass = 'failed_maintenance',
    ): void
    {
        $path = $this->root . '/jobs/' . self::JOB_ID . '/status.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'job_id' => self::JOB_ID,
            'revision' => $revision,
            'state' => $state,
            'next_step' => $nextStep,
            'failure_class' => $failureClass,
            'platform_sync_pending' => true,
            'platform_receipt_confirmed' => false,
            'safe_to_stop' => false,
            'updated_at' => 1500,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        chmod($path, 0660);
    }

    /** @return array<string,callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => static function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = 37002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 || str_ends_with($path, '/status.json')
                        ? 37001 : 37003;
                }

                return $stat;
            },
            'fstat' => static function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $path = (string) (stream_get_meta_data($handle)['uri'] ?? '');
                    $stat['gid'] = 37002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 || str_ends_with($path, '/status.json')
                        ? 37001 : 37003;
                }

                return $stat;
            },
        ];
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0660);
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @chmod($path, 0770);
        @rmdir($path);
    }
}

final class AgentTransitionGate implements UpgradeGateRepository, UpgradeRecoveryGateRepository, UpgradeDrainGateRepository
{
    public int $mutationCount = 0;
    public int $drainBoundaryCount = 0;
    public bool $failNextMutation = false;

    public function __construct(private UpgradeGateSnapshot $current)
    {
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        return $this->current;
    }

    public function compareAndSet(
        int $expectedRevision,
        UpgradeState $expectedState,
        UpgradeState $nextState,
        string $jobId,
    ): UpgradeGateSnapshot {
        $this->assertCurrent($expectedRevision, $expectedState, $jobId);
        if (!$expectedState->permitsGenericTransitionTo($nextState)) {
            throw new \RuntimeException('generic transition forbidden');
        }
        $this->beforeMutation();
        $this->current = $this->next($nextState, $expectedRevision + 1, $jobId, false);

        return $this->current;
    }

    public function returnToNormal(
        int $expectedRevision,
        UpgradeState $terminalState,
        string $jobId,
        bool $platformSyncPending,
    ): UpgradeGateSnapshot {
        $this->assertCurrent($expectedRevision, $terminalState, $jobId);
        $this->beforeMutation();
        $this->current = $this->next(UpgradeState::Normal, $expectedRevision + 1, null, $platformSyncPending);

        return $this->current;
    }

    public function enterBackingUpAfterDrain(int $expectedRevision, string $jobId): UpgradeGateSnapshot
    {
        $this->assertCurrent($expectedRevision, UpgradeState::Paused, $jobId);
        $this->beforeMutation();
        ++$this->drainBoundaryCount;
        $this->current = $this->next(UpgradeState::BackingUp, $expectedRevision + 1, $jobId, false);

        return $this->current;
    }

    public function resumeFromFailedMaintenance(
        int $expectedRevision,
        UpgradeState $phase,
        string $jobId,
    ): UpgradeGateSnapshot {
        $this->assertCurrent($expectedRevision, UpgradeState::FailedMaintenance, $jobId);
        if (!in_array($phase, [
            UpgradeState::BackingUp,
            UpgradeState::Applying,
            UpgradeState::AwaitingDeployment,
            UpgradeState::Verifying,
            UpgradeState::Reconciling,
        ], true)) {
            throw new \RuntimeException('resume phase forbidden');
        }
        $this->beforeMutation();
        $this->current = $this->next($phase, $expectedRevision + 1, $jobId, false);

        return $this->current;
    }

    public function advanceRuntimeFence(
        int $expectedRevision,
        UpgradeRuntimeIdentity $current,
        UpgradeRuntimeIdentity $target,
        string $jobId,
    ): UpgradeGateSnapshot {
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

    public function clearActivityUncertainty(
        int $expectedRevision,
        array $requiredRoles,
        array $cleanRoleRecords,
    ): UpgradeGateSnapshot {
        throw new \LogicException('not used');
    }

    private function assertCurrent(int $revision, UpgradeState $state, string $jobId): void
    {
        if ($this->current->revision !== $revision || $this->current->state !== $state
            || $this->current->jobId !== $jobId) {
            throw new RuntimeException('UPGRADE_STATE_CONFLICT');
        }
    }

    private function beforeMutation(): void
    {
        $this->mutationCount++;
        if ($this->failNextMutation) {
            $this->failNextMutation = false;
            throw new RuntimeException('injected gate failure');
        }
    }

    private function next(UpgradeState $state, int $revision, ?string $jobId, bool $pending): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $state,
            $revision,
            $jobId,
            $this->current->requiredRuntimeVersion,
            $this->current->requiredDeploymentId,
            $this->current->requiredStorageLayoutVersion,
            $this->current->requiredStorageLayoutGeneration,
            $this->current->deploymentEpoch,
            $this->current->activityGeneration,
            $this->current->redisIncarnation,
            $this->current->uncertain,
            $this->current->taintedBoots,
            $pending,
            null,
            $this->current->updatedAt + 1,
        );
    }
}
