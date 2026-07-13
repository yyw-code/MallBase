<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeRegistrationCoordinator;
use app\service\upgrade\UpgradeRuntimeRegistry;
use app\service\upgrade\UpgradeState;
use app\service\upgrade\UpgradeStateConflict;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;

final class UpgradeRuntimeRegistrationCoordinatorTest extends TestCase
{
    public function testRegistrationCrossingToUncertainTaintsOwnerBeforeReturningWorkPermission(): void
    {
        $events = new RegistrationEventLog();
        $clean = $this->gate(5);
        $uncertain = $this->gate(6, uncertain: true, uncertainRevision: 6);
        $gate = new RegistrationGateRepository($clean, $events);
        $registry = new RegistrationRuntimeRegistry(
            $events,
            static function () use ($gate, $uncertain): void {
                $gate->transitionTo($uncertain);
            },
        );
        $coordinator = new UpgradeRuntimeRegistrationCoordinator($registry, $gate);
        $owner = $this->owner();

        $registration = $coordinator->register($owner, ['default'], false, 'slot-http');
        $events->add('caller.work');

        self::assertContains($owner->key(), $registration->gate->taintedBoots);
        self::assertTrue($registration->record['identity_fenced']);
        self::assertTrue(
            $registration->mayAcceptBusinessWork,
            'Normal uncertainty remains fail-open only after the crossing owner is durably tainted',
        );
        self::assertSame([
            'gate.snapshot:5',
            'registry.register',
            'gate.transition:6',
            'gate.ack:5',
            'gate.snapshot:6',
            'gate.ack:6',
            'gate.tainted:' . $owner->key(),
            'registry.heartbeat:7',
            'caller.work',
        ], $events->events);
    }

    public function testNonNormalGateNeverPermitsBusinessWork(): void
    {
        $events = new RegistrationEventLog();
        $gate = new RegistrationGateRepository($this->gate(5, UpgradeState::Preparing), $events);
        $registry = new RegistrationRuntimeRegistry($events);
        $coordinator = new UpgradeRuntimeRegistrationCoordinator($registry, $gate);

        $registration = $coordinator->register($this->owner(), [], false, 'slot-http');

        self::assertFalse($registration->mayAcceptBusinessWork);
        self::assertFalse($registration->record['identity_fenced']);
        self::assertSame(UpgradeState::Preparing, $registration->gate->state);
    }

    public function testWrongIdentityCannotAcceptWorkEvenWhenNormalGateIsUncertain(): void
    {
        $events = new RegistrationEventLog();
        $gate = new RegistrationGateRepository(
            $this->gate(5, uncertain: true, uncertainRevision: 5),
            $events,
        );
        $registry = new RegistrationRuntimeRegistry($events);
        $coordinator = new UpgradeRuntimeRegistrationCoordinator($registry, $gate);

        try {
            $registration = $coordinator->register($this->owner(self::WRONG_DEPLOYMENT_ID), [], false, 'slot-http');
            self::assertFalse(
                $registration->mayAcceptBusinessWork,
                'Normal uncertainty cannot bypass an actual runtime identity mismatch',
            );
            self::assertTrue($registration->record['identity_fenced']);
        } catch (UpgradeStateConflict) {
            self::addToAssertionCount(1);
        }
    }

    public function testPersistentRevisionRaceFailsBeforeHeartbeatOrWorkPermission(): void
    {
        $events = new RegistrationEventLog();
        $gate = new RegistrationGateRepository($this->gate(5), $events, true);
        $registry = new RegistrationRuntimeRegistry($events);
        $coordinator = new UpgradeRuntimeRegistrationCoordinator($registry, $gate);

        try {
            $coordinator->register($this->owner(), [], false, 'slot-http');
            self::fail('registration returned while every gate acknowledgement raced');
        } catch (UpgradeStateConflict $exception) {
            self::assertSame('UPGRADE_RUNTIME_REGISTRATION_RACE', $exception->getMessage());
        }
        self::assertNotContains('registry.heartbeat:5', $events->events);
    }

