<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\RedisServerIncarnation;
use app\service\upgrade\UpgradeActivityBootstrapper;
use app\service\upgrade\UpgradeActivityLedgerBackend;
use app\service\upgrade\UpgradeActivityLedgerInitializer;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeQueueInventory;
use app\service\upgrade\UpgradeRuntimeDeploymentInventory;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeOwnerLiveness;
use app\service\upgrade\UpgradeRuntimeRecordLookup;
use app\service\upgrade\UpgradeRuntimeRecoveryCoordinator;
use app\service\upgrade\UpgradeRuntimeRegistrationCoordinator;
use app\service\upgrade\UpgradeRuntimeRegistry;
use app\service\upgrade\UpgradeRuntimeRetirementEvidenceStore;
use app\service\upgrade\UpgradeRuntimeRetirementGuard;
use app\service\upgrade\UpgradeState;
use app\service\upgrade\UpgradeStateConflict;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class UpgradeRuntimeRecoveryCoordinatorTest extends TestCase
{
    public function testBeginTaintsAllRegistryOwnersBeforeInitializingRandomReplacementGeneration(): void
    {
        $events = new RecoveryEventLog();
        $initial = $this->gate(5, 11, self::OLD_RUN_ID, null);
        $gate = new RecoveryGateRepository($initial, $events, self::RANDOM_GENERATION);
        $records = [
            $this->runtimeRecord('cron', self::BOOT_C, $initial),
            $this->runtimeRecord('http', self::BOOT_A, $initial),
            $this->runtimeRecord('queue', self::BOOT_B, $initial),
        ];
        $ledger = new RecoveryLedger($events);
        $coordinator = $this->coordinator(
            $gate,
            new RecoveryRuntimeRegistry($records, $events),
            $ledger,
            self::NEW_RUN_ID,
            ['http', 'queue', 'cron'],
            new RecoveryRetirementGuard($events),
        );

        $barrier = $coordinator->beginReplacementLedger();

        $expectedOwners = array_map($this->recordOwnerKey(...), $records);
        sort($expectedOwners, SORT_STRING);
        self::assertSame($expectedOwners, $barrier->taintedBoots);
        self::assertSame(self::RANDOM_GENERATION, $barrier->activityGeneration);
        self::assertNotSame($initial->activityGeneration + 1, $barrier->activityGeneration);
        self::assertSame(self::NEW_RUN_ID, $barrier->redisIncarnation);
        self::assertSame([[self::RANDOM_GENERATION, self::NEW_RUN_ID]], $ledger->initializeCalls);
        self::assertSame([[self::RANDOM_GENERATION, self::NEW_RUN_ID]], $ledger->snapshotCalls);
        self::assertSame([
            'gate.snapshot:5',
            'registry.active',
            'gate.taint:' . implode(',', $expectedOwners),
            'gate.begin:6:' . self::NEW_RUN_ID,
            'ledger.initialize:' . self::RANDOM_GENERATION . ':' . self::NEW_RUN_ID,
            'ledger.snapshot:' . self::RANDOM_GENERATION . ':' . self::NEW_RUN_ID,
        ], $events->events);
    }

    public function testBootstrapFailureCanRecoverThroughBarrierCleanWorkerRegistrationAndClear(): void
    {
        $events = new RecoveryEventLog();
        $clean = new UpgradeGateSnapshot(
            UpgradeState::Normal,
            5,
            null,
            '1.2.0',
            self::DEPLOYMENT_ID,
            1,
            4,
            2,
            11,
            self::OLD_RUN_ID,
            false,
            [],
            false,
            null,
            1_000,
        );
        $gate = new RecoveryGateRepository($clean, $events, self::RANDOM_GENERATION);
        $bootstrap = new UpgradeActivityBootstrapper(
            new class implements UpgradeActivityLedgerInitializer {
                public function initializeLedger(): void
                {
                    throw new \RuntimeException('simulated bootstrap failure');
                }
            },
            $gate,
        );
        self::assertFalse($bootstrap->initialize());
        self::assertTrue($gate->current->uncertain);

        $registry = new RecoveryRuntimeRegistry([], $events);
        $ledger = new RecoveryLedger($events);
        $recovery = $this->coordinator(
            $gate,
            $registry,
            $ledger,
            self::NEW_RUN_ID,
            ['http'],
            new RecoveryRetirementGuard($events),
        );
        $barrier = $recovery->beginReplacementLedger();
        self::assertNotNull($barrier->replacementBarrierRevision);

        $replacement = new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_D,
            'http',
            new UpgradeRuntimeIdentity('1.2.0', self::DEPLOYMENT_ID, 1, 4),
            2,
        );
        $registration = (new UpgradeRuntimeRegistrationCoordinator($registry, $gate))->register(
            $replacement,
            [],
            false,
            'slot-http-replacement',
        );
        self::assertTrue($registration->mayAcceptBusinessWork);
        self::assertFalse($registration->record['identity_fenced']);

        $cleared = $recovery->clearUncertainty();
        self::assertFalse($cleared->uncertain);
        self::assertSame(UpgradeState::Normal, $cleared->state);

        $listenerSource = file_get_contents(dirname(__DIR__, 3) . '/app/listener/swoole/WorkerBootListener.php');
        self::assertIsString($listenerSource);
        self::assertStringNotContainsString(
            "|| defined('MALLBASE_AUTOMATIC_UPGRADE_DISABLED')",
            $listenerSource,
            'the automatic-upgrade latch must not suppress runtime lifecycle registration',
        );
    }

    public function testInitializeFailureLeavesBarrierUnclearableWhenLedgerWasNeverCreated(): void
    {
        $events = new RecoveryEventLog();
        $gate = new RecoveryGateRepository(
            $this->gate(5, 11, self::OLD_RUN_ID, null),
            $events,
            self::RANDOM_GENERATION,
        );
        $ledger = new RecoveryLedger($events, initializeThrows: true);
        $coordinator = $this->coordinator(
            $gate,
            new RecoveryRuntimeRegistry([], $events),
            $ledger,
            self::NEW_RUN_ID,
            ['http'],
            new RecoveryRetirementGuard($events),
        );

        try {
            $coordinator->beginReplacementLedger();
            self::fail('failed ledger initialization was reported as successful');
        } catch (UpgradeStateConflict) {
        }
        self::assertNotNull($gate->current->replacementBarrierRevision);

        try {
            $coordinator->clearUncertainty();
            self::fail('a barrier without an initialized ledger was cleared');
        } catch (UpgradeStateConflict) {
        }
        self::assertSame(0, $gate->clearCalls);
    }

    public function testClearUsesAuthoritativeRequiredRolesAndFullRegistryProjection(): void
    {
        $events = new RecoveryEventLog();
        $ready = $this->gate(7, self::RANDOM_GENERATION, self::NEW_RUN_ID, 7, []);
        $records = [
            $this->runtimeRecord('http', self::BOOT_A, $ready),
            $this->runtimeRecord('queue', self::BOOT_B, $ready),
            $this->runtimeRecord('cron', self::BOOT_C, $ready),
            $this->runtimeRecord('http', self::BOOT_D, $ready),
        ];
        $gate = new RecoveryGateRepository($ready, $events, self::RANDOM_GENERATION);
        $coordinator = $this->coordinator(
            $gate,
            new RecoveryRuntimeRegistry($records, $events),
            new RecoveryLedger(
                $events,
                initialized: true,
                generation: self::RANDOM_GENERATION,
                runId: self::NEW_RUN_ID,
            ),
            self::NEW_RUN_ID,
            ['http', 'queue', 'cron'],
            new RecoveryRetirementGuard($events),
        );

        $cleared = $coordinator->clearUncertainty();

        self::assertFalse($cleared->uncertain);
        self::assertSame(['http', 'queue', 'cron'], $gate->lastRequiredRoles);
        self::assertSame($records, $gate->lastCleanRecords, 'the registry projection must not be sampled or inferred');
        self::assertSame(1, $gate->clearCalls);
    }

    public function testClearRejectsRegistryMissingAuthoritativeQueueAndCronRoles(): void
    {
        $events = new RecoveryEventLog();
        $ready = $this->gate(7, self::RANDOM_GENERATION, self::NEW_RUN_ID, 7, []);
        $gate = new RecoveryGateRepository($ready, $events, self::RANDOM_GENERATION);
        $coordinator = $this->coordinator(
            $gate,
            new RecoveryRuntimeRegistry([$this->runtimeRecord('http', self::BOOT_A, $ready)], $events),
            new RecoveryLedger(
                $events,
                initialized: true,
                generation: self::RANDOM_GENERATION,
                runId: self::NEW_RUN_ID,
            ),
            self::NEW_RUN_ID,
            ['http', 'queue', 'cron'],
            new RecoveryRetirementGuard($events),
        );

        $this->expectException(UpgradeStateConflict::class);
        $coordinator->clearUncertainty();
    }

    public function testClearRejectsNonEmptyLedger(): void
    {
        [$coordinator, $gate] = $this->clearScenario([['entry_id' => 'still-running']], true, self::NEW_RUN_ID);

        try {
            $coordinator->clearUncertainty();
            self::fail('a non-empty replacement ledger was cleared');
        } catch (UpgradeStateConflict) {
        }
        self::assertSame(0, $gate->clearCalls);
    }

    public function testClearRejectsMissingLedger(): void
    {
        [$coordinator, $gate] = $this->clearScenario([], false, self::NEW_RUN_ID);

        try {
            $coordinator->clearUncertainty();
            self::fail('a missing replacement ledger was treated as empty');
        } catch (UpgradeStateConflict) {
        }
        self::assertSame(0, $gate->clearCalls);
    }

    public function testClearRejectsRedisRunChange(): void
    {
        [$coordinator, $gate] = $this->clearScenario([], true, self::CHANGED_RUN_ID);

        try {
            $coordinator->clearUncertainty();
            self::fail('a replacement ledger from an old Redis run was cleared');
        } catch (UpgradeStateConflict) {
        }
        self::assertSame(0, $gate->clearCalls);
    }

    public function testClearRejectsRetirementSagaPendingAfterGateRemoval(): void
    {
        $events = new RecoveryEventLog();
        $ready = $this->gate(7, self::RANDOM_GENERATION, self::NEW_RUN_ID, 7, []);
        $record = $this->runtimeRecord('http', self::BOOT_A, $ready);
        $gate = new RecoveryGateRepository($ready, $events, self::RANDOM_GENERATION);
        $coordinator = $this->coordinator(
            $gate,
            new RecoveryRuntimeRegistry([$record], $events),
            new RecoveryLedger(
                $events,
                initialized: true,
                generation: self::RANDOM_GENERATION,
                runId: self::NEW_RUN_ID,
            ),
            self::NEW_RUN_ID,
            ['http'],
            new RecoveryRetirementGuard($events),
            new RecoveryRetirementEvidence($record, 'gate_retired', 1_100),
        );

        try {
            $coordinator->clearUncertainty();
            self::fail('uncertainty cleared before the retirement saga committed');
        } catch (UpgradeStateConflict $exception) {
            self::assertSame('UPGRADE_RUNTIME_RETIREMENT_PENDING', $exception->getMessage());
        }
        self::assertSame(0, $gate->clearCalls);
    }

    public function testRetirementCallbackRunsInsideDurableGuardAndOrdersRegistryBeforeGateRemoval(): void
    {
        $events = new RecoveryEventLog();
        $record = $this->runtimeRecord(
            'http',
            self::BOOT_A,
            $this->gate(7, self::RANDOM_GENERATION, self::NEW_RUN_ID, 7, []),
        );
        $ownerKey = $this->recordOwnerKey($record);
        $gate = new RecoveryGateRepository(
            $this->gate(7, self::RANDOM_GENERATION, self::NEW_RUN_ID, 7, [$ownerKey]),
            $events,
            self::RANDOM_GENERATION,
        );
        $registry = new RecoveryRuntimeRegistry([$record], $events);
        $coordinator = $this->coordinator(
            $gate,
            $registry,
            new RecoveryLedger($events),
            self::NEW_RUN_ID,
            ['http'],
            new RecoveryRetirementGuard($events, true),
        );

        $retired = $coordinator->retireEligibleTaintedOwners(1_100);

        self::assertSame([], $retired->taintedBoots);
        self::assertSame([
            'gate.snapshot:7',
            'registry.active',
            'guard.enter:' . $ownerKey,
            'guard.durable_tombstone:' . $ownerKey,
            'registry.retire:' . $ownerKey,
            'gate.remove:' . $ownerKey,
            'guard.return:' . $ownerKey,
        ], $events->events);
        self::assertSame([], $registry->activeRecords);
    }

    public function testRetirementSagaResumesAfterRegistryCommitBeforeGateRemoval(): void
    {
        $events = new RecoveryEventLog();
        $ready = $this->gate(7, self::RANDOM_GENERATION, self::NEW_RUN_ID, 7, []);
        $record = $this->runtimeRecord('http', self::BOOT_A, $ready);
        $ownerKey = $this->recordOwnerKey($record);
        $gate = new RecoveryGateRepository(
            $this->gate(7, self::RANDOM_GENERATION, self::NEW_RUN_ID, 7, [$ownerKey]),
            $events,
            self::RANDOM_GENERATION,
            failRemoveAttempts: 4,
        );
        $registry = new RecoveryRuntimeRegistry([$record], $events);
        $evidence = new RecoveryRetirementEvidence($record, 'prepared', 1_100);
        $coordinator = $this->coordinator(
            $gate,
            $registry,
            new RecoveryLedger($events),
            self::NEW_RUN_ID,
            ['http'],
            new RecoveryRetirementGuard($events, true),
            $evidence,
        );

        try {
            $coordinator->retireEligibleTaintedOwners(1_101);
            self::fail('injected gate failure did not interrupt the retirement saga');
        } catch (UpgradeStateConflict $exception) {
            self::assertSame('UPGRADE_ACTIVITY_RECOVERY_RACE', $exception->getMessage());
        }
        self::assertSame('registry_retired', $evidence->state);
        self::assertSame([$ownerKey], $gate->current->taintedBoots);
        self::assertSame('retired', $registry->findByOwnerKey($ownerKey)['state']);

        $recovered = $coordinator->retireEligibleTaintedOwners(1_102);

        self::assertSame([], $recovered->taintedBoots);
        self::assertSame('committed', $evidence->state);
        self::assertSame(1_100, $registry->findByOwnerKey($ownerKey)['retired_at']);
        self::assertSame(1, count(array_filter(
            $events->events,
            static fn(string $event): bool => $event === 'registry.retire:' . $ownerKey,
        )));
    }

    public function testRetirementSagaResumesWhenGateCommittedBeforeEvidenceAdvance(): void
    {
        $events = new RecoveryEventLog();
        $ready = $this->gate(7, self::RANDOM_GENERATION, self::NEW_RUN_ID, 7, []);
        $record = $this->runtimeRecord('http', self::BOOT_A, $ready);
        $ownerKey = $this->recordOwnerKey($record);
        $gate = new RecoveryGateRepository(
            $this->gate(7, self::RANDOM_GENERATION, self::NEW_RUN_ID, 7, [$ownerKey]),
            $events,
            self::RANDOM_GENERATION,
        );
        $registry = new RecoveryRuntimeRegistry([$record], $events);
        $evidence = new RecoveryRetirementEvidence(
            $record,
            'prepared',
            1_100,
            failAdvanceOnce: 'registry_retired:gate_retired',
        );
        $coordinator = $this->coordinator(
            $gate,
            $registry,
            new RecoveryLedger($events),
            self::NEW_RUN_ID,
            ['http'],
            new RecoveryRetirementGuard($events, true),
            $evidence,
        );

        try {
            $coordinator->retireEligibleTaintedOwners(1_101);
            self::fail('injected evidence failure did not interrupt the retirement saga');
        } catch (UpgradeStateConflict $exception) {
            self::assertSame('injected-evidence-advance-failure', $exception->getMessage());
        }
        self::assertSame('registry_retired', $evidence->state);
        self::assertSame([], $gate->current->taintedBoots);

        $recovered = $coordinator->retireEligibleTaintedOwners(1_102);

        self::assertSame([], $recovered->taintedBoots);
        self::assertSame('committed', $evidence->state);
        self::assertSame(1, count(array_filter(
            $events->events,
            static fn(string $event): bool => $event === 'gate.remove:' . $ownerKey,
        )));
    }

    /** @return array{UpgradeRuntimeRecoveryCoordinator,RecoveryGateRepository} */
    private function clearScenario(array $entries, bool $initialized, string $currentRunId): array
    {
        $events = new RecoveryEventLog();
        $ready = $this->gate(7, self::RANDOM_GENERATION, self::NEW_RUN_ID, 7, []);
        $record = $this->runtimeRecord('http', self::BOOT_A, $ready);
        $gate = new RecoveryGateRepository($ready, $events, self::RANDOM_GENERATION);
        $coordinator = $this->coordinator(
            $gate,
            new RecoveryRuntimeRegistry([$record], $events),
            new RecoveryLedger(
                $events,
                initialized: $initialized,
                generation: self::RANDOM_GENERATION,
                runId: self::NEW_RUN_ID,
                entries: $entries,
            ),
            $currentRunId,
            ['http'],
            new RecoveryRetirementGuard($events),
        );

        return [$coordinator, $gate];
    }

    /** @param list<string> $requiredRoles */
    private function coordinator(
        RecoveryGateRepository $gate,
        RecoveryRuntimeRegistry $runtimes,
        RecoveryLedger $ledger,
        string $currentRunId,
        array $requiredRoles,
        RecoveryRetirementGuard $retirement,
        ?UpgradeRuntimeRetirementEvidenceStore $evidence = null,
    ): UpgradeRuntimeRecoveryCoordinator {
        return new UpgradeRuntimeRecoveryCoordinator(
            $gate,
            $runtimes,
            $ledger,
            new RedisServerIncarnation(new stdClass(), static fn(): string => $currentRunId),
            new RecoveryDeploymentInventory($requiredRoles),
            $retirement,
            $evidence,
            $runtimes,
        );
    }

    /** @param list<string> $taintedBoots */
    private function gate(
        int $revision,
        int $generation,
        string $runId,
        ?int $barrierRevision,
        array $taintedBoots = [],
    ): UpgradeGateSnapshot {
        return new UpgradeGateSnapshot(
            UpgradeState::Normal,
            $revision,
            null,
            '1.2.0',
            self::DEPLOYMENT_ID,
            1,
            4,
            2,
            $generation,
            $runId,
            true,
            $taintedBoots,
            false,
            'ACTIVITY_TRACKING_UNCERTAIN',
            1_000,
            5,
            $barrierRevision,
        );
    }

    /** @return array<string,mixed> */
    private function runtimeRecord(string $role, string $bootId, UpgradeGateSnapshot $gate): array
    {
        return [
            'schema_version' => 2,
            'state' => 'active',
            'runtime_instance_id' => self::RUNTIME_ID,
            'boot_id' => $bootId,
            'role' => $role,
            'app_version' => $gate->requiredRuntimeVersion,
            'deployment_id' => $gate->requiredDeploymentId,
            'storage_layout_version' => $gate->requiredStorageLayoutVersion,
            'storage_layout_generation' => $gate->requiredStorageLayoutGeneration,
            'observed_deployment_epoch' => $gate->deploymentEpoch,
            'boot_registration_revision' => $gate->replacementBarrierRevision ?? $gate->revision,
            'activity_generation' => $gate->activityGeneration,
            'redis_incarnation' => $gate->redisIncarnation,
            'queues' => $role === 'queue' ? ['default'] : [],
            'cron_enabled' => $role === 'cron',
            'observed_gate_revision' => $gate->revision,
            'identity_fenced' => false,
            'paused_ack_revision' => null,
            'slot_id' => 'slot-' . $role . '-' . substr($bootId, 0, 4),
            'registered_at' => 1_000,
            'last_seen_at' => 1_000,
            'retired_at' => null,
        ];
    }

    /** @param array<string,mixed> $record */
    private function recordOwnerKey(array $record): string
    {
        return $record['runtime_instance_id'] . ':' . $record['boot_id'] . ':' . $record['role'];
    }

    private const RANDOM_GENERATION = 918_273;
    private const OLD_RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NEW_RUN_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const CHANGED_RUN_ID = 'cccccccccccccccccccccccccccccccccccccccc';
    private const RUNTIME_ID = '218f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_A = '318f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_B = '418f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_C = '518f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_D = '618f5d35-3f42-7a31-a731-9e45df3356c2';
    private const DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}

