<?php

declare(strict_types=1);

namespace app\service\upgrade;

enum UpgradeState: string
{
    case Normal = 'normal';
    case Preparing = 'preparing';
    case ReadyToDrain = 'ready_to_drain';
    case Draining = 'draining';
    case Paused = 'paused';
    case BackingUp = 'backing_up';
    case Applying = 'applying';
    case AwaitingDeployment = 'awaiting_deployment';
    case Verifying = 'verifying';
    case Reconciling = 'reconciling';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case FailedPreApply = 'failed_pre_apply';
    case FailedMaintenance = 'failed_maintenance';

    public function blocksBusinessTraffic(): bool
    {
        return !in_array($this, [
            self::Normal,
            self::Preparing,
            self::ReadyToDrain,
            self::FailedPreApply,
            self::Cancelled,
        ], true);
    }

    public function pausesQueuePop(): bool
    {
        return in_array($this, [
            self::Paused,
            self::BackingUp,
            self::Applying,
            self::AwaitingDeployment,
            self::Verifying,
            self::Reconciling,
            self::Completed,
            self::FailedMaintenance,
        ], true);
    }

    public function hasIrreversibleSideEffects(): bool
    {
        return in_array($this, [
            self::BackingUp,
            self::Applying,
            self::AwaitingDeployment,
            self::Verifying,
            self::Reconciling,
            self::Completed,
            self::FailedMaintenance,
        ], true);
    }

    public function permitsGenericTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Normal => $next === self::Preparing,
            self::Preparing => in_array($next, [self::ReadyToDrain, self::Cancelled, self::FailedPreApply], true),
            self::ReadyToDrain => in_array($next, [self::Draining, self::Cancelled, self::FailedPreApply], true),
            self::Draining => in_array($next, [self::Paused, self::Cancelled, self::FailedPreApply], true),
            self::Paused => in_array($next, [self::Draining, self::Cancelled, self::FailedPreApply], true),
            self::BackingUp => in_array($next, [self::Applying, self::FailedMaintenance], true),
            self::Applying => in_array($next, [self::AwaitingDeployment, self::Verifying, self::FailedMaintenance], true),
            self::AwaitingDeployment => in_array($next, [self::Verifying, self::FailedMaintenance], true),
            self::Verifying => in_array($next, [self::Reconciling, self::FailedMaintenance], true),
            self::Reconciling => in_array($next, [self::Completed, self::FailedMaintenance], true),
            self::Completed, self::Cancelled, self::FailedPreApply, self::FailedMaintenance => false,
        };
    }
}
