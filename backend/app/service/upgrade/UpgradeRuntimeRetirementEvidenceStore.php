<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeRuntimeRetirementEvidenceStore
{
    /** @param array<string,mixed> $runtimeRecord @param array<string,mixed>|null $redisRecord */
    public function observe(array $runtimeRecord, ?array $redisRecord, int $now, int $windowSeconds): bool;

    /** @param array<string,mixed> $runtimeRecord @param array<string,mixed>|null $redisRecord */
    public function prepareIfUnchanged(array $runtimeRecord, ?array $redisRecord, int $now, int $windowSeconds): bool;

    /**
     * @return list<array{
     *   owner_key:string,
     *   state:string,
     *   runtime_record:array<string,mixed>,
     *   retired_at:int
     * }>
     */
    public function pending(): array;

    public function advance(string $ownerKey, string $expectedState, string $nextState, int $now): void;

    public function reset(string $ownerKey): void;
}