final class RecoveryEventLog
{
    /** @var list<string> */
    public array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }
}

final class RecoveryRuntimeRegistry implements UpgradeRuntimeRegistry, UpgradeRuntimeRecordLookup
{
    /** @var list<array<string,mixed>> */
    public array $retiredRecords = [];

    /** @param list<array<string,mixed>> $activeRecords */
    public function __construct(
        public array $activeRecords,
        private readonly RecoveryEventLog $events,
    ) {
    }

    public function register(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        UpgradeGateSnapshot $gate,
        string $slotId,
    ): array {
        $record = [
            'schema_version' => 2,
            'state' => 'active',
            ...$instance->toArray(),
            'boot_registration_revision' => $gate->revision,
            'activity_generation' => $gate->activityGeneration,
            'redis_incarnation' => $gate->redisIncarnation,
            'queues' => $queues,
            'cron_enabled' => $cronEnabled,
            'observed_gate_revision' => $gate->revision,
            'identity_fenced' => !$instance->matchesGateSnapshot($gate),
            'paused_ack_revision' => null,
            'slot_id' => $slotId,
            'registered_at' => 1_100,
            'last_seen_at' => 1_100,
            'retired_at' => null,
        ];
        $this->activeRecords[] = $record;

        return $record;
    }

    public function heartbeat(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        UpgradeGateSnapshot $gate,
        bool $identityFenced,
        ?int $pausedAckRevision,
    ): array {
        foreach ($this->activeRecords as $index => $record) {
            $key = $record['runtime_instance_id'] . ':' . $record['boot_id'] . ':' . $record['role'];
            if ($key !== $instance->key()) {
                continue;
            }
            $record['queues'] = $queues;
            $record['cron_enabled'] = $cronEnabled;
            $record['observed_gate_revision'] = $gate->revision;
            $record['identity_fenced'] = $identityFenced;
            $record['paused_ack_revision'] = $pausedAckRevision;
            $record['last_seen_at'] = 1_101;
            $this->activeRecords[$index] = $record;

            return $record;
        }
        throw new UpgradeStateConflict('UPGRADE_RUNTIME_NOT_REGISTERED');
    }

