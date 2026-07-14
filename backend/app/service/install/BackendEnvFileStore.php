<?php

declare(strict_types=1);

namespace app\service\install;

use RuntimeException;
use Throwable;

/**
 * 以同目录原子替换方式发布后端环境文件。
 *
 * 生产环境的目标文件位于持久化的 .mallbase-env 卷中；开发环境则仍可
 * 使用 backend/.env。调用方只能提供最终目标和固定模板，不能提供临时路径。
 */
final readonly class BackendEnvFileStore
{
    private const MAX_BYTES = 1024 * 1024;

    /**
     * @param array<string, string> $values
     */
    public function write(string $targetPath, string $templatePath, array $values): void
    {
        $this->assertAbsolutePath($targetPath);
        $this->assertAbsolutePath($templatePath);
        $directory = dirname($targetPath);
        $directoryHandle = $this->openDirectory($directory);
        $lockHandle = null;
        $temporaryPath = '';

        try {
            $lockHandle = $this->openLock($directory . '/.backend-env.lock');
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('BACKEND_ENV_LOCK_FAILED');
            }

            $baseContent = $this->readBaseContent($targetPath, $templatePath);
            $content = $this->mergeValues($baseContent, $values);
            if (strlen($content) > self::MAX_BYTES) {
                throw new RuntimeException('BACKEND_ENV_CONTENT_INVALID');
            }

            $temporaryPath = $directory . '/.' . basename($targetPath) . '.' . bin2hex(random_bytes(16)) . '.tmp';
            $temporaryHandle = @fopen($temporaryPath, 'x+b');
            if (!is_resource($temporaryHandle)) {
                throw new RuntimeException('BACKEND_ENV_TEMP_CREATE_FAILED');
            }
            try {
                if (!chmod($temporaryPath, 0600)) {
                    throw new RuntimeException('BACKEND_ENV_TEMP_MODE_FAILED');
                }
                $this->writeAll($temporaryHandle, $content);
                if (!fflush($temporaryHandle) || !fsync($temporaryHandle)) {
                    throw new RuntimeException('BACKEND_ENV_FILE_SYNC_FAILED');
                }
            } finally {
                fclose($temporaryHandle);
            }

            $this->assertDirectoryUnchanged($directory, $directoryHandle);
            if (!@rename($temporaryPath, $targetPath)) {
                throw new RuntimeException('BACKEND_ENV_RENAME_FAILED');
            }
            $temporaryPath = '';
            $this->assertPublishedFile($targetPath);
            if (!fsync($directoryHandle)) {
                throw new RuntimeException('BACKEND_ENV_DIRECTORY_SYNC_FAILED');
            }
        } catch (Throwable $exception) {
            if ($temporaryPath !== '' && is_file($temporaryPath) && !is_link($temporaryPath)) {
                @unlink($temporaryPath);
            }
            if ($exception instanceof RuntimeException
                && str_starts_with($exception->getMessage(), 'BACKEND_ENV_')) {
                throw $exception;
            }
            throw new RuntimeException('BACKEND_ENV_WRITE_FAILED', 0, $exception);
        } finally {
            if (is_resource($lockHandle)) {
                @flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
            fclose($directoryHandle);
        }
    }

    /** @return resource */
    private function openDirectory(string $directory)
    {
        $nameStat = @lstat($directory);
        $handle = @fopen($directory, 'rb');
        if (!is_array($nameStat) || !$this->isDirectory($nameStat) || is_link($directory)
            || !is_resource($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('BACKEND_ENV_DIRECTORY_INVALID');
        }
        $descriptorStat = fstat($handle);
        if (!is_array($descriptorStat) || !$this->sameInode($nameStat, $descriptorStat)) {
            fclose($handle);
            throw new RuntimeException('BACKEND_ENV_DIRECTORY_INVALID');
        }

        return $handle;
    }

    /** @return resource */
    private function openLock(string $path)
    {
        $nameStat = @lstat($path);
        if ($nameStat === false) {
            $handle = @fopen($path, 'x+b');
            if (!is_resource($handle)) {
                $nameStat = @lstat($path);
                $handle = @fopen($path, 'c+b');
            } else {
                @chmod($path, 0660);
                $nameStat = @lstat($path);
            }
        } else {
            $handle = @fopen($path, 'c+b');
        }
        if (!is_resource($handle) || !is_array($nameStat) || !$this->isRegular($nameStat)
            || (int) ($nameStat['nlink'] ?? 0) !== 1) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('BACKEND_ENV_LOCK_INVALID');
        }
        $descriptorStat = fstat($handle);
        if (!is_array($descriptorStat) || !$this->sameInode($nameStat, $descriptorStat)) {
            fclose($handle);
            throw new RuntimeException('BACKEND_ENV_LOCK_INVALID');
        }

        return $handle;
    }

    private function readBaseContent(string $targetPath, string $templatePath): string
    {
        $content = null;
        if (file_exists($targetPath) || is_link($targetPath)) {
            $content = $this->readRegularFile($targetPath);
        }
        if ($content === null || $content === '') {
            $content = $this->readRegularFile($templatePath);
        }
        if ($content === '' || strlen($content) > self::MAX_BYTES) {
            throw new RuntimeException('BACKEND_ENV_TEMPLATE_INVALID');
        }

        return $content;
    }

    private function readRegularFile(string $path): string
    {
        $nameStat = @lstat($path);
        if (!is_array($nameStat) || !$this->isRegular($nameStat)
            || (int) ($nameStat['nlink'] ?? 0) !== 1 || is_link($path)) {
            throw new RuntimeException('BACKEND_ENV_FILE_INVALID');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('BACKEND_ENV_FILE_INVALID');
        }
        try {
            $descriptorStat = fstat($handle);
            if (!is_array($descriptorStat) || !$this->sameInode($nameStat, $descriptorStat)) {
                throw new RuntimeException('BACKEND_ENV_FILE_INVALID');
            }
            $content = stream_get_contents($handle, self::MAX_BYTES + 1);
            if (!is_string($content) || strlen($content) > self::MAX_BYTES) {
                throw new RuntimeException('BACKEND_ENV_FILE_INVALID');
            }

            return $content;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, string> $values
     */
    private function mergeValues(string $baseContent, array $values): string
    {
        foreach ($values as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1
                || !is_string($value) || str_contains($value, "\0")
                || str_contains($value, "\r") || str_contains($value, "\n")
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                throw new RuntimeException('BACKEND_ENV_VALUE_INVALID');
            }
            $pattern = '/^' . preg_quote($key, '/') . '\s*=.*$/m';
            $matches = preg_match_all($pattern, $baseContent);
            if ($matches === false || $matches > 1) {
                throw new RuntimeException('BACKEND_ENV_TEMPLATE_INVALID');
            }
            $line = $key . '=' . $this->formatValue($value);
            if ($matches === 1) {
                $baseContent = (string) preg_replace_callback(
                    $pattern,
                    static fn (): string => $line,
                    $baseContent,
                    1,
                );
            } else {
                $baseContent = rtrim($baseContent, "\n") . PHP_EOL . $line . PHP_EOL;
            }
        }

        return $baseContent;
    }

    /** @param resource $handle */
    private function writeAll($handle, string $content): void
    {
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new RuntimeException('BACKEND_ENV_WRITE_FAILED');
            }
            $offset += $written;
        }
    }

    /** @param resource $directoryHandle */
    private function assertDirectoryUnchanged(string $directory, $directoryHandle): void
    {
        $nameStat = @lstat($directory);
        $descriptorStat = fstat($directoryHandle);
        if (!is_array($nameStat) || !is_array($descriptorStat) || !$this->isDirectory($nameStat)
            || !$this->sameInode($nameStat, $descriptorStat)) {
            throw new RuntimeException('BACKEND_ENV_DIRECTORY_CHANGED');
        }
    }

    private function assertPublishedFile(string $path): void
    {
        $stat = @lstat($path);
        if (!is_array($stat) || !$this->isRegular($stat) || (int) ($stat['nlink'] ?? 0) !== 1
            || (((int) $stat['mode']) & 0777) !== 0600) {
            throw new RuntimeException('BACKEND_ENV_PUBLISH_INVALID');
        }
    }

    private function assertAbsolutePath(string $path): void
    {
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0")) {
            throw new RuntimeException('BACKEND_ENV_PATH_INVALID');
        }
    }

    /** @param array<string|int, mixed> $stat */
    private function isDirectory(array $stat): bool
    {
        return (((int) ($stat['mode'] ?? 0)) & 0170000) === 0040000;
    }

    /** @param array<string|int, mixed> $stat */
    private function isRegular(array $stat): bool
    {
        return (((int) ($stat['mode'] ?? 0)) & 0170000) === 0100000;
    }

    /**
     * @param array<string|int, mixed> $first
     * @param array<string|int, mixed> $second
     */
    private function sameInode(array $first, array $second): bool
    {
        return (int) ($first['dev'] ?? -1) === (int) ($second['dev'] ?? -2)
            && (int) ($first['ino'] ?? -1) === (int) ($second['ino'] ?? -2);
    }

    private function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // ThinkPHP loads this file with parse_ini_file(..., INI_SCANNER_RAW).
        // In RAW mode the outer double quotes are removed while every inner
        // byte (including quotes, backslashes, dollars and backticks) is kept.
        // The entrypoint never sources this file directly.
        return '"' . $value . '"';
    }
}
