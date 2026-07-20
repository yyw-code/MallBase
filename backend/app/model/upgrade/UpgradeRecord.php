<?php

declare(strict_types=1);

namespace app\model\upgrade;

use JsonException;
use mall_base\base\BaseModel;
use RuntimeException;
use Throwable;

/**
 * 一次性升级请求与 Agent 任务记录的共享文件投影。
 */
final class UpgradeRecord extends BaseModel
{
    private const MAX_RECORD_BYTES = 64 * 1024;
    private const MAX_RECORDS = 10_000;
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
    private const VERSION_PATTERN = '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D';
    private const ACTIONS = ['upgrade', 'rollback'];
    private const STATUSES = [
        'queued',
        'running',
        'awaiting_php_restart',
        'succeeded',
        'failed',
    ];

    /**
     * 这是文件投影 Model，不使用 ORM；跳过父类数据库连接初始化。
     */
    public function __construct(array|object $data = [])
    {
        if ($data !== []) {
            throw new RuntimeException('UPGRADE_RECORD_ARGUMENT_INVALID');
        }
    }

    /**
     * 扫描 PHP 与 Agent 共享的任务目录，并按创建时间倒序返回严格校验后的记录。
     * 此方法只读取文件，不根据运行中的 Agent 推测状态。
     *
     * @return list<array<string, int|string>>
     */
    public function scan(string $root): array
    {
        $root = $this->existingRoot($root);
        $jobs = $root . DIRECTORY_SEPARATOR . 'jobs';
        if (!file_exists($jobs)) {
            return [];
        }
        if (is_link($jobs) || !is_dir($jobs)) {
            throw new RuntimeException('UPGRADE_RECORD_UNAVAILABLE');
        }

        $entries = scandir($jobs);
        if (!is_array($entries) || count($entries) > self::MAX_RECORDS + 2) {
            throw new RuntimeException('UPGRADE_RECORD_UNAVAILABLE');
        }

        $records = [];
        foreach ($entries as $jobId) {
            if (preg_match(self::UUID_PATTERN, $jobId) !== 1) {
                continue;
            }
            $jobDirectory = $jobs . DIRECTORY_SEPARATOR . $jobId;
            if (is_link($jobDirectory) || !is_dir($jobDirectory)) {
                throw new RuntimeException('UPGRADE_RECORD_INVALID');
            }
            $recordPath = $jobDirectory . DIRECTORY_SEPARATOR . 'record.json';
            if (!file_exists($recordPath)) {
                continue;
            }
            $records[] = $this->readRecord($recordPath, $jobId);
        }

        usort($records, static function (array $left, array $right): int {
            $created = $right['created_at'] <=> $left['created_at'];

            return $created !== 0 ? $created : strcmp((string) $right['job_id'], (string) $left['job_id']);
        });

        return $records;
    }

