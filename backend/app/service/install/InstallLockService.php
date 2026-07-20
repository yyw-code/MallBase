<?php

declare(strict_types=1);

namespace app\service\install;

/**
 * 安装锁文件读写服务。
 *
 * install.lock 是运行时文件，不进入版本库；除安装状态外，也承载平台实例的低频运行态。
 */
final class InstallLockService
{
    private const PLATFORM_KEY = 'platform';

    /** @var \Closure(string,string):void|null 仅用于文件竞态测试 */
    private readonly ?\Closure $operationHook;

    public function __construct(
        private readonly ?string $lockFilePath = null,
        ?\Closure $operationHook = null,
    ) {
        $this->operationHook = $operationHook;
    }

    public function isInstalled(): bool
    {
        $path = $this->lockFilePath();
        $identity = $this->pathIdentity($path);
        if ($identity === null) {
            return false;
        }
        $this->assertSafeRegularFile($identity, $path, '安装锁文件');

        return true;
    }

    public function lockFilePath(): string
    {
        if ($this->lockFilePath !== null) {
            return $this->lockFilePath;
        }

        return runtime_path() . 'install' . DIRECTORY_SEPARATOR . 'install.lock';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLockInfo(): ?array
    {
        $path = $this->lockFilePath();
        $identity = $this->pathIdentity($path);
        if ($identity === null) {
            return null;
        }
        $this->assertSafeRegularFile($identity, $path, '安装锁文件');
        $this->hardenLockDirectories($path);

        $coordinationHandle = $this->acquireCoordinationLock($path, LOCK_SH);
        $handle = null;
        $locked = false;
        try {
            if ($this->pathIdentity($path) === null) {
                return null;
            }
            [$handle, $identity] = $this->openExistingLock($path, 'read');
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('安装锁文件加共享锁失败：' . $path);
            }
            $locked = true;
            $this->hardenLockFile($path);
            $this->assertOpenedLockIdentity($path, $handle, $identity, 'read');
            $raw = $this->readLockContent($handle, $path);
        } finally {
            if (is_resource($handle)) {
                if ($locked) {
                    @flock($handle, LOCK_UN);
                }
                fclose($handle);
            }
            $this->releaseCoordinationLock($coordinationHandle);
        }

        if (trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function writeInstalledLock(?string $installedAt = null): void
    {
        $this->updateLockInfo(function (array $info) use ($installedAt): array {
            $info['installed_at'] = $installedAt ?? date('Y-m-d H:i:s');

            return $info;
        }, true);
    }

    /**
     * @return array{
     *     instance_id?: string,
     *     token?: string,
     *     last_report_at?: int,
     *     next_report_after?: int,
     *     disabled?: bool,
     *     components?: array<string, int>
     * }
     */
    public function getPlatformState(): array
    {
        $info = $this->getLockInfo();
        $state = is_array($info) ? ($info[self::PLATFORM_KEY] ?? []) : [];

        return is_array($state) ? $this->normalizePlatformState($state) : [];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function savePlatformState(array $state): array
    {
        $lockInfo = $this->updateLockInfo(function (array $info) use ($state): array {
            $current = $info[self::PLATFORM_KEY] ?? [];
            if (!is_array($current)) {
                $current = [];
            }

            $info[self::PLATFORM_KEY] = array_merge(
                $this->normalizePlatformState($current),
                $this->normalizePlatformState($state),
            );

            return $info;
        }, false);

        $platform = $lockInfo[self::PLATFORM_KEY] ?? [];

        return is_array($platform) ? $platform : [];
    }

    public function reservePlatformReportWindow(int $now, int $interval): bool
    {
        $reserved = false;

        $this->updateLockInfo(function (array $info) use ($now, $interval, &$reserved): array {
            $current = $info[self::PLATFORM_KEY] ?? [];
            if (!is_array($current)) {
                $current = [];
            }

            $current = $this->normalizePlatformState($current);
            if (($current['disabled'] ?? false) === true) {
                return $info;
            }

            if (($current['next_report_after'] ?? 0) > $now) {
                return $info;
            }

            $current['next_report_after'] = $now + max(1, $interval);
            $info[self::PLATFORM_KEY] = $current;
            $reserved = true;

            return $info;
        }, false);

        return $reserved;
    }

    public function markPlatformComponentSeen(string $componentType, int $now, int $minInterval = 3600): void
    {
        $componentType = trim($componentType);
        if ($componentType === '') {
            return;
        }

        $this->updateLockInfo(function (array $info) use ($componentType, $now, $minInterval): array {
            $current = $info[self::PLATFORM_KEY] ?? [];
            if (!is_array($current)) {
                $current = [];
            }

            $current = $this->normalizePlatformState($current);
            if (($current['disabled'] ?? false) === true) {
                return $info;
            }

            $components = $current['components'] ?? [];
            if (!is_array($components)) {
                $components = [];
            }

            $lastSeenAt = (int) ($components[$componentType] ?? 0);
            if ($lastSeenAt > 0 && $lastSeenAt + max(1, $minInterval) > $now) {
                return $info;
            }

            $components[$componentType] = $now;
            $current['components'] = $components;
            $info[self::PLATFORM_KEY] = $current;

            return $info;
        }, false);
    }

    /**
     * @return array<int, array{type: string, version: string}>
     */
    public function getActivePlatformComponents(int $now, int $activeWindow, string $version): array
    {
        $state = $this->getPlatformState();
        $components = $state['components'] ?? [];
        if (!is_array($components)) {
            return [];
        }

        $active = [];
        foreach ($components as $type => $lastSeenAt) {
            $lastSeenAt = (int) $lastSeenAt;
            if ($lastSeenAt <= 0 || $lastSeenAt + $activeWindow < $now) {
                continue;
            }

            $active[] = [
                'type' => (string) $type,
                'version' => $version,
            ];
        }

        return $active;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     * @return array<string, mixed>
     */
    private function updateLockInfo(callable $mutator, bool $create): array
    {
        $path = $this->lockFilePath();
        $initialIdentity = $this->pathIdentity($path);
        if ($initialIdentity !== null) {
            $this->assertSafeRegularFile($initialIdentity, $path, '安装锁文件');
        } elseif (!$create) {
            throw new \RuntimeException('安装锁不存在，无法写入平台实例状态：' . $path);
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('安装锁目录创建失败：' . $dir);
        }
        $this->hardenLockDirectories($path);

        $coordinationHandle = $this->acquireCoordinationLock($path, LOCK_EX);
        $lockHandle = null;
        $lockLocked = false;
        $lockIdentity = null;
        $temporaryHandle = null;
        $temporaryPath = null;
        $temporaryIdentity = null;
        $temporaryPublished = false;
        $result = null;
        $failure = null;
        $temporaryCleanupFailed = false;

        try {
            $currentIdentity = $this->pathIdentity($path);
            if ($currentIdentity !== null) {
                $this->assertSafeRegularFile($currentIdentity, $path, '安装锁文件');
                [$lockHandle, $lockIdentity] = $this->openExistingLock($path, 'update');
                if (!flock($lockHandle, LOCK_EX)) {
                    throw new \RuntimeException('安装锁文件加独占锁失败：' . $path);
                }
                $lockLocked = true;
                $this->hardenLockFile($path);
                $this->assertOpenedLockIdentity($path, $lockHandle, $lockIdentity, 'update');
                $raw = $this->readLockContent($lockHandle, $path);
                $info = [];
                if (trim($raw) !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $info = $decoded;
                    }
                }
            } else {
                if (!$create) {
                    throw new \RuntimeException('安装锁不存在，无法写入平台实例状态：' . $path);
                }
                $info = [];
            }

            $next = $mutator($info);
            if (!isset($next['installed_at']) || trim((string) $next['installed_at']) === '') {
                $next['installed_at'] = date('Y-m-d H:i:s');
            }

            if ($next == $info) {
                $result = $next;
            } else {
                $content = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                if ($content === false) {
                    throw new \RuntimeException('安装锁内容编码失败');
                }

                [$temporaryHandle, $temporaryPath, $temporaryIdentity] = $this->createTemporaryLockFile($path);
                $this->runOperationHook('temporary_created', $temporaryPath);
                $this->writeLockContent($temporaryHandle, $content, $temporaryPath);
                $this->assertTemporaryIdentity($temporaryPath, $temporaryHandle, $temporaryIdentity);
                $this->runOperationHook('temporary_flushed', $temporaryPath);
                $this->runOperationHook('before_publish', $path);

                if (is_resource($lockHandle) && is_array($lockIdentity)) {
                    $this->assertOpenedLockIdentity($path, $lockHandle, $lockIdentity, 'update');
                } elseif ($this->pathIdentity($path) !== null) {
                    throw new \RuntimeException('安装锁并发更新冲突，请重试：发布前锁文件已出现');
                }
                $this->assertTemporaryIdentity($temporaryPath, $temporaryHandle, $temporaryIdentity);
                fclose($temporaryHandle);
                $temporaryHandle = null;
                $this->assertPathIdentity($temporaryPath, $temporaryIdentity, '安装锁临时文件身份校验失败');

                if (!@rename($temporaryPath, $path)) {
                    throw new \RuntimeException('安装锁原子发布失败：' . $path);
                }
                $temporaryPublished = true;
                $this->assertPathIdentity($path, $temporaryIdentity, '安装锁发布后身份校验失败');
                $result = $next;
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            if (is_resource($temporaryHandle)) {
                fclose($temporaryHandle);
            }
            if (is_resource($lockHandle)) {
                if ($lockLocked) {
                    @flock($lockHandle, LOCK_UN);
                }
                fclose($lockHandle);
            }
            if ($failure !== null
                && !$temporaryPublished
                && is_string($temporaryPath)
                && is_array($temporaryIdentity)) {
                $temporaryCleanupFailed = !$this->cleanupOwnedTemporaryFile($temporaryPath, $temporaryIdentity);
            }
            $this->releaseCoordinationLock($coordinationHandle);
        }

        if ($failure !== null) {
            if ($temporaryCleanupFailed) {
                throw new \RuntimeException('安装锁临时文件清理失败：' . $temporaryPath, 0, $failure);
            }

            throw $failure;
        }

        return is_array($result) ? $result : [];
    }

    /** @param resource $handle */
    private function writeLockContent($handle, string $content, string $path): void
    {
        if (!@rewind($handle) || !@ftruncate($handle, 0)) {
            throw new \RuntimeException('安装锁内容写入失败：' . $path);
        }

        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $written = @fwrite($handle, substr($content, $offset));
            if (!is_int($written) || $written < 1) {
                throw new \RuntimeException('安装锁内容写入失败：' . $path);
            }
            $offset += $written;
        }
        if (!@fflush($handle)) {
            throw new \RuntimeException('安装锁内容写入失败：' . $path);
        }
        if (function_exists('fsync') && !@fsync($handle)) {
            throw new \RuntimeException('安装锁内容同步失败：' . $path);
        }
    }

    /**
     * @return array{dev:int,ino:int,nlink:int,mode:int}|null
     */
    private function pathIdentity(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);

        return is_array($stat) ? $this->normalizeIdentity($stat) : null;
    }

    /**
     * @param resource $handle
     * @return array{dev:int,ino:int,nlink:int,mode:int}
     */
    private function handleIdentity($handle, string $path): array
    {
        $stat = @fstat($handle);
        if (!is_array($stat)) {
            throw new \RuntimeException('安装锁文件状态读取失败：' . $path);
        }

        return $this->normalizeIdentity($stat);
    }

    /**
     * @param array<int|string, mixed> $stat
     * @return array{dev:int,ino:int,nlink:int,mode:int}
     */
    private function normalizeIdentity(array $stat): array
    {
        return [
            'dev' => (int) ($stat['dev'] ?? $stat[0] ?? -1),
            'ino' => (int) ($stat['ino'] ?? $stat[1] ?? -1),
            'mode' => (int) ($stat['mode'] ?? $stat[2] ?? 0),
            'nlink' => (int) ($stat['nlink'] ?? $stat[3] ?? 0),
        ];
    }

    /** @param array{dev:int,ino:int,nlink:int,mode:int} $identity */
    private function assertSafeRegularFile(array $identity, string $path, string $subject): void
    {
        $type = $identity['mode'] & 0170000;
        if ($type === 0120000) {
            throw new \RuntimeException($subject . '不能是符号链接：' . $path);
        }
        if ($type !== 0100000) {
            throw new \RuntimeException($subject . '必须是普通文件：' . $path);
        }
        if ($identity['nlink'] !== 1) {
            throw new \RuntimeException($subject . '存在硬链接，已拒绝：' . $path);
        }
    }

    /**
     * @param array{dev:int,ino:int,nlink:int,mode:int} $left
     * @param array{dev:int,ino:int,nlink:int,mode:int} $right
     */
    private function sameFileIdentity(array $left, array $right): bool
    {
        return $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }

    /**
     * @return array{0:resource,1:array{dev:int,ino:int,nlink:int,mode:int}}
     */
    private function openExistingLock(string $path, string $operation): array
    {
        $before = $this->pathIdentity($path);
        if ($before === null) {
            throw new \RuntimeException($this->identityConflictMessage($operation));
        }
        $this->assertSafeRegularFile($before, $path, '安装锁文件');

        $handle = @fopen($path, 'r+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('安装锁文件打开失败：' . $path);
        }

        try {
            $handleIdentity = $this->handleIdentity($handle, $path);
            $this->runOperationHook('lock_opened', $path);
            $after = $this->pathIdentity($path);
            if ($after === null) {
                throw new \RuntimeException($this->identityConflictMessage($operation));
            }
            $this->assertSafeRegularFile($handleIdentity, $path, '安装锁文件');
            $this->assertSafeRegularFile($after, $path, '安装锁文件');
            if (!$this->sameFileIdentity($before, $handleIdentity)
                || !$this->sameFileIdentity($after, $handleIdentity)) {
                throw new \RuntimeException($this->identityConflictMessage($operation));
            }

            return [$handle, $handleIdentity];
        } catch (\Throwable $exception) {
            fclose($handle);
            throw $exception;
        }
    }

    /**
     * @param resource $handle
     * @param array{dev:int,ino:int,nlink:int,mode:int} $expected
     */
    private function assertOpenedLockIdentity(string $path, $handle, array $expected, string $operation): void
    {
        $pathIdentity = $this->pathIdentity($path);
        if ($pathIdentity === null) {
            throw new \RuntimeException($this->identityConflictMessage($operation));
        }
        $handleIdentity = $this->handleIdentity($handle, $path);
        $this->assertSafeRegularFile($pathIdentity, $path, '安装锁文件');
        if (($handleIdentity['mode'] & 0170000) !== 0100000
            || $handleIdentity['nlink'] !== 1
            || !$this->sameFileIdentity($expected, $pathIdentity)
            || !$this->sameFileIdentity($expected, $handleIdentity)) {
            throw new \RuntimeException($this->identityConflictMessage($operation));
        }
    }

    private function identityConflictMessage(string $operation): string
    {
        return $operation === 'read'
            ? '安装锁并发读取冲突，请重试：锁文件身份已变化'
            : '安装锁并发更新冲突，请重试：锁文件身份已变化';
    }

    /** @param resource $handle */
    private function readLockContent($handle, string $path): string
    {
        if (!@rewind($handle)) {
            throw new \RuntimeException('安装锁内容读取失败：' . $path);
        }
        $raw = stream_get_contents($handle);
        if (!is_string($raw)) {
            throw new \RuntimeException('安装锁内容读取失败：' . $path);
        }

        return $raw;
    }

    /** @return resource */
    private function acquireCoordinationLock(string $lockPath, int $operation)
    {
        $path = dirname($lockPath) . DIRECTORY_SEPARATOR . '.' . basename($lockPath) . '.guard';
        $initial = $this->pathIdentity($path);
        $created = false;
        if ($initial !== null) {
            $this->assertSafeRegularFile($initial, $path, '安装锁协调文件');
            $handle = @fopen($path, 'r+b');
        } else {
            $handle = @fopen($path, 'x+b');
            if (is_resource($handle)) {
                $created = true;
            } else {
                $initial = $this->pathIdentity($path);
                if ($initial !== null) {
                    $this->assertSafeRegularFile($initial, $path, '安装锁协调文件');
                    $handle = @fopen($path, 'r+b');
                }
            }
        }
        if (!is_resource($handle)) {
            throw new \RuntimeException('安装锁协调文件打开失败：' . $path);
        }

        $identity = null;
        try {
            $identity = $this->handleIdentity($handle, $path);
            $current = $this->pathIdentity($path);
            if ($current === null) {
                throw new \RuntimeException('安装锁协调文件身份校验失败：' . $path);
            }
            $this->assertSafeRegularFile($identity, $path, '安装锁协调文件');
            $this->assertSafeRegularFile($current, $path, '安装锁协调文件');
            if (($initial !== null && !$this->sameFileIdentity($initial, $identity))
                || !$this->sameFileIdentity($current, $identity)) {
                throw new \RuntimeException('安装锁协调文件身份校验失败：' . $path);
            }
            $this->hardenOwnedFile($path, $identity, '安装锁协调文件');
            if (!flock($handle, $operation)) {
                throw new \RuntimeException('安装锁协调文件加锁失败：' . $path);
            }

            return $handle;
        } catch (\Throwable $exception) {
            fclose($handle);
            if ($created && is_array($identity)) {
                $this->cleanupOwnedTemporaryFile($path, $identity);
            }
            throw $exception;
        }
    }

    /** @param resource $handle */
    private function releaseCoordinationLock($handle): void
    {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * @return array{0:resource,1:string,2:array{dev:int,ino:int,nlink:int,mode:int}}
     */
    private function createTemporaryLockFile(string $lockPath): array
    {
        $directory = dirname($lockPath);
        $prefix = $directory . DIRECTORY_SEPARATOR . '.' . basename($lockPath) . '.';
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $path = $prefix . bin2hex(random_bytes(8)) . '.tmp';
            $handle = @fopen($path, 'x+b');
            if (!is_resource($handle)) {
                continue;
            }

            $identity = null;
            try {
                $identity = $this->handleIdentity($handle, $path);
                $current = $this->pathIdentity($path);
                if ($current === null) {
                    throw new \RuntimeException('安装锁临时文件身份校验失败：' . $path);
                }
                $this->assertSafeRegularFile($identity, $path, '安装锁临时文件');
                $this->assertSafeRegularFile($current, $path, '安装锁临时文件');
                if (!$this->sameFileIdentity($identity, $current)) {
                    throw new \RuntimeException('安装锁临时文件身份校验失败：' . $path);
                }
                $this->hardenOwnedFile($path, $identity, '安装锁临时文件');

                return [$handle, $path, $identity];
            } catch (\Throwable $exception) {
                fclose($handle);
                if (is_array($identity)) {
                    $this->cleanupOwnedTemporaryFile($path, $identity);
                }
                throw $exception;
            }
        }

        throw new \RuntimeException('安装锁临时文件创建失败：' . $directory);
    }

    /**
     * @param resource $handle
     * @param array{dev:int,ino:int,nlink:int,mode:int} $expected
     */
    private function assertTemporaryIdentity(string $path, $handle, array $expected): void
    {
        $pathIdentity = $this->pathIdentity($path);
        if ($pathIdentity === null) {
            throw new \RuntimeException('安装锁临时文件身份校验失败：' . $path);
        }
        $handleIdentity = $this->handleIdentity($handle, $path);
        $this->assertSafeRegularFile($pathIdentity, $path, '安装锁临时文件');
        $this->assertSafeRegularFile($handleIdentity, $path, '安装锁临时文件');
        if (!$this->sameFileIdentity($expected, $pathIdentity)
            || !$this->sameFileIdentity($expected, $handleIdentity)
            || ($pathIdentity['mode'] & 0777) !== 0600
            || ($handleIdentity['mode'] & 0777) !== 0600) {
            throw new \RuntimeException('安装锁临时文件身份校验失败：' . $path);
        }
    }

    /**
     * @param array{dev:int,ino:int,nlink:int,mode:int} $expected
     */
    private function assertPathIdentity(string $path, array $expected, string $message): void
    {
        $current = $this->pathIdentity($path);
        if ($current === null) {
            throw new \RuntimeException($message . '：' . $path);
        }
        $this->assertSafeRegularFile($current, $path, '安装锁文件');
        if (!$this->sameFileIdentity($expected, $current) || ($current['mode'] & 0777) !== 0600) {
            throw new \RuntimeException($message . '：' . $path);
        }
    }

    /**
     * @param array{dev:int,ino:int,nlink:int,mode:int} $expected
     */
    private function hardenOwnedFile(string $path, array $expected, string $subject): void
    {
        if (!@chmod($path, 0600)) {
            throw new \RuntimeException($subject . '权限收紧失败：' . $path);
        }
        $current = $this->pathIdentity($path);
        if ($current === null) {
            throw new \RuntimeException($subject . '身份校验失败：' . $path);
        }
        $this->assertSafeRegularFile($current, $path, $subject);
        if (!$this->sameFileIdentity($expected, $current) || ($current['mode'] & 0777) !== 0600) {
            throw new \RuntimeException($subject . '权限校验失败：' . $path);
        }
    }

    /**
     * @param array{dev:int,ino:int,nlink:int,mode:int} $expected
     */
    private function cleanupOwnedTemporaryFile(string $path, array $expected): bool
    {
        $current = $this->pathIdentity($path);
        if ($current === null) {
            return true;
        }
        if (($current['mode'] & 0170000) !== 0100000
            || $current['nlink'] !== 1
            || !$this->sameFileIdentity($expected, $current)) {
            return true;
        }
        if (@unlink($path)) {
            return true;
        }

        $after = $this->pathIdentity($path);
        return $after === null || !$this->sameFileIdentity($expected, $after);
    }

    private function runOperationHook(string $event, string $path): void
    {
        if ($this->operationHook !== null) {
            ($this->operationHook)($event, $path);
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function normalizePlatformState(array $state): array
    {
        $normalized = [];

        foreach (['instance_id', 'token'] as $key) {
            if (isset($state[$key]) && trim((string) $state[$key]) !== '') {
                $normalized[$key] = trim((string) $state[$key]);
            }
        }

        foreach (['last_report_at', 'next_report_after', 'last_report_error_at'] as $key) {
            if (isset($state[$key])) {
                $normalized[$key] = max(0, (int) $state[$key]);
            }
        }

        if (array_key_exists('last_report_error', $state)) {
            $normalized['last_report_error'] = substr(trim((string) $state['last_report_error']), 0, 180);
        }

        if (array_key_exists('disabled', $state)) {
            $normalized['disabled'] = filter_var($state['disabled'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($state['components']) && is_array($state['components'])) {
            $components = [];
            foreach ($state['components'] as $type => $lastSeenAt) {
                $type = trim((string) $type);
                if ($type === '') {
                    continue;
                }

                $components[$type] = max(0, (int) $lastSeenAt);
            }
            if ($components !== []) {
                $normalized['components'] = $components;
            }
        }

        return $normalized;
    }

    private function hardenLockDirectories(string $path): void
    {
        $installDirectory = dirname($path);
        $runtimeDirectory = dirname($installDirectory);
        $directories = basename($path) === 'install.lock'
            && basename($installDirectory) === 'install'
            && basename($runtimeDirectory) === 'runtime'
            ? [$runtimeDirectory, $installDirectory]
            : [];

        foreach ($directories as $directory) {
            if (!is_dir($directory) || is_link($directory) || !@chmod($directory, 0755)) {
                throw new \RuntimeException('安装锁目录权限收紧失败：' . $directory);
            }
            clearstatcache(true, $directory);
            $directoryPermissions = @fileperms($directory);
            if (!is_int($directoryPermissions) || ($directoryPermissions & 0777) !== 0755) {
                throw new \RuntimeException('安装锁目录权限校验失败：' . $directory);
            }
        }
    }

    private function hardenLockFile(string $path): void
    {
        if (!@chmod($path, 0600)) {
            throw new \RuntimeException('安装锁权限收紧失败：' . $path);
        }
        clearstatcache(true, $path);
        $permissions = @fileperms($path);
        if (!is_int($permissions) || ($permissions & 0777) !== 0600) {
            throw new \RuntimeException('安装锁权限校验失败：' . $path);
        }
    }
}
