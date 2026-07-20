<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use RuntimeException;
use Throwable;

final class SimpleDatabaseSnapshotService
{
    /** @var Closure(array<int,string>,array<string,string>,mixed,mixed):int */
    private Closure $runner;

    private readonly string $upgradeRoot;

    /** @param array{host:string,port:int,user:string,password:string,database:string,charset:string} $database */
    public function __construct(
        string $upgradeRoot,
        private readonly string $dumpExecutable,
        private readonly string $restoreExecutable,
        private readonly array $database,
        ?callable $runner = null,
    ) {
        $upgradeRoot = rtrim($upgradeRoot, DIRECTORY_SEPARATOR);
        $resolved = realpath($upgradeRoot);
        if ($upgradeRoot === '' || !str_starts_with($upgradeRoot, DIRECTORY_SEPARATOR)
            || !is_string($resolved) || !is_dir($resolved)
            || !$this->absoluteExecutable($this->dumpExecutable)
            || !$this->absoluteExecutable($this->restoreExecutable)
            || $this->database['host'] === '' || $this->database['port'] < 1 || $this->database['port'] > 65535
            || $this->database['user'] === '' || $this->database['database'] === ''
            || preg_match('/^[A-Za-z0-9_]{1,32}$/D', $this->database['charset']) !== 1) {
            throw new RuntimeException('SIMPLE_DATABASE_CONFIG_INVALID');
        }
        $this->upgradeRoot = $resolved;
        $this->runner = $runner === null ? $this->defaultRunner(...) : Closure::fromCallable($runner);
    }

    /** @return array{database_path:string,database_sha256:string,size:int} */
    public function backup(string $jobId): array
    {
        $this->requireUuid($jobId);
        $directory = $this->artifactDirectory($jobId, true);
        $final = $directory . DIRECTORY_SEPARATOR . 'database.sql';
        if (file_exists($final) || is_link($final)) {
            return $this->artifactResult($jobId, $final);
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.database-' . bin2hex(random_bytes(8)) . '.tmp';
        $output = @fopen($temporary, 'xb');
        if (!is_resource($output)) {
            throw new RuntimeException('SIMPLE_DATABASE_BACKUP_STORAGE_UNAVAILABLE');
        }
        try {
            $runner = $this->runner;
            $status = $runner($this->dumpArgv(), $this->environment(), null, $output);
            if ($status !== 0 || !fflush($output) || function_exists('fsync') && !fsync($output)) {
                throw new RuntimeException('SIMPLE_DATABASE_BACKUP_FAILED');
            }
            $stat = fstat($output);
            if (!is_array($stat) || ($stat['size'] ?? 0) < 1) {
                throw new RuntimeException('SIMPLE_DATABASE_BACKUP_FAILED');
            }
            fclose($output);
            $output = null;
            if (!chmod($temporary, 0660) || !rename($temporary, $final)) {
                throw new RuntimeException('SIMPLE_DATABASE_BACKUP_STORAGE_UNAVAILABLE');
            }

            return $this->artifactResult($jobId, $final);
        } catch (Throwable $exception) {
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);
            throw $exception;
        }
    }

