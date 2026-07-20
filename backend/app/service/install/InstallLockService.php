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

    public function __construct(private readonly ?string $lockFilePath = null)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockFilePath());
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
        if (!is_file($path)) {
            return null;
        }
        $this->hardenPermissions($path);

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
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
        if (!$create && !is_file($path)) {
            throw new \RuntimeException('安装锁不存在，无法写入平台实例状态：' . $path);
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('安装锁目录创建失败：' . $dir);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('安装锁文件打开失败：' . $path);
        }

        try {
            $this->hardenPermissions($path);
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('安装锁文件加锁失败：' . $path);
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $info = [];
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $info = $decoded;
                }
            }

            $next = $mutator($info);
            if (!isset($next['installed_at']) || trim((string) $next['installed_at']) === '') {
                $next['installed_at'] = date('Y-m-d H:i:s');
            }

            if ($next == $info) {
                flock($handle, LOCK_UN);

                return $next;
            }

            $content = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($content === false) {
                throw new \RuntimeException('安装锁内容编码失败');
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $content);
            fflush($handle);
            flock($handle, LOCK_UN);

            return $next;
        } finally {
            fclose($handle);
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

    private function hardenPermissions(string $path): void
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