    public function active(): array
    {
        $this->events->add('registry.active');

        return $this->activeRecords;
    }

    public function retire(UpgradeRuntimeInstance $instance, int $retiredAt): array
    {
        $this->events->add('registry.retire:' . $instance->key());
        foreach ($this->activeRecords as $index => $record) {
            $key = $record['runtime_instance_id'] . ':' . $record['boot_id'] . ':' . $record['role'];
            if ($key !== $instance->key()) {
                continue;
            }
            unset($this->activeRecords[$index]);
            $this->activeRecords = array_values($this->activeRecords);
            $record['state'] = 'retired';
            $record['retired_at'] = $retiredAt;
            $this->retiredRecords[] = $record;

            return $record;
        }
        foreach ($this->retiredRecords as $record) {
            $key = $record['runtime_instance_id'] . ':' . $record['boot_id'] . ':' . $record['role'];
            if ($key === $instance->key() && $record['retired_at'] === $retiredAt) {
                return $record;
            }
        }
        throw new UpgradeStateConflict('UPGRADE_RUNTIME_NOT_REGISTERED');
    }

    public function findByOwnerKey(string $ownerKey): ?array
    {
        foreach ([...$this->activeRecords, ...$this->retiredRecords] as $record) {
            $key = $record['runtime_instance_id'] . ':' . $record['boot_id'] . ':' . $record['role'];
            if ($key === $ownerKey) {
                return $record;
            }
        }

        return null;
    }
}

