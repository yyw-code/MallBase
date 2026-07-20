<?php

declare(strict_types=1);

namespace app\support\upload;

use RuntimeException;

/**
 * Canonical local-upload root contract shared by settings, runtime uploads,
 * migrations and upgrade checks.
 */
final readonly class LocalUploadRootPolicy
{
    public const CANONICAL_ROOT = 'uploads';

    public const ERROR_UNSUPPORTED = 'LOCAL_UPLOAD_ROOT_UNSUPPORTED';

    public const ERROR_UNAVAILABLE = 'LOCAL_UPLOAD_ROOT_UNAVAILABLE';

    public const MIGRATION_REQUIRED_MESSAGE = '本地上传目录仅支持 uploads，请先通过升级流程完成数据迁移';

    public function assertCanonical(mixed $configuredRoot): void
    {
        if ($configuredRoot !== self::CANONICAL_ROOT) {
            throw new RuntimeException(self::ERROR_UNSUPPORTED);
        }
    }

    public function assertSupported(mixed $configuredRoot, string $publicRoot): string
    {
        $this->assertCanonical($configuredRoot);

        if ($publicRoot === '' || str_contains($publicRoot, "\0") || !str_starts_with($publicRoot, '/')) {
            throw new RuntimeException(self::ERROR_UNAVAILABLE);
        }

        $normalizedPublicRoot = rtrim($publicRoot, '/');
        $publicStat = @lstat($normalizedPublicRoot);
        $publicReal = realpath($normalizedPublicRoot);
        if ($normalizedPublicRoot === ''
            || !is_array($publicStat)
            || ($publicStat['mode'] & 0170000) !== 0040000
            || !is_string($publicReal)
            || $publicReal !== $normalizedPublicRoot) {
            throw new RuntimeException(self::ERROR_UNAVAILABLE);
        }

        $root = $normalizedPublicRoot . '/' . self::CANONICAL_ROOT;
        $rootStat = @lstat($root);
        $rootReal = realpath($root);
        if (!is_array($rootStat)
            || ($rootStat['mode'] & 0170000) !== 0040000
            || !is_string($rootReal)
            || $rootReal !== $root
            || !str_starts_with($root . '/', $publicReal . '/')) {
            throw new RuntimeException(self::ERROR_UNAVAILABLE);
        }

        return $root;
    }
}
