<?php

declare(strict_types=1);

namespace app\service\upgrade;

use app\support\upload\LocalUploadRootPolicy as UploadLocalUploadRootPolicy;
use RuntimeException;

/** Upgrade-facing adapter that preserves sanitized upgrade error codes. */
final readonly class LocalUploadRootPolicy
{
    public const CANONICAL_ROOT = UploadLocalUploadRootPolicy::CANONICAL_ROOT;

    private UploadLocalUploadRootPolicy $policy;

    public function __construct(?UploadLocalUploadRootPolicy $policy = null)
    {
        $this->policy = $policy ?? new UploadLocalUploadRootPolicy();
    }

    public function assertSupported(string $configuredRoot, string $publicRoot): void
    {
        try {
            $this->policy->assertSupported($configuredRoot, $publicRoot);
        } catch (RuntimeException $exception) {
            $code = match ($exception->getMessage()) {
                UploadLocalUploadRootPolicy::ERROR_UNSUPPORTED => 'UPGRADE_LOCAL_UPLOAD_ROOT_UNSUPPORTED',
                UploadLocalUploadRootPolicy::ERROR_UNAVAILABLE => 'UPGRADE_LOCAL_UPLOAD_ROOT_UNAVAILABLE',
                default => 'UPGRADE_WRITABLE_SURFACE_UNAVAILABLE',
            };

            throw new RuntimeException($code, 0, $exception);
        }
    }
}