final class RecoveryLedger implements UpgradeActivityLedgerBackend
{
    /** @var list<array{int,string}> */
    public array $initializeCalls = [];
    /** @var list<array{int,string}> */
    public array $snapshotCalls = [];

    /** @param list<array<string,mixed>> $entries */
    public function __construct(
        private readonly RecoveryEventLog $events,
        private readonly bool $initializeThrows = false,
        private bool $initialized = false,
        private int $generation = 0,
        private string $runId = '',
        private array $entries = [],
    ) {
    }

    public function initialize(int $generation, string $serverRunId): void
    {
        $this->events->add('ledger.initialize:' . $generation . ':' . $serverRunId);
        $this->initializeCalls[] = [$generation, $serverRunId];
        if ($this->initializeThrows) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }
        $this->initialized = true;
        $this->generation = $generation;
        $this->runId = $serverRunId;
    }

    public function snapshot(int $generation, string $serverRunId): array
    {
        $this->events->add('ledger.snapshot:' . $generation . ':' . $serverRunId);
        $this->snapshotCalls[] = [$generation, $serverRunId];
        if (!$this->initialized || $generation !== $this->generation || !hash_equals($this->runId, $serverRunId)) {
            throw new UpgradeStateConflict('UPGRADE_ACTIVITY_TRACKING_UNCERTAIN');
        }

        return $this->entries;
    }

    public function begin(UpgradeGateSnapshot $gate, string $entryId, array $payload, array $allowedStates): ?string
    {
        throw new LogicException('not used');
    }

    public function bind(int $generation, string $serverRunId, string $entryId, string $token, array $payload): ?string
    {
        throw new LogicException('not used');
    }

    public function release(int $generation, string $serverRunId, string $entryId, string $token): void
    {
        throw new LogicException('not used');
    }

    public function heartbeatWorker(UpgradeGateSnapshot $gate, string $workerId, array $worker): void
    {
        throw new LogicException('not used');
    }

    public function ackPaused(
        UpgradeGateSnapshot $gate,
        string $workerId,
        UpgradeRuntimeInstance $owner,
        int $revision,
        int $expiresAt,
    ): void {
        throw new LogicException('not used');
    }

    public function liveWorkers(UpgradeGateSnapshot $gate, int $now): array
    {
        throw new LogicException('not used');
    }

    public function reconcileQueue(
        int $generation,
        string $serverRunId,
        UpgradeQueueInventory $inventory,
        UpgradeRuntimeOwnerLiveness $owners,
    ): void {
        throw new LogicException('not used');
    }

    public function reconcileOrphans(
        int $generation,
        string $serverRunId,
        UpgradeRuntimeOwnerLiveness $owners,
    ): void {
        throw new LogicException('not used');
    }
}

