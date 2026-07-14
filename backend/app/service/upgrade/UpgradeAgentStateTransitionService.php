<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;
use Throwable;

/** Executes only the fixed upgrade-state edges owned by the foreground Agent. */
final readonly class UpgradeAgentStateTransitionService
{
    public function __construct(
        private UpgradeOperationStore $operations,
        private UpgradeGateRepository $gate,
        private ?UpgradeSharedFileStore $files = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function cancel(
        string $jobId,
        int $expectedGateRevision,
        int $controlExpectedRevision,
        string $controlRequestId,
        string $attempt,
        string $runtimeId,
        string $bootId,
        int $now,
    ): array {
        $files = $this->files;
        if (!$files instanceof UpgradeSharedFileStore
            || $attempt !== UpgradeOperationAttempt::fromRequestId($controlRequestId)) {
            throw new RuntimeException('UPGRADE_CANCEL_CONTROL_INVALID');
        }
        $control = $files->readJobControl($jobId, $controlExpectedRevision, $controlRequestId);
        $intent = $control === null ? [] : get_object_vars($control);
        if (array_keys($intent) !== [
            'schema_version', 'job_id', 'action', 'requested_at',
            'expected_revision', 'request_id',
        ] || ($intent['schema_version'] ?? null) !== 1 || ($intent['job_id'] ?? null) !== $jobId
            || ($intent['action'] ?? null) !== 'cancel'
            || ($intent['expected_revision'] ?? null) !== $controlExpectedRevision
            || ($intent['request_id'] ?? null) !== $controlRequestId) {
            throw new RuntimeException('UPGRADE_CANCEL_CONTROL_INVALID');
        }
        $status = $files->readJobStatus($jobId);
        $projection = $status === null ? [] : get_object_vars($status);
        if (($projection['revision'] ?? null) !== $controlExpectedRevision
            || !in_array($projection['state'] ?? null, [
                UpgradeState::Preparing->value,
                UpgradeState::ReadyToDrain->value,
                UpgradeState::Draining->value,
                UpgradeState::Paused->value,
            ], true)) {
            throw new RuntimeException('UPGRADE_CANCEL_CONTROL_INVALID');
        }
        $action = UpgradeOperationAttempt::action('cancel', $attempt);
        $checksum = $this->cancelChecksum(
            $expectedGateRevision,
            $controlExpectedRevision,
            $controlRequestId,
            $attempt,
        );
        $operationId = $this->operations->operationId($jobId, $action, $checksum);
        $existing = $this->operations->get($operationId);
        if (is_array($existing)) {
            $this->operations->assertMatches($existing, $jobId, $action, $checksum);
            if ($existing['state'] !== 'running') {
                return $existing;
            }
        }
        $operation = $this->operations->claim(
            $jobId,
            $action,
            $checksum,
            $runtimeId,
            $bootId,
            $now,
        );
        if ($operation['state'] !== 'running') {
            return $operation;
        }
        try {
            $current = $this->gate->snapshot();
            if ($current->jobId === $jobId && $current->state === UpgradeState::Cancelled
                && $current->revision === $expectedGateRevision + 1) {
                return $this->operations->complete(
                    $operationId,
                    $bootId,
                    $this->projection($current),
                    $now,
                );
            }
            if (!in_array($current->state, [
                UpgradeState::Preparing,
                UpgradeState::ReadyToDrain,
                UpgradeState::Draining,
                UpgradeState::Paused,
            ], true)) {
                throw new RuntimeException('UPGRADE_CANCEL_STATE_FORBIDDEN');
            }
            $next = $this->gate->compareAndSet(
                $expectedGateRevision,
                $current->state,
                UpgradeState::Cancelled,
                $jobId,
            );

            return $this->operations->complete(
                $operationId,
                $bootId,
                $this->projection($next),
                $now,
            );
        } catch (Throwable) {
            return $this->operations->fail(
                $operationId,
                $bootId,
                'UPGRADE_CANCEL_FAILED',
                $now,
            );
        }
    }

    public function cancelOperationId(
        string $jobId,
        int $expectedGateRevision,
        int $controlExpectedRevision,
        string $controlRequestId,
        string $attempt,
    ): string {
        $action = UpgradeOperationAttempt::action('cancel', $attempt);

        return $this->operations->operationId(
            $jobId,
            $action,
            $this->cancelChecksum(
                $expectedGateRevision,
                $controlExpectedRevision,
                $controlRequestId,
                $attempt,
            ),
        );
    }

    /** @return array<string,mixed> */
    public function resume(
        string $jobId,
        int $expectedGateRevision,
        UpgradeState $phase,
        int $controlExpectedRevision,
        string $controlRequestId,
        string $attempt,
        string $runtimeId,
        string $bootId,
        int $now,
    ): array {
        $files = $this->files;
        if (!$files instanceof UpgradeSharedFileStore
            || $attempt !== UpgradeOperationAttempt::fromRequestId($controlRequestId)
            || !$this->resumePhaseAllowed($phase)) {
            throw new RuntimeException('UPGRADE_RESUME_CONTROL_INVALID');
        }
        $control = $files->readJobControl($jobId, $controlExpectedRevision, $controlRequestId);
        $intent = $control === null ? [] : get_object_vars($control);
        if (array_keys($intent) !== [
            'schema_version', 'job_id', 'action', 'requested_at',
            'expected_revision', 'request_id',
        ] || ($intent['schema_version'] ?? null) !== 1 || ($intent['job_id'] ?? null) !== $jobId
            || ($intent['action'] ?? null) !== 'resume'
            || ($intent['expected_revision'] ?? null) !== $controlExpectedRevision
            || ($intent['request_id'] ?? null) !== $controlRequestId) {
            throw new RuntimeException('UPGRADE_RESUME_CONTROL_INVALID');
        }
        $status = $files->readJobStatus($jobId);
        $projection = $status === null ? [] : get_object_vars($status);
        if (($projection['revision'] ?? null) !== $controlExpectedRevision
            || ($projection['state'] ?? null) !== UpgradeState::FailedMaintenance->value
            || !$this->resumeStepMatches((string) ($projection['next_step'] ?? ''), $phase)) {
            throw new RuntimeException('UPGRADE_RESUME_CONTROL_INVALID');
        }
        $action = UpgradeOperationAttempt::action('resume_' . $phase->value, $attempt);
        $checksum = 'sha256:' . hash('sha256', json_encode([
            $expectedGateRevision,
            $phase->value,
            $controlExpectedRevision,
            $controlRequestId,
            $attempt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $operationId = $this->operations->operationId($jobId, $action, $checksum);
        $existing = $this->operations->get($operationId);
        if (is_array($existing)) {
            $this->operations->assertMatches($existing, $jobId, $action, $checksum);
            if ($existing['state'] !== 'running') {
                return $existing;
            }
        }
        $operation = $this->operations->claim(
            $jobId,
            $action,
            $checksum,
            $runtimeId,
            $bootId,
            $now,
        );
        if ($operation['state'] !== 'running') {
            return $operation;
        }
        try {
            $current = $this->gate->snapshot();
            if ($current->jobId === $jobId && $current->state === $phase
                && $current->revision === $expectedGateRevision + 1) {
                return $this->operations->complete(
                    $operationId,
                    $bootId,
                    $this->projection($current),
                    $now,
                );
            }
            if (!$this->gate instanceof UpgradeRecoveryGateRepository) {
                throw new RuntimeException('UPGRADE_RESUME_GATE_UNAVAILABLE');
            }
            $next = $this->gate->resumeFromFailedMaintenance(
                $expectedGateRevision,
                $phase,
                $jobId,
            );

            return $this->operations->complete(
                $operationId,
                $bootId,
                $this->projection($next),
                $now,
            );
        } catch (Throwable) {
            return $this->operations->fail(
                $operationId,
                $bootId,
                'UPGRADE_RESUME_FAILED',
                $now,
            );
        }
    }

    public function resumeOperationId(
        string $jobId,
        int $expectedGateRevision,
        UpgradeState $phase,
        int $controlExpectedRevision,
        string $controlRequestId,
        string $attempt,
    ): string {
        $action = UpgradeOperationAttempt::action('resume_' . $phase->value, $attempt);
        $checksum = 'sha256:' . hash('sha256', json_encode([
            $expectedGateRevision,
            $phase->value,
            $controlExpectedRevision,
            $controlRequestId,
            UpgradeOperationAttempt::normalize($attempt),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $this->operations->operationId($jobId, $action, $checksum);
    }

    /** @return array<string,mixed> */
    public function transition(
        string $jobId,
        int $expectedRevision,
        UpgradeState $expectedState,
        UpgradeState $nextState,
        bool $platformSyncPending,
        string $runtimeId,
        string $bootId,
        int $now,
        string $attempt = '',
    ): array {
        if (!$this->allowed($expectedState, $nextState, $platformSyncPending)) {
            throw new RuntimeException('UPGRADE_STATE_TRANSITION_FORBIDDEN');
        }
        $action = $this->action($expectedState, $nextState, $attempt);
        $checksum = $this->checksum($expectedRevision, $expectedState, $nextState, $platformSyncPending, $attempt);
        $operationId = $this->operations->operationId(
            $jobId,
            $action,
            $checksum,
        );
        $existing = $this->operations->get($operationId);
        if (is_array($existing)) {
            $this->operations->assertMatches(
                $existing,
                $jobId,
                $action,
                $checksum,
            );
            if ($existing['state'] !== 'running') {
                return $existing;
            }
        }
        $operation = $this->operations->claim(
            $jobId,
            $action,
            $checksum,
            $runtimeId,
            $bootId,
            $now,
        );
        if ($operation['state'] !== 'running') {
            return $operation;
        }
        try {
            $current = $this->gate->snapshot();
            if ($current->jobId === ($nextState === UpgradeState::Normal ? null : $jobId)
                && $current->state === $nextState && $current->revision === $expectedRevision + 1) {
                return $this->operations->complete(
                    $operationId,
                    $bootId,
                    $this->projection($current),
                    $now,
                );
            }
            if ($nextState === UpgradeState::Normal) {
                $next = $this->gate->returnToNormal(
                    $expectedRevision,
                    $expectedState,
                    $jobId,
                    $platformSyncPending,
                );
            } elseif ($expectedState === UpgradeState::Paused && $nextState === UpgradeState::BackingUp) {
                if (!$this->gate instanceof UpgradeDrainGateRepository) {
                    throw new RuntimeException('UPGRADE_DRAIN_GATE_UNAVAILABLE');
                }
                $next = $this->gate->enterBackingUpAfterDrain($expectedRevision, $jobId);
            } else {
                $next = $this->gate->compareAndSet($expectedRevision, $expectedState, $nextState, $jobId);
            }

            return $this->operations->complete(
                $operationId,
                $bootId,
                $this->projection($next),
                $now,
            );
        } catch (Throwable) {
            return $this->operations->fail(
                $operationId,
                $bootId,
                'UPGRADE_STATE_TRANSITION_FAILED',
                $now,
            );
        }
    }

    public function operationId(
        string $jobId,
        int $expectedRevision,
        UpgradeState $expectedState,
        UpgradeState $nextState,
        bool $platformSyncPending,
        string $attempt = '',
    ): string {
        return $this->operations->operationId(
            $jobId,
            $this->action($expectedState, $nextState, $attempt),
            $this->checksum($expectedRevision, $expectedState, $nextState, $platformSyncPending, $attempt),
        );
    }

    private function allowed(UpgradeState $from, UpgradeState $to, bool $platformSyncPending): bool
    {
        if ($to === UpgradeState::Normal) {
            return in_array($from, [
                UpgradeState::Completed,
                UpgradeState::Cancelled,
                UpgradeState::FailedPreApply,
            ], true);
        }
        if ($platformSyncPending) {
            return false;
        }

        return in_array([$from, $to], [
            [UpgradeState::Preparing, UpgradeState::ReadyToDrain],
            [UpgradeState::Paused, UpgradeState::BackingUp],
            [UpgradeState::BackingUp, UpgradeState::Applying],
            [UpgradeState::Applying, UpgradeState::AwaitingDeployment],
            [UpgradeState::AwaitingDeployment, UpgradeState::Verifying],
            [UpgradeState::Verifying, UpgradeState::Reconciling],
            [UpgradeState::Reconciling, UpgradeState::Completed],
            [UpgradeState::Preparing, UpgradeState::FailedPreApply],
            [UpgradeState::ReadyToDrain, UpgradeState::FailedPreApply],
            [UpgradeState::Draining, UpgradeState::FailedPreApply],
            [UpgradeState::Paused, UpgradeState::FailedPreApply],
            [UpgradeState::BackingUp, UpgradeState::FailedMaintenance],
            [UpgradeState::Applying, UpgradeState::FailedMaintenance],
            [UpgradeState::AwaitingDeployment, UpgradeState::FailedMaintenance],
            [UpgradeState::Verifying, UpgradeState::FailedMaintenance],
            [UpgradeState::Reconciling, UpgradeState::FailedMaintenance],
        ], true);
    }

    private function resumePhaseAllowed(UpgradeState $phase): bool
    {
        return in_array($phase, [
            UpgradeState::BackingUp,
            UpgradeState::Applying,
            UpgradeState::AwaitingDeployment,
            UpgradeState::Verifying,
            UpgradeState::Reconciling,
        ], true);
    }

    private function cancelChecksum(
        int $expectedGateRevision,
        int $controlExpectedRevision,
        string $controlRequestId,
        string $attempt,
    ): string {
        return 'sha256:' . hash('sha256', json_encode([
            $expectedGateRevision,
            $controlExpectedRevision,
            $controlRequestId,
            UpgradeOperationAttempt::normalize($attempt),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function resumeStepMatches(string $step, UpgradeState $phase): bool
    {
        return match ($step) {
            'backup' => $phase === UpgradeState::BackingUp,
            'apply', 'migrate' => $phase === UpgradeState::Applying,
            'awaiting_deployment' => $phase === UpgradeState::AwaitingDeployment,
            'verify' => $phase === UpgradeState::Verifying,
            'reconcile' => $phase === UpgradeState::Reconciling,
            default => false,
        };
    }

    private function action(UpgradeState $from, UpgradeState $to, string $attempt = ''): string
    {
        return UpgradeOperationAttempt::action('state_' . $from->value . '_to_' . $to->value, $attempt);
    }

    private function checksum(
        int $expectedRevision,
        UpgradeState $expectedState,
        UpgradeState $nextState,
        bool $platformSyncPending,
        string $attempt = '',
    ): string {
        return 'sha256:' . hash('sha256', json_encode([
            $expectedRevision,
            $expectedState->value,
            $nextState->value,
            $platformSyncPending,
            UpgradeOperationAttempt::normalize($attempt),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function projection(UpgradeGateSnapshot $snapshot): array
    {
        return [
            'state' => $snapshot->state->value,
            'revision' => $snapshot->revision,
            'job_id' => $snapshot->jobId,
            'platform_sync_pending' => $snapshot->platformSyncPending,
            'runtime_identity' => $snapshot->runtimeIdentity()->toArray(),
        ];
    }
}