    private function owner(string $deploymentId = self::DEPLOYMENT_ID): UpgradeRuntimeInstance
    {
        return new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_ID,
            'http',
            new UpgradeRuntimeIdentity('1.2.0', $deploymentId, 1, 4),
            2,
        );
    }

    private function gate(
        int $revision,
        UpgradeState $state = UpgradeState::Normal,
        bool $uncertain = false,
        ?int $uncertainRevision = null,
    ): UpgradeGateSnapshot {
        return new UpgradeGateSnapshot(
            $state,
            $state === UpgradeState::Normal ? $revision : $revision,
            $state === UpgradeState::Normal ? null : self::JOB_ID,
            '1.2.0',
            self::DEPLOYMENT_ID,
            1,
            4,
            2,
            11,
            str_repeat('a', 40),
            $uncertain,
            [],
            false,
            $uncertain ? 'ACTIVITY_TRACKING_UNCERTAIN' : null,
            1_000,
            $uncertainRevision,
        );
    }

    private const JOB_ID = '018f5d35-3f42-7a31-a731-9e45df3356c2';
    private const RUNTIME_ID = '218f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_ID = '318f5d35-3f42-7a31-a731-9e45df3356c2';
    private const DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
    private const WRONG_DEPLOYMENT_ID = 'b475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}

final class RegistrationEventLog
{
    /** @var list<string> */
    public array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }
}

final class RegistrationRuntimeRegistry implements UpgradeRuntimeRegistry
{
    /** @var array<string,mixed>|null */
    private ?array $record = null;

    public function __construct(
        private readonly RegistrationEventLog $events,
        private readonly ?Closure $afterRegister = null,
    ) {
    }

    public function register(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        UpgradeGateSnapshot $gate,
        string $slotId,
    ): array {
        $this->events->add('registry.register');
        $this->record = [
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
            'registered_at' => 1_000,
            'last_seen_at' => 1_000,
            'retired_at' => null,
        ];
        if ($this->afterRegister !== null) {
            ($this->afterRegister)();
        }

        return $this->record;
    }

    public function heartbeat(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        UpgradeGateSnapshot $gate,
        bool $identityFenced,
        ?int $pausedAckRevision,
    ): array {
        if ($this->record === null) {
            throw new LogicException('runtime was not registered');
        }
        $this->events->add('registry.heartbeat:' . $gate->revision);
        $this->record['queues'] = $queues;
        $this->record['cron_enabled'] = $cronEnabled;
        $this->record['observed_gate_revision'] = $gate->revision;
        $this->record['identity_fenced'] = $this->record['identity_fenced']
            || $identityFenced
            || !$instance->matchesGateSnapshot($gate);
        $this->record['paused_ack_revision'] = $this->record['identity_fenced'] ? null : $pausedAckRevision;
        $this->record['last_seen_at'] = 1_001;

        return $this->record;
    }

    public function active(): array
    {
        return $this->record === null ? [] : [$this->record];
    }

    public function retire(UpgradeRuntimeInstance $instance, int $retiredAt): array
    {
        throw new LogicException('not used');
    }
}

final class RegistrationGateRepository implements UpgradeGateRepository
{
    public function __construct(
        public UpgradeGateSnapshot $current,
        private readonly RegistrationEventLog $events,
        private readonly bool $alwaysConflict = false,
    ) {
    }

    public function transitionTo(UpgradeGateSnapshot $snapshot): void
    {
        $this->current = $snapshot;
        $this->events->add('gate.transition:' . $snapshot->revision);
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        $this->events->add('gate.snapshot:' . $this->current->revision);

        return $this->current;
    }

    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot
    {
        $this->events->add('gate.ack:' . $expectedRevision);
        if ($this->alwaysConflict || $expectedRevision !== $this->current->revision) {
            throw new UpgradeStateConflict();
        }
        if (!$this->current->uncertain) {
            return $this->current;
        }
        $owner = $runtimeRecord['runtime_instance_id'] . ':' . $runtimeRecord['boot_id'] . ':' . $runtimeRecord['role'];
        if (in_array($owner, $this->current->taintedBoots, true)) {
            return $this->current;
        }
        $tainted = [...$this->current->taintedBoots, $owner];
        sort($tainted, SORT_STRING);
        $gate = $this->current;
        $this->current = new UpgradeGateSnapshot(
            $gate->state,
            $gate->revision + 1,
            $gate->jobId,
            $gate->requiredRuntimeVersion,
            $gate->requiredDeploymentId,
            $gate->requiredStorageLayoutVersion,
            $gate->requiredStorageLayoutGeneration,
            $gate->deploymentEpoch,
            $gate->activityGeneration,
            $gate->redisIncarnation,
            true,
            $tainted,
            $gate->platformSyncPending,
            $gate->failureCode,
            $gate->updatedAt,
            $gate->uncertainRevision,
            $gate->replacementBarrierRevision,
            $gate->taintedBootsOverflow,
        );
        $this->events->add('gate.tainted:' . $owner);

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

    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot
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
}
