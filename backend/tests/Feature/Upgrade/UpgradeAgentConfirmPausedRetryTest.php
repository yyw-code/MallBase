<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade;

use app\controller\upgrade\UpgradeAgentController;
use app\service\upgrade\FileUpgradeDrainCheckpointRepository;
use app\service\upgrade\QueueInspector;
use app\service\upgrade\UpgradeActivityLease;
use app\service\upgrade\UpgradeActivitySnapshot;
use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeDrainCoordinator;
use app\service\upgrade\UpgradeDrainGateRepository;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeOperationStore;
use app\service\upgrade\UpgradeQueueInventory;
use app\service\upgrade\UpgradeRuntimeContext;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeOwnerLiveness;
use app\service\upgrade\UpgradeSharedFileStore;
use app\service\upgrade\UpgradeState;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use think\App;
use think\Request;
use think\Response;

final class UpgradeAgentConfirmPausedRetryTest extends TestCase
{
    private const JOB_ID = '44444444-4444-4444-8444-444444444444';
    public const RUNTIME_ID = '55555555-5555-4555-8555-555555555555';
    public const BOOT_ID = '66666666-6666-4666-8666-666666666666';

    private string $root;
    private App $app;
    private UpgradeSharedFileStore $files;
    private UpgradeOperationStore $operations;
    private ConfirmPausedActivityTracker $activity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-confirm-paused-retry-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        foreach (['state', 'run', 'jobs'] as $directory) {
            mkdir($this->root . '/' . $directory, 02770);
            chmod($this->root . '/' . $directory, 02770);
        }
        $this->files = new UpgradeSharedFileStore(
            $this->root,
            39001,
            39002,
            39003,
            1024 * 1024,
            50,
            $this->statOperations(),
        );
        $this->operations = new UpgradeOperationStore($this->files);
        $this->activity = new ConfirmPausedActivityTracker();
        $this->app = new App(dirname(__DIR__, 3));
        $this->app->initialize();
        $this->app->instance(UpgradeOperationStore::class, $this->operations);
        $this->app->instance(UpgradeRuntimeContext::class, new ConfirmPausedRuntimeContext($this->identity()));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    #[DataProvider('pendingDrainCodeProvider')]
    public function testPendingDrainFailureKeepsConfirmPausedOperationRunningAndRetryable(
        UpgradeState $initialState,
        string $pendingCode,
    ): void {
        $gate = new ConfirmPausedGate($this->snapshot($initialState, 10));
        $drain = new UpgradeDrainCoordinator(
            $gate,
            $this->activity,
            new ConfirmPausedQueueInspector(),
            new FileUpgradeDrainCheckpointRepository($this->files),
            static fn(): int => time(),
            static function (int $microseconds): void {
                unset($microseconds);
            },
            1,
        );
        $this->app->instance(UpgradeDrainCoordinator::class, $drain);
        $request = $this->request($gate->snapshot());
        $controller = new UpgradeAgentController($this->app);
        $operationId = $this->operationId();

        $pending = $controller->confirmPaused(self::JOB_ID);
        $pendingBody = $this->responseBody($pending);
        $storedPending = $this->operations->get($operationId);

        self::assertSame(202, $pending->getCode(), json_encode($pendingBody, JSON_THROW_ON_ERROR));
        self::assertSame('running', $pendingBody['data']['status']);
        self::assertSame($pendingCode, $pendingBody['data']['result']['pending_code']);
        self::assertIsArray($storedPending);
        self::assertSame('running', $storedPending['state']);
        self::assertNull($storedPending['error_code']);
        self::assertSame($initialState, $gate->snapshot()->state);

        $this->ageOperation($operationId);
        $this->activity->snapshot = new UpgradeActivitySnapshot(0, 0, 0, 0, 0, false);
        $request->upgrade_agent = ['gate' => $gate->snapshot()];

        $completed = $controller->confirmPaused(self::JOB_ID);
        $completedBody = $this->responseBody($completed);
        $storedCompleted = $this->operations->get($operationId);

        self::assertSame(200, $completed->getCode());
        self::assertSame('completed', $completedBody['data']['status']);
        self::assertSame('paused', $completedBody['data']['result']['state']);
        self::assertIsArray($storedCompleted);
        self::assertSame('completed', $storedCompleted['state']);
        self::assertNull($storedCompleted['error_code']);
        self::assertSame(UpgradeState::Paused, $gate->snapshot()->state);
    }

    /** @return iterable<string,array{UpgradeState,string}> */
    public static function pendingDrainCodeProvider(): iterable
    {
        yield 'draining blocker' => [UpgradeState::Draining, 'UPGRADE_DRAIN_BLOCKED'];
        yield 'paused safety check' => [UpgradeState::Paused, 'UPGRADE_DRAIN_NOT_SAFE'];
    }

