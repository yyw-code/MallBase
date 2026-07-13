<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Throwable;

/**
 * 在任何工作进程登记前创建与 gate 同代的空 activity ledger。
 */
final readonly class UpgradeActivityBootstrapper
{
    public function __construct(
        private UpgradeActivityLedgerInitializer $initializer,
        private UpgradeGateRepository $gate,
    ) {
    }

    public function initialize(): bool
    {
        try {
            $this->initializer->initializeLedger();

            return true;
        } catch (Throwable $exception) {
            $snapshot = $this->persistUncertainty();
            if (!$snapshot->uncertain) {
                throw new UpgradeStateConflict('UPGRADE_ACTIVITY_BOOTSTRAP_UNSAFE');
            }

            return false;
        }
    }

    private function persistUncertainty(): UpgradeGateSnapshot
    {
        $snapshot = $this->gate->snapshot();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($snapshot->uncertain) {
                return $snapshot;
            }
            try {
                return $this->gate->recordActivityUncertainty($snapshot->revision, []);
            } catch (Throwable) {
                $snapshot = $this->gate->snapshot();
            }
        }

        throw new UpgradeStateConflict('UPGRADE_ACTIVITY_BOOTSTRAP_UNSAFE');
    }
}