    /**
     * 在同一把创建锁下先写 queued 记录，再发布一次性请求。
     * 记录先落盘可保证 Agent 一旦消费请求，就一定能找到对应任务身份。
     *
     * @param array<string,int|string> $request
     * @param array<string,int|string> $record
     */
    public function createQueuedJob(string $root, array $request, array $record): void
    {
        $root = $this->existingRoot($root);
        $jobId = $request['job_id'] ?? '';
        if (($request['schema_version'] ?? null) !== 1 || !is_string($jobId)
            || preg_match(self::UUID_PATTERN, $jobId) !== 1
            || ($record['schema_version'] ?? null) !== 1 || ($record['job_id'] ?? null) !== $jobId
            || ($record['status'] ?? null) !== 'queued' || ($record['action'] ?? null) !== ($request['action'] ?? null)
            || ($record['target_version'] ?? null) !== ($request['target_version'] ?? null)
            || ($record['created_at'] ?? null) !== ($request['created_at'] ?? null)) {
            throw new RuntimeException('UPGRADE_ENTRY_ARGUMENT_INVALID');
        }

        $run = $root . DIRECTORY_SEPARATOR . 'run';
        $requests = $run . DIRECTORY_SEPARATOR . 'requests';
        $jobs = $root . DIRECTORY_SEPARATOR . 'jobs';
        $jobDirectory = $jobs . DIRECTORY_SEPARATOR . $jobId;
        $this->ensureDirectory($run, false);
        $this->ensureDirectory($requests, false);
        $this->ensureDirectory($jobs, false, null);
        $lockPath = $run . DIRECTORY_SEPARATOR . 'job-create.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }
        $lock = @fopen($lockPath, 'c+');
        if (!is_resource($lock) || !@chmod($lockPath, 0660) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }

        try {
            foreach ($this->scan($root) as $existing) {
                if (in_array($existing['status'], ['queued', 'running'], true)) {
                    throw new RuntimeException('UPGRADE_ENTRY_CONFLICT');
                }
            }
            if (file_exists($jobDirectory) || is_link($jobDirectory)) {
                throw new RuntimeException('UPGRADE_ENTRY_CONFLICT');
            }
            if (!@mkdir($jobDirectory, 02770) || !@chmod($jobDirectory, 02770)) {
                @rmdir($jobDirectory);
                throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
            }

            $recordPath = $jobDirectory . DIRECTORY_SEPARATOR . 'record.json';
            $requestPath = $requests . DIRECTORY_SEPARATOR . $jobId . '.json';
            try {
                $this->writeExclusiveJson($recordPath, $record, $jobDirectory, '.record-');
                $this->writeExclusiveJson($requestPath, $request, $run, '.request-');
            } catch (Throwable $exception) {
                @unlink($requestPath);
                @unlink($recordPath);
                @rmdir($jobDirectory);
                throw $exception;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,int|string> $document */
    private function writeExclusiveJson(string $target, array $document, string $temporaryDirectory, string $prefix): void
    {
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('UPGRADE_ENTRY_CONFLICT');
        }
        try {
            $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }
        $temporary = $temporaryDirectory . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }
        $writeError = null;
        try {
            $written = 0;
            $length = strlen($bytes);
            while ($written < $length) {
                $count = fwrite($handle, substr($bytes, $written));
                if ($count === false || $count === 0) {
                    throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
                }
                $written += $count;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
            }
        } catch (Throwable $exception) {
            $writeError = $exception;
        } finally {
            fclose($handle);
        }
        if ($writeError instanceof Throwable) {
            @unlink($temporary);
            throw $writeError;
        }
        if (!@chmod($temporary, 0660) || !@link($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException(file_exists($target) ? 'UPGRADE_ENTRY_CONFLICT' : 'UPGRADE_ENTRY_UNAVAILABLE');
        }
        @unlink($temporary);
    }

    /**
     * @return array<string, int|string>
     */
    private function readRecord(string $path, string $jobId): array
    {
        $stat = lstat($path);
        if (!is_array($stat) || is_link($path) || !is_file($path)
            || ($stat['size'] ?? 0) < 2 || ($stat['size'] ?? 0) > self::MAX_RECORD_BYTES) {
            throw new RuntimeException('UPGRADE_RECORD_INVALID');
        }
        $bytes = file_get_contents($path, false, null, 0, self::MAX_RECORD_BYTES + 1);
        if (!is_string($bytes) || strlen($bytes) > self::MAX_RECORD_BYTES) {
            throw new RuntimeException('UPGRADE_RECORD_INVALID');
        }
        try {
            $record = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('UPGRADE_RECORD_INVALID');
        }
        if (!is_array($record) || ($record['schema_version'] ?? null) !== 1
            || ($record['job_id'] ?? null) !== $jobId
            || !in_array($record['action'] ?? null, self::ACTIONS, true)
            || !in_array($record['status'] ?? null, self::STATUSES, true)) {
            throw new RuntimeException('UPGRADE_RECORD_INVALID');
        }

        $sourceVersion = $this->version($record['source_version'] ?? '');
        $targetVersion = $this->version($record['target_version'] ?? '');
        $error = $record['error'] ?? '';
        if (!is_string($error) || strlen($error) > 1000) {
            throw new RuntimeException('UPGRADE_RECORD_INVALID');
        }

        return [
            'job_id' => $jobId,
            'action' => (string) $record['action'],
            'source_version' => $sourceVersion,
            'target_version' => $targetVersion,
            'status' => (string) $record['status'],
            'backup_path' => $this->artifactPath($record['backup_path'] ?? '', 'backups'),
            'package_path' => $this->artifactPath($record['package_path'] ?? '', 'packages'),
            'created_at' => $this->timestamp($record['created_at'] ?? null),
            'started_at' => $this->timestamp($record['started_at'] ?? null, true),
            'finished_at' => $this->timestamp($record['finished_at'] ?? null, true),
            'error' => $error,
        ];
    }

    private function version(mixed $value): string
    {
        if (!is_string($value) || ($value !== '' && preg_match(self::VERSION_PATTERN, $value) !== 1)) {
            throw new RuntimeException('UPGRADE_RECORD_INVALID');
        }

        return $value;
    }

    private function artifactPath(mixed $value, string $prefix): string
    {
        if ($value === '') {
            return '';
        }
        if (!is_string($value) || str_contains($value, '\\') || str_starts_with($value, '/')) {
            throw new RuntimeException('UPGRADE_RECORD_INVALID');
        }
        $value = str_starts_with($value, 'upgrade/') ? substr($value, 8) : $value;
        $segments = explode('/', $value);
        if (($segments[0] ?? '') !== $prefix || count($segments) < 2) {
            throw new RuntimeException('UPGRADE_RECORD_INVALID');
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/^[0-9A-Za-z._-]+$/D', $segment) !== 1) {
                throw new RuntimeException('UPGRADE_RECORD_INVALID');
            }
        }

        return 'upgrade/' . implode('/', $segments);
    }

    private function timestamp(mixed $value, bool $nullable = false): int
    {
        if ($nullable && ($value === null || $value === 0)) {
            return 0;
        }
        if (!is_int($value) || $value < 0 || $value > 4_102_444_800) {
            throw new RuntimeException('UPGRADE_RECORD_INVALID');
        }

        return $value;
    }

    private function existingRoot(string $root): string
    {
        if ($root === '' || !str_starts_with($root, DIRECTORY_SEPARATOR) || is_link($root)) {
            throw new RuntimeException('UPGRADE_ROOT_UNAVAILABLE');
        }
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('UPGRADE_ROOT_UNAVAILABLE');
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function ensureDirectory(string $path, bool $allowCreate = true, ?int $requiredMode = 02770): void
    {
        $created = false;
        if (file_exists($path) || is_link($path)) {
            if (is_link($path) || !is_dir($path)) {
                throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
            }
        } elseif (!$allowCreate) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        } elseif (@mkdir($path, 02770)) {
            $created = true;
        } elseif (is_link($path) || !is_dir($path)) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }
        if ($created && $requiredMode !== null && !@chmod($path, $requiredMode)) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        if (is_link($path) || !is_dir($path) || !is_int($mode)
            || ($requiredMode !== null && ($mode & 07777) !== $requiredMode)) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }
    }
}