    private function request(UpgradeGateSnapshot $gate): Request
    {
        $payload = json_encode([
            'operation_id' => $this->operationId(),
            'expected_revision' => 10,
            'delayed_compatible' => true,
            'attempt' => '',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $request = $this->app->make(Request::class);
        $request->withInput($payload)->withHeader([
            'content-type' => 'application/json',
            'content-length' => (string) strlen($payload),
        ]);
        $request->upgrade_agent = ['gate' => $gate];

        return $request;
    }

    private function operationId(): string
    {
        $checksum = 'sha256:' . hash('sha256', json_encode([10, true, ''], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $this->operations->operationId(self::JOB_ID, 'confirm_paused', $checksum);
    }

    /** @return array<string,mixed> */
    private function responseBody(Response $response): array
    {
        return json_decode($response->getContent(), true, 16, JSON_THROW_ON_ERROR);
    }

    private function ageOperation(string $operationId): void
    {
        $document = $this->files->readJson('upgrade_operations');
        self::assertInstanceOf(stdClass::class, $document);
        self::assertInstanceOf(stdClass::class, $document->operations);
        self::assertInstanceOf(stdClass::class, $document->operations->{$operationId});
        $document->operations->{$operationId}->heartbeat_at = time() - 16;
        $document->operations->{$operationId}->updated_at = time() - 16;
        $this->files->writeJson('upgrade_operations', $document);
    }

    private function identity(): UpgradeRuntimeIdentity
    {
        return new UpgradeRuntimeIdentity(
            '1.2.0',
            '77777777-7777-4777-8777-777777777777',
            1,
            1,
        );
    }

    private function snapshot(UpgradeState $state, int $revision): UpgradeGateSnapshot
    {
        $identity = $this->identity();

        return new UpgradeGateSnapshot(
            $state,
            $revision,
            self::JOB_ID,
            $identity->version,
            $identity->deploymentId,
            $identity->storageLayoutVersion,
            $identity->storageLayoutGeneration,
            1,
            1,
            str_repeat('c', 40),
            false,
            [],
            false,
            null,
            time(),
        );
    }

    /** @return array<string,callable> */
    private function statOperations(): array
    {
        return [
            'lstat' => static function (string $path): array|false {
                $stat = @lstat($path);
                if ($stat !== false) {
                    $stat['gid'] = 39002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 39001 : 39003;
                }

                return $stat;
            },
            'fstat' => static function ($handle): array|false {
                $stat = fstat($handle);
                if ($stat !== false) {
                    $stat['gid'] = 39002;
                    $stat['uid'] = ($stat['mode'] & 0170000) === 0040000 ? 39001 : 39003;
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

final class ConfirmPausedRuntimeContext implements UpgradeRuntimeContext
{
    public function __construct(private readonly UpgradeRuntimeIdentity $identity)
    {
    }

    public function owner(string $role): UpgradeRuntimeInstance
    {
        return new UpgradeRuntimeInstance(
            UpgradeAgentConfirmPausedRetryTest::RUNTIME_ID,
            UpgradeAgentConfirmPausedRetryTest::BOOT_ID,
            $role,
            $this->identity,
            1,
        );
    }
}

final class ConfirmPausedGate implements UpgradeGateRepository, UpgradeDrainGateRepository
{
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
        $this->current = $this->next($nextState, $expectedRevision + 1);

        return $this->current;
    }

    public function enterBackingUpAfterDrain(int $expectedRevision, string $jobId): UpgradeGateSnapshot
    {
        $this->assertCurrent($expectedRevision, UpgradeState::Paused, $jobId);
        $this->current = $this->next(UpgradeState::BackingUp, $expectedRevision + 1);

        return $this->current;
    }

    public function returnToNormal(
        int $expectedRevision,
        UpgradeState $terminalState,
        string $jobId,
        bool $platformSyncPending,
    ): UpgradeGateSnapshot {
        throw new LogicException('not used');
    }

    public function advanceRuntimeFence(
        int $expectedRevision,
        UpgradeRuntimeIdentity $current,
        UpgradeRuntimeIdentity $target,
        string $jobId,
    ): UpgradeGateSnapshot {
        throw new LogicException('not used');
    }

    public function forceMaintenance(int $expectedRevision, string $jobId, string $failureCode): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function clearActivityUncertainty(
        int $expectedRevision,
        array $requiredRoles,
        array $cleanRoleRecords,
    ): UpgradeGateSnapshot {
        throw new LogicException('not used');
    }

    private function assertCurrent(int $revision, UpgradeState $state, string $jobId): void
    {
        if ($this->current->revision !== $revision || $this->current->state !== $state
            || $this->current->jobId !== $jobId) {
            throw new RuntimeException('UPGRADE_DRAIN_STATE_CONFLICT');
        }
    }

    private function next(UpgradeState $state, int $revision): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $state,
            $revision,
            $this->current->jobId,
            $this->current->requiredRuntimeVersion,
            $this->current->requiredDeploymentId,
            $this->current->requiredStorageLayoutVersion,
            $this->current->requiredStorageLayoutGeneration,
            $this->current->deploymentEpoch,
            $this->current->activityGeneration,
            $this->current->redisIncarnation,
            false,
            [],
            false,
            null,
            $this->current->updatedAt + 1,
        );
    }
}

final class ConfirmPausedActivityTracker implements UpgradeActivityTracker
{
    public UpgradeActivitySnapshot $snapshot;

    public function __construct()
    {
        $this->snapshot = new UpgradeActivitySnapshot(1, 0, 0, 0, 0, false);
    }

    public function tryBeginHttp(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function tryBeginExternalCallback(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function tryBeginCron(string $taskId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function beginQueuePop(string $workerId, string $connectorType, array $queues, string $executionAttemptId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function bindQueueJob(UpgradeActivityLease $popLease, string $connection, string $queue, string $jobId): UpgradeActivityLease { return $popLease; }
    public function snapshot(): UpgradeActivitySnapshot { return $this->snapshot; }
    public function heartbeatWorker(string $workerId, string $connectorType, array $queues, UpgradeRuntimeInstance $owner, int $ttl): void {}
    public function ackPaused(string $workerId, UpgradeRuntimeInstance $owner, int $revision, int $ttl): void {}
    public function liveWorkers(): array { return []; }
    public function reconcileQueueLeases(UpgradeQueueInventory $inventory, UpgradeRuntimeOwnerLiveness $owners): void {}
    public function reconcileOrphanActivityLeases(UpgradeRuntimeOwnerLiveness $owners): void {}
}

final class ConfirmPausedQueueInspector implements QueueInspector
{
    public function inventory(): UpgradeQueueInventory
    {
        return new UpgradeQueueInventory([], [], []);
    }
}
