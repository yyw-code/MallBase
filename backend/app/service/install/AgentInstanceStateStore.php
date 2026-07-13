<?php

declare(strict_types=1);

namespace app\service\install;

interface AgentInstanceStateStore
{
    /** @return array<string, mixed>|null */
    public function load(): ?array;

    /** @return array<string, mixed> */
    public function initializeFromLegacy(InstallLockService $legacy, int $now): array;

    /** @return array{reservation_id:string,reservation_revision:int,instance:array<string,mixed>}|null */
    public function reserveReportWindow(string $componentType, int $now, int $reservationSeconds): ?array;

    public function recordReportResult(
        string $reservationId,
        int $reservationRevision,
        bool $success,
        int $now,
        int $nextReportAfterSeconds,
        string $errorCode = '',
    ): bool;

    /** @return array<string, mixed> */
    public function storeActivationResponse(
        string $generation,
        int $expectedRevision,
        string $instanceId,
        string $token,
        int $now,
    ): array;

    /** @return array<string, mixed> */
    public function confirmActivation(string $generation, int $expectedRevision, int $now): array;

    /** @return array<string, mixed> */
    public function markExpiredActivationRecoveryRequired(int $now): array;
}
