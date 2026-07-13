<?php

declare(strict_types=1);

namespace app\service\upgrade;

final readonly class UpgradeRuntimeRegistrationCoordinator
{
    public function __construct(
        private UpgradeRuntimeRegistry $runtimes,
        private UpgradeGateRepository $gate,
    ) {
    }

    /** @param list<string> $queues */
    public function register(
        UpgradeRuntimeInstance $instance,
        array $queues,
        bool $cronEnabled,
        string $slotId,
    ): UpgradeRuntimeRegistration {
        $snapshot = $this->gate->snapshot();
        $record = $this->runtimes->register($instance, $queues, $cronEnabled, $snapshot, $slotId);

        $acknowledged = null;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $acknowledged = $this->gate->acknowledgeRuntimeRegistration($snapshot->revision, $record);
                break;
            } catch (UpgradeStateConflict) {
                $snapshot = $this->gate->snapshot();
            }
        }
        if (!$acknowledged instanceof UpgradeGateSnapshot) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_REGISTRATION_RACE');
        }

        $cleanReplacement = $acknowledged->uncertain
            && $instance->isCleanReplacementFor(
                $acknowledged,
                (int) ($record['boot_registration_revision'] ?? -1),
                (int) ($record['activity_generation'] ?? -1),
                (string) ($record['redis_incarnation'] ?? ''),
            );
        $identityFenced = (bool) ($record['identity_fenced'] ?? true)
            || ($acknowledged->uncertain && !$cleanReplacement);
        $record = $this->runtimes->heartbeat(
            $instance,
            $queues,
            $cronEnabled,
            $acknowledged,
            $identityFenced,
            null,
        );

        return new UpgradeRuntimeRegistration(
            $record,
            $acknowledged,
            $acknowledged->state === UpgradeState::Normal
                && $instance->matchesGateSnapshot($acknowledged)
                && (!$identityFenced || $acknowledged->uncertain),
        );
    }
}
