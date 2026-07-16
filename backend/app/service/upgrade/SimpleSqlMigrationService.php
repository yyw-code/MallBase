<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class SimpleSqlMigrationService
{
    /** @var Closure():PDO */
    private Closure $connection;

    private readonly string $upgradeRoot;

    public function __construct(string $upgradeRoot, callable $connection)
    {
        $upgradeRoot = rtrim($upgradeRoot, DIRECTORY_SEPARATOR);
        $resolved = realpath($upgradeRoot);
        if ($upgradeRoot === '' || !is_string($resolved)
            || !is_dir($resolved) || realpath($resolved . '/run') !== $resolved . '/run') {
            throw new RuntimeException('SIMPLE_MIGRATION_CONFIG_INVALID');
        }
        $this->upgradeRoot = $resolved;
        $this->connection = Closure::fromCallable($connection);
    }

    /** @return array{state:string,migration_id:string,statement_count:int,next_statement:int} */
    public function execute(
        string $jobId,
        string $migrationId,
        string $version,
        string $path,
        string $sha256,
    ): array {
        $this->validateInput($jobId, $migrationId, $version, $path, $sha256);
        $sql = $this->readSql($jobId, $path);
        if (!hash_equals($sha256, hash('sha256', $sql))) {
            throw new RuntimeException('SIMPLE_MIGRATION_CHECKSUM_MISMATCH');
        }
        $statements = $this->statements($sql);
        $lock = $this->openLock();
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('SIMPLE_MIGRATION_LOCK_FAILED');
            }
            $document = $this->readCheckpoint();
            $index = $this->entryIndex($document['migrations'], $jobId, $migrationId);
            if ($index === null) {
                $document['migrations'][] = [
                    'job_id' => $jobId,
                    'migration_id' => $migrationId,
                    'version' => $version,
                    'sha256' => $sha256,
                    'statement_count' => count($statements),
                    'next_statement' => 0,
                    'state' => 'running',
                ];
                $index = count($document['migrations']) - 1;
                $this->writeCheckpoint($document);
            }
            $entry = $document['migrations'][$index];
            if ($entry['version'] !== $version || $entry['sha256'] !== $sha256
                || $entry['statement_count'] !== count($statements)) {
                throw new RuntimeException('SIMPLE_MIGRATION_CHECKPOINT_CONFLICT');
            }
            if ($entry['state'] === 'completed') {
                return $this->result($entry);
            }
            if ($entry['state'] !== 'running') {
                throw new RuntimeException('SIMPLE_MIGRATION_PREVIOUSLY_FAILED');
            }

            $connection = $this->connection;
            $pdo = $connection();
            try {
                for ($statement = $entry['next_statement']; $statement < count($statements); $statement++) {
                    if ($pdo->exec($statements[$statement]) === false) {
                        throw new RuntimeException('SIMPLE_MIGRATION_STATEMENT_FAILED');
                    }
                    $document['migrations'][$index]['next_statement'] = $statement + 1;
                    $this->writeCheckpoint($document);
                }
            } catch (Throwable $exception) {
                $document['migrations'][$index]['state'] = 'failed';
                $this->writeCheckpoint($document);
                if ($exception instanceof RuntimeException
                    && str_starts_with($exception->getMessage(), 'SIMPLE_MIGRATION_')) {
                    throw $exception;
                }
                throw new RuntimeException('SIMPLE_MIGRATION_STATEMENT_FAILED', 0, $exception);
            }
            $document['migrations'][$index]['state'] = 'completed';
            $this->writeCheckpoint($document);

            return $this->result($document['migrations'][$index]);
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function forgetJob(string $jobId): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1) {
            throw new RuntimeException('SIMPLE_MIGRATION_INPUT_INVALID');
        }
        $lock = $this->openLock();
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('SIMPLE_MIGRATION_LOCK_FAILED');
            }
            $document = $this->readCheckpoint();
            $remaining = array_values(array_filter(
                $document['migrations'],
                static fn(array $entry): bool => $entry['job_id'] !== $jobId,
            ));
            if (count($remaining) !== count($document['migrations'])) {
                $document['migrations'] = $remaining;
                $this->writeCheckpoint($document);
            }
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        if (strlen($sql) < 1 || strlen($sql) > 16 * 1024 * 1024 || !mb_check_encoding($sql, 'UTF-8')) {
            throw new RuntimeException('SIMPLE_MIGRATION_INPUT_INVALID');
        }
        $parts = preg_split('/^-- mallbase:statement-breakpoint\r?$/m', $sql);
        if (!is_array($parts) || $parts === [] || count($parts) > 10000) {
            throw new RuntimeException('SIMPLE_MIGRATION_INPUT_INVALID');
        }
        $statements = [];
        foreach ($parts as $part) {
            $statement = trim($part);
            if ($statement === '') {
                throw new RuntimeException('SIMPLE_MIGRATION_INPUT_INVALID');
            }
            if (preg_match('/\b(?:DELIMITER|SOURCE|LOAD\s+DATA\s+LOCAL|START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|SET\s+AUTOCOMMIT)\b/i', $statement) === 1) {
                throw new RuntimeException('SIMPLE_MIGRATION_STATEMENT_FORBIDDEN');
            }
            $withoutTrailing = preg_replace('/;\s*$/D', '', $statement, 1);
            if (!is_string($withoutTrailing) || str_contains($withoutTrailing, ';')) {
                throw new RuntimeException('SIMPLE_MIGRATION_MULTIPLE_STATEMENTS');
            }
            $statements[] = $statement;
        }

        return $statements;
    }

    private function readSql(string $jobId, string $path): string
    {
        $directory = $this->upgradeRoot . '/staging/' . $jobId . '/migrations';
        $file = $this->upgradeRoot . '/staging/' . $jobId . '/' . $path;
        if (realpath($directory) !== $directory || is_link($directory)) {
            throw new RuntimeException('SIMPLE_MIGRATION_STAGING_INVALID');
        }
        $named = @lstat($file);
        if (!is_array($named) || ($named['mode'] & 0170000) !== 0100000
            || ($named['nlink'] ?? 0) !== 1 || ($named['size'] ?? 0) < 1
            || ($named['size'] ?? 0) > 16 * 1024 * 1024) {
            throw new RuntimeException('SIMPLE_MIGRATION_STAGING_INVALID');
        }
        $handle = @fopen($file, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('SIMPLE_MIGRATION_STAGING_INVALID');
        }
        try {
            $opened = fstat($handle);
            $sql = stream_get_contents($handle, 16 * 1024 * 1024 + 1);
            $after = fstat($handle);
            if (!is_array($opened) || ($opened['dev'] ?? null) !== ($named['dev'] ?? null)
                || ($opened['ino'] ?? null) !== ($named['ino'] ?? null)
                || !is_string($sql) || strlen($sql) > 16 * 1024 * 1024 || !is_array($after)
                || ($after['size'] ?? null) !== ($opened['size'] ?? null)
                || ($after['mtime'] ?? null) !== ($opened['mtime'] ?? null)) {
                throw new RuntimeException('SIMPLE_MIGRATION_STAGING_INVALID');
            }

            return $sql;
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function openLock()
    {
        $path = $this->upgradeRoot . '/run/simple-migrations.lock';
        $named = @lstat($path);
        if (is_array($named) && (($named['mode'] & 0170000) !== 0100000 || ($named['nlink'] ?? 0) !== 1)) {
            throw new RuntimeException('SIMPLE_MIGRATION_LOCK_FAILED');
        }
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('SIMPLE_MIGRATION_LOCK_FAILED');
        }

        return $handle;
    }

    /** @return array{schema_version:int,migrations:list<array<string,mixed>>} */
    private function readCheckpoint(): array
    {
        $path = $this->upgradeRoot . '/run/simple-migrations.json';
        if (!file_exists($path) && !is_link($path)) {
            return ['schema_version' => 1, 'migrations' => []];
        }
        $named = @lstat($path);
        if (!is_array($named) || ($named['mode'] & 0170000) !== 0100000
            || ($named['nlink'] ?? 0) !== 1 || ($named['size'] ?? 0) < 2
            || ($named['size'] ?? 0) > 4 * 1024 * 1024) {
            throw new RuntimeException('SIMPLE_MIGRATION_CHECKPOINT_INVALID');
        }
        $raw = @file_get_contents($path);
        try {
            $document = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            throw new RuntimeException('SIMPLE_MIGRATION_CHECKPOINT_INVALID');
        }
        if (!is_array($document) || array_keys($document) !== ['schema_version', 'migrations']
            || $document['schema_version'] !== 1 || !is_array($document['migrations'])
            || !array_is_list($document['migrations']) || count($document['migrations']) > 10000) {
            throw new RuntimeException('SIMPLE_MIGRATION_CHECKPOINT_INVALID');
        }
        foreach ($document['migrations'] as $entry) {
            if (!$this->validEntry($entry)) {
                throw new RuntimeException('SIMPLE_MIGRATION_CHECKPOINT_INVALID');
            }
        }

        return $document;
    }

    /** @param array{schema_version:int,migrations:list<array<string,mixed>>} $document */
    private function writeCheckpoint(array $document): void
    {
        $path = $this->upgradeRoot . '/run/simple-migrations.json';
        $temporary = $this->upgradeRoot . '/run/.simple-migrations-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('SIMPLE_MIGRATION_CHECKPOINT_INVALID', 0, $exception);
        }
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('SIMPLE_MIGRATION_CHECKPOINT_WRITE_FAILED');
        }
        try {
            if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)
                || function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('SIMPLE_MIGRATION_CHECKPOINT_WRITE_FAILED');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($temporary);
            throw $exception;
        }
        fclose($handle);
        if (!chmod($temporary, 0660) || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('SIMPLE_MIGRATION_CHECKPOINT_WRITE_FAILED');
        }
    }

    private function validateInput(
        string $jobId,
        string $migrationId,
        string $version,
        string $path,
        string $sha256,
    ): void {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $migrationId) !== 1
            || preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version) !== 1
            || $path !== 'migrations/' . $migrationId . '.sql'
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            throw new RuntimeException('SIMPLE_MIGRATION_INPUT_INVALID');
        }
    }

    /** @param list<array<string,mixed>> $entries */
    private function entryIndex(array $entries, string $jobId, string $migrationId): ?int
    {
        foreach ($entries as $index => $entry) {
            if ($entry['job_id'] === $jobId && $entry['migration_id'] === $migrationId) {
                return $index;
            }
        }

        return null;
    }

    private function validEntry(mixed $entry): bool
    {
        return is_array($entry)
            && array_keys($entry) === [
                'job_id', 'migration_id', 'version', 'sha256',
                'statement_count', 'next_statement', 'state',
            ]
            && is_string($entry['job_id']) && is_string($entry['migration_id'])
            && is_string($entry['version']) && is_string($entry['sha256'])
            && is_int($entry['statement_count']) && $entry['statement_count'] >= 1
            && is_int($entry['next_statement']) && $entry['next_statement'] >= 0
            && $entry['next_statement'] <= $entry['statement_count']
            && in_array($entry['state'], ['running', 'completed', 'failed'], true);
    }

    /** @param array<string,mixed> $entry @return array{state:string,migration_id:string,statement_count:int,next_statement:int} */
    private function result(array $entry): array
    {
        return [
            'state' => $entry['state'],
            'migration_id' => $entry['migration_id'],
            'statement_count' => $entry['statement_count'],
            'next_statement' => $entry['next_statement'],
        ];
    }
}
