<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use Throwable;

final class FileUpgradeCheckpointRepository
{
    /** @var Closure():UpgradeRuntimeIdentity */
    private readonly Closure $identityProvider;

    /** @var Closure():int */
    private readonly Closure $clock;

    public function __construct(
        private readonly UpgradeSharedFileStore $files,
        ?Closure $identityProvider = null,
        ?Closure $clock = null,
    ) {
        if ($identityProvider === null) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_IDENTITY_UNAVAILABLE');
        }
        $this->identityProvider = $identityProvider;
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function readActive(): ?UpgradeGateSnapshot
    {
        try {
            $document = $this->files->readJson('upgrade_gate');

            return $document === null ? null : UpgradeGateSnapshot::fromDocument($document);
        } catch (Throwable) {
            throw new UpgradeStateConflict('UPGRADE_CHECKPOINT_INVALID');
        }
    }

    public function write(UpgradeGateSnapshot $snapshot): void
    {
        try {
            $this->files->writeJson('upgrade_gate', $snapshot->toDocument());
        } catch (Throwable) {
            throw new UpgradeStateConflict('UPGRADE_CHECKPOINT_WRITE_FAILED');
        }
    }

    public function initialize(string $redisIncarnation): UpgradeGateSnapshot
    {
        $provider = $this->identityProvider;
        $identity = $provider();
        if (!$identity instanceof UpgradeRuntimeIdentity) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_IDENTITY_UNAVAILABLE');
        }
        $clock = $this->clock;
        $now = $clock();

        return new UpgradeGateSnapshot(
            UpgradeState::Normal,
            0,
            null,
            $identity->version,
            $identity->deploymentId,
            $identity->storageLayoutVersion,
            $identity->storageLayoutGeneration,
            1,
            1,
            $redisIncarnation,
            false,
            [],
            false,
            null,
            $now,
        );
    }

    public function withLock(Closure $callback): mixed
    {
        try {
            return $this->files->withUpgradeGateLock($callback);
        } catch (Throwable $exception) {
            if ($exception instanceof UpgradeStateConflict) {
                throw $exception;
            }
            throw new UpgradeStateConflict('UPGRADE_GATE_BUSY');
        }
    }
}
