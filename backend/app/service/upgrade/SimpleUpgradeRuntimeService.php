<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;

final readonly class SimpleUpgradeRuntimeService
{
    public function __construct(
        private SimpleUpgradeGate $gate,
        private SimpleDatabaseSnapshotService $database,
        private SimpleSqlMigrationService $migrations,
    ) {
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function pause(string $jobId, array $body): array
    {
        $this->requireJob($jobId);
        $this->requireFields($body, ['action', 'source_version', 'target_version']);
        if (!$this->action($body['action']) || !$this->version($body['source_version'])
            || !$this->version($body['target_version'])
            || $body['source_version'] === $body['target_version']) {
            $this->invalid();
        }

        return [
            'state' => $this->gate->drain(),
            'action' => $body['action'],
            'source_version' => $body['source_version'],
            'target_version' => $body['target_version'],
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function backup(string $jobId, array $body): array
    {
        $this->requireJob($jobId);
        $this->requireFields($body, []);
        $this->requirePaused();

        return $this->database->backup($jobId);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function restore(string $jobId, array $body): array
    {
        $this->requireJob($jobId);
        $this->requireFields($body, ['source_job_id', 'database_path', 'database_sha256']);
        if (!is_string($body['source_job_id'] ?? null)
            || !is_string($body['database_path'] ?? null)
            || !is_string($body['database_sha256'] ?? null)) {
            $this->invalid();
        }

        $this->requireJob($body['source_job_id']);
        $this->requirePaused();

        $result = $this->database->restore(
            $body['source_job_id'],
            $body['database_path'],
            $body['database_sha256'],
        );
        $this->migrations->forgetJob($body['source_job_id']);

        return $result;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function migrate(string $jobId, array $body): array
    {
        $this->requireJob($jobId);
        $this->requireFields($body, ['migration_id', 'version', 'path', 'sha256']);
        foreach (['migration_id', 'version', 'path', 'sha256'] as $field) {
            if (!is_string($body[$field] ?? null)) {
                $this->invalid();
            }
        }
        $this->requirePaused();

        return $this->migrations->execute(
            $jobId,
            $body['migration_id'],
            $body['version'],
            $body['path'],
            $body['sha256'],
        );
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function awaitingRestart(string $jobId, array $body): array
    {
        $this->requireJob($jobId);
        $this->requireFields($body, ['action', 'target_version']);
        if (!$this->action($body['action']) || !$this->version($body['target_version'])) {
            $this->invalid();
        }
        $this->requirePaused();

        return [
            'state' => $this->gate->markAwaitingPhpRestart(),
            'action' => $body['action'],
            'target_version' => $body['target_version'],
        ];
    }

    /** @param array<string,mixed> $body @return array{state:string} */
    public function resume(string $jobId, array $body): array
    {
        $this->requireJob($jobId);
        $this->requireFields($body, []);

        return ['state' => $this->gate->restoreNormal()];
    }

    private function requirePaused(): void
    {
        if ($this->gate->state() !== 'paused') {
            throw new RuntimeException('SIMPLE_UPGRADE_GATE_NOT_PAUSED');
        }
    }

    private function requireJob(string $jobId): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1) {
            $this->invalid();
        }
    }

    /** @param array<string,mixed> $body @param list<string> $fields */
    private function requireFields(array $body, array $fields): void
    {
        if (count($body) !== count($fields) || array_diff(array_keys($body), $fields) !== []
            || array_diff($fields, array_keys($body)) !== []) {
            $this->invalid();
        }
    }

    private function action(mixed $value): bool
    {
        return is_string($value) && in_array($value, ['upgrade', 'rollback'], true);
    }

    private function version(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $value) === 1;
    }

    private function invalid(): never
    {
        throw new RuntimeException('SIMPLE_UPGRADE_INPUT_INVALID');
    }
}