final readonly class RecoveryDeploymentInventory implements UpgradeRuntimeDeploymentInventory
{
    /** @param list<string> $roles */
    public function __construct(private array $roles)
    {
    }

    public function requiredRoles(): array
    {
        return $this->roles;
    }
}

final readonly class RecoveryRetirementGuard implements UpgradeRuntimeRetirementGuard
{
    public function __construct(
        private RecoveryEventLog $events,
        private bool $proven = false,
    ) {
    }

    public function retireIfProven(array $runtimeRecord, int $now, Closure $afterDurableTombstone): bool
    {
        $owner = $runtimeRecord['runtime_instance_id'] . ':' . $runtimeRecord['boot_id'] . ':' . $runtimeRecord['role'];
        $this->events->add('guard.enter:' . $owner);
        if (!$this->proven) {
            $this->events->add('guard.not_proven:' . $owner);

            return false;
        }
        $this->events->add('guard.durable_tombstone:' . $owner);
        $afterDurableTombstone();
        $this->events->add('guard.return:' . $owner);

        return true;
    }
}

final class RecoveryRetirementEvidence implements UpgradeRuntimeRetirementEvidenceStore
{
    /** @param array<string,mixed> $runtimeRecord */
    public function __construct(
        private readonly array $runtimeRecord,
        public string $state,
        private readonly int $retiredAt,
        private ?string $failAdvanceOnce = null,
    ) {
    }