    /** @return array{state:string,database_path:string,database_sha256:string,size:int} */
    public function restore(string $sourceJobId, string $databasePath, string $databaseSha256): array
    {
        $this->requireUuid($sourceJobId);
        $expectedPath = 'upgrade/backups/' . $sourceJobId . '/database.sql';
        if ($databasePath !== $expectedPath || preg_match('/^[0-9a-f]{64}$/D', $databaseSha256) !== 1) {
            throw new RuntimeException('SIMPLE_DATABASE_RESTORE_INPUT_INVALID');
        }
        $final = $this->artifactDirectory($sourceJobId, false) . DIRECTORY_SEPARATOR . 'database.sql';
        [$input, $size, $actualHash] = $this->openArtifact($final);
        if (!hash_equals($databaseSha256, $actualHash)) {
            fclose($input);
            throw new RuntimeException('SIMPLE_DATABASE_RESTORE_CHECKSUM_MISMATCH');
        }
        $output = tmpfile();
        if (!is_resource($output)) {
            fclose($input);
            throw new RuntimeException('SIMPLE_DATABASE_RESTORE_FAILED');
        }
        try {
            $runner = $this->runner;
            if ($runner($this->restoreArgv(), $this->environment(), $input, $output) !== 0) {
                throw new RuntimeException('SIMPLE_DATABASE_RESTORE_FAILED');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        return [
            'state' => 'restored',
            'database_path' => $expectedPath,
            'database_sha256' => $actualHash,
            'size' => $size,
        ];
    }

    /** @return array{database_path:string,database_sha256:string,size:int} */
    private function artifactResult(string $jobId, string $path): array
    {
        [$handle, $size, $hash] = $this->openArtifact($path);
        fclose($handle);

        return [
            'database_path' => 'upgrade/backups/' . $jobId . '/database.sql',
            'database_sha256' => $hash,
            'size' => $size,
        ];
    }

    /** @return array{resource,int,string} */
    private function openArtifact(string $path): array
    {
        $named = @lstat($path);
        if (!is_array($named) || ($named['mode'] & 0170000) !== 0100000
            || ($named['nlink'] ?? 0) !== 1 || ($named['size'] ?? 0) < 1) {
            throw new RuntimeException('SIMPLE_DATABASE_ARTIFACT_INVALID');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('SIMPLE_DATABASE_ARTIFACT_INVALID');
        }
        $opened = fstat($handle);
        if (!is_array($opened) || ($opened['dev'] ?? null) !== ($named['dev'] ?? null)
            || ($opened['ino'] ?? null) !== ($named['ino'] ?? null)
            || ($opened['size'] ?? null) !== ($named['size'] ?? null)) {
            fclose($handle);
            throw new RuntimeException('SIMPLE_DATABASE_ARTIFACT_INVALID');
        }
        $hash = hash_init('sha256');
        hash_update_stream($hash, $handle);
        $actual = hash_final($hash);
        $after = fstat($handle);
        if (!is_array($after) || ($after['size'] ?? null) !== ($opened['size'] ?? null)
            || ($after['mtime'] ?? null) !== ($opened['mtime'] ?? null)
            || fseek($handle, 0) !== 0) {
            fclose($handle);
            throw new RuntimeException('SIMPLE_DATABASE_ARTIFACT_INVALID');
        }

        return [$handle, (int) $opened['size'], $actual];
    }

    private function artifactDirectory(string $jobId, bool $create): string
    {
        $backups = $this->upgradeRoot . DIRECTORY_SEPARATOR . 'backups';
        $directory = $backups . DIRECTORY_SEPARATOR . $jobId;
        if ($create) {
            foreach ([$backups, $directory] as $path) {
                if (!is_dir($path) && !mkdir($path, 0770)) {
                    throw new RuntimeException('SIMPLE_DATABASE_BACKUP_STORAGE_UNAVAILABLE');
                }
            }
        }
        if (realpath($directory) !== $directory || is_link($backups) || is_link($directory)) {
            throw new RuntimeException('SIMPLE_DATABASE_ARTIFACT_INVALID');
        }

        return $directory;
    }

    /** @return list<string> */
    private function dumpArgv(): array
    {
        return [
            $this->dumpExecutable,
            '--host=' . $this->database['host'],
            '--port=' . $this->database['port'],
            '--user=' . $this->database['user'],
            '--default-character-set=' . $this->database['charset'],
            '--single-transaction',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--add-drop-database',
            '--databases',
            $this->database['database'],
        ];
    }

    /** @return list<string> */
    private function restoreArgv(): array
    {
        return [
            $this->restoreExecutable,
            '--host=' . $this->database['host'],
            '--port=' . $this->database['port'],
            '--user=' . $this->database['user'],
            '--default-character-set=' . $this->database['charset'],
            $this->database['database'],
        ];
    }

    /** @return array{MYSQL_PWD:string} */
    private function environment(): array
    {
        return ['MYSQL_PWD' => $this->database['password']];
    }

    private function defaultRunner(array $argv, array $environment, mixed $stdin, mixed $stdout): int
    {
        $error = tmpfile();
        if (!is_resource($error)) {
            return 1;
        }
        $descriptors = [
            0 => is_resource($stdin) ? $stdin : ['pipe', 'r'],
            1 => $stdout,
            2 => $error,
        ];
        $pipes = [];
        $process = @proc_open($argv, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            fclose($error);

            return 1;
        }
        if (!is_resource($stdin) && isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $status = proc_close($process);
        fclose($error);

        return is_int($status) ? $status : 1;
    }

    private function requireUuid(string $value): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw new RuntimeException('SIMPLE_DATABASE_JOB_INVALID');
        }
    }

    private function absoluteExecutable(string $value): bool
    {
        return $value !== '' && str_starts_with($value, DIRECTORY_SEPARATOR) && !str_contains($value, "\0");
    }
}
