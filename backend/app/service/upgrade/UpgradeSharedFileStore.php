<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * PHP 与宿主机 Agent 之间的最小共享文件边界。
 *
 * PHP 只读写实例配置；升级任务和备份由各自服务管理。
 */
final class UpgradeSharedFileStore
{
    /** @var array<string,array{path:string,owner:string,write:bool}> */
    private const DOCUMENTS = [
        'instance' => ['path' => 'config/instance.json', 'owner' => 'php', 'write' => true],
    ];

    private const INSTANCE_LOCK_PATH = 'run/instance-config.lock';

    private readonly string $root;

    /**
     * @param array<string,Closure> $operations 保留该参数以兼容已有容器构造；当前实现不注入文件系统操作。
     */
    public function __construct(
        string $root,
        private readonly int $agentUid,
        private readonly int $expectedGid,
        private readonly int $phpEuid,
        private readonly int $maxJsonBytes = 65536,
        private readonly int $lockTimeoutMilliseconds = 2000,
        array $operations = [],
    ) {
        unset($operations);
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ($root === '' || !str_starts_with($root, DIRECTORY_SEPARATOR)
            || str_contains($root, "\0") || $this->agentUid < 0 || $this->expectedGid < 0
            || $this->phpEuid < 0 || $this->maxJsonBytes < 1024
            || $this->lockTimeoutMilliseconds < 1 || !is_dir($root) || is_link($root)) {
            throw new RuntimeException('SHARED_FILE_CONFIG_INVALID');
        }
        $resolved = realpath($root);
        if (!is_string($resolved)) {
            throw new RuntimeException('SHARED_FILE_CONFIG_INVALID');
        }
        $this->root = $resolved;
    }

    public function readJson(string $logicalName): ?object
    {
        $definition = self::DOCUMENTS[$logicalName] ?? null;
        if (!is_array($definition)) {
            throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
        }
        $path = $this->path($definition['path']);
        $stat = @lstat($path);
        if ($stat === false) {
            return null;
        }
        $this->validateFile($stat, $definition['owner']);

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
        }
        try {
            $opened = @fstat($handle);
            if (!is_array($opened) || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) {
                throw new RuntimeException('SHARED_FILE_INVALID');
            }
            $raw = stream_get_contents($handle, $this->maxJsonBytes + 1);
            if (!is_string($raw) || $raw === '' || strlen($raw) > $this->maxJsonBytes
                || !mb_check_encoding($raw, 'UTF-8')) {
                throw new RuntimeException('SHARED_FILE_INVALID');
            }

            return $this->decodeObject($raw);
        } finally {
            fclose($handle);
        }
    }

    public function writeJson(string $logicalName, object $document): void
    {
        $definition = self::DOCUMENTS[$logicalName] ?? null;
        if (!is_array($definition) || $definition['write'] !== true) {
            throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
        }
        $directory = dirname($this->path($definition['path']));
        $this->validateSharedDirectory($directory);

        try {
            $raw = json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            ) . "\n";
        } catch (JsonException) {
            throw new RuntimeException('SHARED_FILE_INVALID');
        }
        if (strlen($raw) > $this->maxJsonBytes) {
            throw new RuntimeException('SHARED_FILE_INVALID');
        }

        $target = $this->path($definition['path']);
        $temporary = $directory . DIRECTORY_SEPARATOR . '.instance-' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
        }
        try {
            if (!@chmod($temporary, 0660) || !@chgrp($temporary, $this->expectedGid)
                || fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)
                || function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($temporary);
            throw $exception;
        }
        fclose($handle);

        $stat = @lstat($temporary);
        if (!is_array($stat)) {
            @unlink($temporary);
            throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
        }
        $this->validateFile($stat, 'php');
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
        }
    }

    public function withInstanceLock(Closure $callback): mixed
    {
        $path = $this->path(self::INSTANCE_LOCK_PATH);
        $this->validateSharedDirectory(dirname($path));
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle) || !@chmod($path, 0660) || !@chgrp($path, $this->expectedGid)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
        }

        $deadline = hrtime(true) + $this->lockTimeoutMilliseconds * 1_000_000;
        $locked = false;
        try {
            do {
                $locked = @flock($handle, LOCK_EX | LOCK_NB);
                if ($locked) {
                    break;
                }
                usleep(5_000);
            } while (hrtime(true) < $deadline);
            if (!$locked) {
                throw new RuntimeException('INSTANCE_CONFIG_BUSY');
            }
            $stat = @fstat($handle);
            if (!is_array($stat)) {
                throw new RuntimeException('SHARED_FILE_INVALID');
            }
            $this->validateFile($stat, 'shared');

            return $callback();
        } finally {
            if ($locked) {
                @flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    private function decodeObject(string $raw): object
    {
        try {
            $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('SHARED_FILE_INVALID');
        }
        if (!$decoded instanceof stdClass) {
            throw new RuntimeException('SHARED_FILE_INVALID');
        }

        return $decoded;
    }

    /** @param array<string|int,mixed> $stat */
    private function validateFile(array $stat, string $owner): void
    {
        if (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || ($stat['mode'] & 0777) !== 0660 || (int) $stat['gid'] !== $this->expectedGid) {
            throw new RuntimeException('SHARED_FILE_PERMISSION_INVALID');
        }
        $allowedOwners = match ($owner) {
            'php' => [$this->phpEuid],
            'agent' => [$this->agentUid],
            'shared' => [$this->phpEuid, $this->agentUid],
            default => [],
        };
        if (!in_array((int) $stat['uid'], $allowedOwners, true)) {
            throw new RuntimeException('SHARED_FILE_PERMISSION_INVALID');
        }
    }

    private function validateSharedDirectory(string $path): void
    {
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || (int) $stat['gid'] !== $this->expectedGid
            || !in_array($stat['mode'] & 07777, [0770, 02770], true)) {
            throw new RuntimeException('SHARED_FILE_PERMISSION_INVALID');
        }
    }

    private function path(string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, DIRECTORY_SEPARATOR)
            || str_contains($relative, "\0") || str_contains($relative, '..')) {
            throw new RuntimeException('SHARED_FILE_UNAVAILABLE');
        }

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