    public function observe(array $runtimeRecord, ?array $redisRecord, int $now, int $windowSeconds): bool
    {
        return true;
    }

    public function prepareIfUnchanged(array $runtimeRecord, ?array $redisRecord, int $now, int $windowSeconds): bool
    {
        return true;
    }

    public function pending(): array
    {
        if ($this->state === 'committed') {
            return [];
        }

        return [[
            'owner_key' => $this->ownerKey(),
            'state' => $this->state,
            'runtime_record' => $this->runtimeRecord,
            'retired_at' => $this->retiredAt,
        ]];
    }

    public function advance(string $ownerKey, string $expectedState, string $nextState, int $now): void
    {
        if ($ownerKey !== $this->ownerKey() || $expectedState !== $this->state) {
            throw new UpgradeStateConflict('invalid-evidence-transition');
        }
        $transition = $expectedState . ':' . $nextState;
        if ($this->failAdvanceOnce === $transition) {
            $this->failAdvanceOnce = null;
            throw new UpgradeStateConflict('injected-evidence-advance-failure');
        }
        $this->state = $nextState;
    }

    public function reset(string $ownerKey): void
    {
        throw new LogicException('not used');
    }

    private function ownerKey(): string
    {
        return $this->runtimeRecord['runtime_instance_id'] . ':'
            . $this->runtimeRecord['boot_id'] . ':'
            . $this->runtimeRecord['role'];
    }
}

final class RecoveryGateRepository implements UpgradeGateRepository
{
    public int $clearCalls = 0;
    /** @var list<string> */
    public array $lastRequiredRoles = [];
    /** @var list<array<string,mixed>> */
    public array $lastCleanRecords = [];

    public function __construct(
        public UpgradeGateSnapshot $current,
        private readonly RecoveryEventLog $events,
        private readonly int $nextGeneration,
        private int $failRemoveAttempts = 0,
    ) {
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        $this->events->add('gate.snapshot:' . $this->current->revision);

        return $this->current;
    }

    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot
    {
        if ($expectedRevision !== $this->current->revision) {
            throw new UpgradeStateConflict();
        }
        $owners = array_values(array_unique([...$this->current->taintedBoots, ...$taintedBoots]));
        sort($owners, SORT_STRING);
        $this->events->add('gate.taint:' . implode(',', $owners));
        $gate = $this->current;
        $this->current = $this->copy(
            $gate,
            revision: $gate->revision + 1,
            generation: $gate->activityGeneration,
            runId: $gate->redisIncarnation,
            taintedBoots: $owners,
            uncertain: true,
            uncertainRevision: $gate->uncertainRevision ?? $gate->revision + 1,
            barrierRevision: $gate->replacementBarrierRevision,
        );

        return $this->current;
    }

    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot
    {
        if ($expectedRevision !== $this->current->revision) {
            throw new UpgradeStateConflict();
        }
        $this->events->add('gate.begin:' . $expectedRevision . ':' . $redisIncarnation);
        $gate = $this->current;
        $nextRevision = $gate->revision + 1;
        $this->current = $this->copy(
            $gate,
            revision: $nextRevision,
            generation: $this->nextGeneration,
            runId: $redisIncarnation,
            taintedBoots: $gate->taintedBoots,
            uncertain: true,
            uncertainRevision: $gate->uncertainRevision,
            barrierRevision: $nextRevision,
        );

        return $this->current;
    }

    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot
    {
        if ($expectedRevision !== $this->current->revision) {
            throw new UpgradeStateConflict();
        }
        if ($this->failRemoveAttempts > 0) {
            $this->failRemoveAttempts--;
            throw new UpgradeStateConflict('injected-gate-remove-failure');
        }
        $this->events->add('gate.remove:' . $ownerKey);
        $gate = $this->current;
        $remaining = array_values(array_filter(
            $gate->taintedBoots,
            static fn(string $owner): bool => $owner !== $ownerKey,
        ));
        $this->current = $this->copy(
            $gate,
            revision: $gate->revision + 1,
            generation: $gate->activityGeneration,
            runId: $gate->redisIncarnation,
            taintedBoots: $remaining,
            uncertain: true,
            uncertainRevision: $gate->uncertainRevision,
            barrierRevision: $gate->replacementBarrierRevision,
        );

        return $this->current;
    }

    public function clearActivityUncertainty(
        int $expectedRevision,
        array $requiredRoles,
        array $cleanRoleRecords,
    ): UpgradeGateSnapshot {
        $this->clearCalls++;
        $this->lastRequiredRoles = $requiredRoles;
        $this->lastCleanRecords = $cleanRoleRecords;
        $this->events->add('gate.clear');
        if ($expectedRevision !== $this->current->revision) {
            throw new UpgradeStateConflict();
        }
        $roles = array_values(array_unique(array_column($cleanRoleRecords, 'role')));
        foreach ($requiredRoles as $requiredRole) {
            if (!in_array($requiredRole, $roles, true)) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_RECOVERY_INVALID');
            }
        }
        $gate = $this->current;
        $this->current = $this->copy(
            $gate,
            revision: $gate->revision + 1,
            generation: $gate->activityGeneration,
            runId: $gate->redisIncarnation,
            taintedBoots: [],
            uncertain: false,
            uncertainRevision: null,
            barrierRevision: null,
        );

        return $this->current;
    }

    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot
    {
        if ($expectedRevision !== $this->current->revision) {
            throw new UpgradeStateConflict('revision race');
        }
        $runtime = UpgradeRuntimeInstance::fromArray([
            'runtime_instance_id' => $runtimeRecord['runtime_instance_id'],
            'boot_id' => $runtimeRecord['boot_id'],
            'role' => $runtimeRecord['role'],
            'app_version' => $runtimeRecord['app_version'],
            'deployment_id' => $runtimeRecord['deployment_id'],
            'storage_layout_version' => $runtimeRecord['storage_layout_version'],
            'storage_layout_generation' => $runtimeRecord['storage_layout_generation'],
            'observed_deployment_epoch' => $runtimeRecord['observed_deployment_epoch'],
        ]);
        if (!$runtime->isCleanReplacementFor(
            $this->current,
            (int) $runtimeRecord['boot_registration_revision'],
            (int) $runtimeRecord['activity_generation'],
            (string) $runtimeRecord['redis_incarnation'],
        )) {
            return $this->recordActivityUncertainty($expectedRevision, [$runtime->key()]);
        }

        return $this->current;
    }

    public function compareAndSet(
        int $expectedRevision,
        UpgradeState $expectedState,
        UpgradeState $nextState,
        string $jobId,
    ): UpgradeGateSnapshot {
        throw new LogicException('not used');
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

    private function copy(
        UpgradeGateSnapshot $gate,
        int $revision,
        int $generation,
        string $runId,
        array $taintedBoots,
        bool $uncertain,
        ?int $uncertainRevision,
        ?int $barrierRevision,
    ): UpgradeGateSnapshot {
        return new UpgradeGateSnapshot(
            $gate->state,
            $revision,
            $gate->jobId,
            $gate->requiredRuntimeVersion,
            $gate->requiredDeploymentId,
            $gate->requiredStorageLayoutVersion,
            $gate->requiredStorageLayoutGeneration,
            $gate->deploymentEpoch,
            $generation,
            $runId,
            $uncertain,
            $taintedBoots,
            $gate->platformSyncPending,
            $uncertain ? $gate->failureCode : null,
            $gate->updatedAt,
            $uncertainRevision,
            $barrierRevision,
            $uncertain && $gate->taintedBootsOverflow,
        );
    }
}
