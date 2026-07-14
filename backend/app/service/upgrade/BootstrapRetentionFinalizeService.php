<?php

declare(strict_types=1);

namespace app\service\upgrade;

use app\service\admin\setting\SettingService;
use RuntimeException;

/**
 * Isolated, target-only bootstrap finalizer. It never accepts a caller path;
 * every authority, result and application root is fixed by the container.
 */
final readonly class BootstrapRetentionFinalizeService
{
    private const MAX_AUTHORITY_BYTES = 1048576;
    private const MAX_TREE_ENTRIES = 100000;
    private const MAX_TREE_BYTES = 536870912;
    private const MARKER = '.mallbase-layout-marker.json';

    /** @var array<string,string> */
    private array $artifactRoots;

    private int $agentUid;

    private int $sharedGid;

    public function __construct(
        private SettingService $settings,
        private LocalUploadRootPolicy $localRootPolicy,
        private string $authorityRoot = '/bootstrap-authority',
        private string $resultRoot = '/bootstrap-results',
        private string $publicRoot = '/app/public',
        ?array $artifactRoots = null,
        ?int $agentUid = null,
        ?int $sharedGid = null,
    ) {
        $this->artifactRoots = $artifactRoots ?? [
            'cert' => '/app/storage/cert',
            'demo' => '/app/public/static/demo',
            'install' => '/app/runtime/install',
            'local_storage' => '/app/runtime/storage',
            'public_storage' => '/app/public/storage',
            'runtime_backup' => '/app/runtime/backup',
            'uploads' => '/app/public/uploads',
        ];
        $agentUid ??= self::requiredIdentityEnvironment('MALLBASE_AGENT_UID');
        $sharedGid ??= self::requiredIdentityEnvironment('MALLBASE_UPGRADE_SHARED_GID');
        if ($agentUid <= 0 || $sharedGid <= 0) {
            throw new RuntimeException('BOOTSTRAP_AUTHORITY_IDENTITY_INVALID');
        }
        $this->agentUid = $agentUid;
        $this->sharedGid = $sharedGid;
    }

    /** @return array{operation_id:string,confirmation_sha256:string,target_authorization_sha256:string} */
    public function finalize(string $retentionId): array
    {
        $this->assertUuid($retentionId);
        $public = $this->readCanonicalAuthority($this->authorityRoot . '/storage-ready.pub', 0444, true);
        $authorization = $this->readCanonicalAuthority(
            $this->authorityRoot . '/bootstrap-target-authority.json',
            0444,
            true,
        );
        $this->verifyAuthorization($authorization['document'], $authorization['bytes'], $public['document']);

        /** @var array<string,mixed> $document */
        $document = $authorization['document'];
        if (($document['operation_id'] ?? null) !== $retentionId
            || ($document['migration_id'] ?? null) !== $retentionId) {
            throw new RuntimeException('BOOTSTRAP_TARGET_OPERATION_INVALID');
        }
        $targetAuthorizationSha256 = self::hashBytes($authorization['bytes']);
        $verifiedRoots = $this->verifyTargetRoots($document);
        $targetDirectory = $this->requireTargetDirectory();
        $finalizeLock = $this->acquireFinalizeLock($targetDirectory);

        try {
            $this->cleanupInterruptedPublications($targetDirectory);
            $configuredRoot = $this->settings->getLocalUploadRootForBootstrap();
            $intent = $this->loadOrCreateLocalSettingIntent(
                $targetDirectory,
                $document,
                $configuredRoot,
                $targetAuthorizationSha256,
            );
            $currentRoot = $this->settings->getLocalUploadRootForBootstrap();
            if ($currentRoot !== LocalUploadRootPolicy::CANONICAL_ROOT
                && !hash_equals(
                    (string) $intent['expected_old_value_sha256'],
                    self::hashString($currentRoot),
                )) {
                throw new RuntimeException('BOOTSTRAP_LOCAL_UPLOAD_SETTING_CONFLICT');
            }
            $this->settings->compareAndSetLocalUploadRootForBootstrap($currentRoot);
            $effectiveRoot = $this->settings->getLocalUploadRootForBootstrap();
            if ($effectiveRoot !== LocalUploadRootPolicy::CANONICAL_ROOT) {
                throw new RuntimeException('BOOTSTRAP_LOCAL_UPLOAD_SETTING_VERIFY_FAILED');
            }
            $this->localRootPolicy->assertSupported($effectiveRoot, $this->publicRoot);

            $localReceipt = [
                'schema_version' => 1,
                'purpose' => 'storage_bootstrap_local_setting_receipt',
                'operation_id' => $retentionId,
                'retention_receipt_sha256' => $document['retention_receipt_sha256'],
                'local_setting_intent_sha256' => $document['local_setting_intent_sha256'],
                'expected_old_value_sha256' => $intent['expected_old_value_sha256'],
                'canonical_value' => LocalUploadRootPolicy::CANONICAL_ROOT,
                'target_authorization_sha256' => $targetAuthorizationSha256,
                'effective_value_sha256' => self::hashString($effectiveRoot),
                'complete' => true,
            ];
            $localReceiptBytes = self::canonicalJson($localReceipt);
            $this->publishImmutable($targetDirectory . '/local-setting.json', $localReceiptBytes);
            $localReceiptSha256 = self::hashBytes($localReceiptBytes);

            $evidenceWithoutHash = [
                'operation_id' => $retentionId,
                'composite_receipt_sha256' => $document['composite_receipt_sha256'],
                'target_authorization_sha256' => $targetAuthorizationSha256,
                'verified_target_roots' => $verifiedRoots,
                'local_setting_receipt_sha256' => $localReceiptSha256,
                'complete' => true,
            ];
            $confirmationSha256 = self::hashBytes(self::canonicalJson($evidenceWithoutHash));
            $evidence = [
                'operation_id' => $retentionId,
                'confirmation_sha256' => $confirmationSha256,
                'composite_receipt_sha256' => $document['composite_receipt_sha256'],
                'target_authorization_sha256' => $targetAuthorizationSha256,
                'verified_target_roots' => $verifiedRoots,
                'local_setting_receipt_sha256' => $localReceiptSha256,
                'complete' => true,
            ];
            $envelope = [
                'schema_version' => 1,
                'purpose' => 'storage_bootstrap_adopt_target_confirmation',
                'evidence' => $evidence,
            ];
            $this->publishImmutable($targetDirectory . '/confirmation.json', self::canonicalJson($envelope));

            return [
                'operation_id' => $retentionId,
                'confirmation_sha256' => $confirmationSha256,
                'target_authorization_sha256' => $targetAuthorizationSha256,
            ];
        } finally {
            flock($finalizeLock, LOCK_UN);
            fclose($finalizeLock);
        }
    }

    /**
     * Content-only root shared with the no-network bootstrap helper. Modes and
     * ownership are intentionally excluded so permission normalization cannot
     * change the root. The protected marker is verified separately.
     */
    public static function contentRoot(string $root): string
    {
        $rootStat = @lstat($root);
        if (!is_array($rootStat) || ($rootStat['mode'] & 0170000) !== 0040000 || is_link($root)) {
            throw new RuntimeException('BOOTSTRAP_TARGET_ROOT_INVALID');
        }
        $rootDevice = (int) $rootStat['dev'];
        $rows = [self::canonicalJsonLine(['directory', '.'])];
        $entries = 1;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
            if ($relative === self::MARKER) {
                continue;
            }
            self::assertRelativeEvidencePath($relative);
            $stat = @lstat($path);
            if (!is_array($stat) || (int) $stat['dev'] !== $rootDevice || is_link($path)) {
                throw new RuntimeException('BOOTSTRAP_TARGET_ENTRY_INVALID');
            }
            $type = $stat['mode'] & 0170000;
            if ($type === 0040000) {
                $rows[] = self::canonicalJsonLine(['directory', str_replace(DIRECTORY_SEPARATOR, '/', $relative)]);
            } elseif ($type === 0100000 && (int) $stat['nlink'] === 1) {
                $size = (int) $stat['size'];
                if ($size < 0 || $size > self::MAX_TREE_BYTES - $bytes) {
                    throw new RuntimeException('BOOTSTRAP_TARGET_TREE_TOO_LARGE');
                }
                $digest = @hash_file('sha256', $path);
                if (!is_string($digest)) {
                    throw new RuntimeException('BOOTSTRAP_TARGET_ENTRY_UNREADABLE');
                }
                $bytes += $size;
                $rows[] = self::canonicalJsonLine([
                    'file',
                    str_replace(DIRECTORY_SEPARATOR, '/', $relative),
                    $size,
                    'sha256:' . $digest,
                ]);
            } else {
                throw new RuntimeException('BOOTSTRAP_TARGET_ENTRY_INVALID');
            }
            if (++$entries > self::MAX_TREE_ENTRIES) {
                throw new RuntimeException('BOOTSTRAP_TARGET_TREE_TOO_LARGE');
            }
        }
        sort($rows, SORT_STRING);

        return self::hashBytes(implode('', $rows));
    }

    /** @param array<string,mixed> $authorization @return array<string,string> */
    private function verifyTargetRoots(array $authorization): array
    {
        $targets = $authorization['targets'] ?? null;
        if (!is_array($targets) || array_keys($targets) !== array_keys($this->artifactRoots)) {
            throw new RuntimeException('BOOTSTRAP_TARGETS_INVALID');
        }
        $keys = array_keys($targets);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        if ($keys !== $sorted) {
            throw new RuntimeException('BOOTSTRAP_TARGETS_INVALID');
        }
        $verified = [];
        foreach ($targets as $artifact => $target) {
            if (!is_string($artifact) || !isset($this->artifactRoots[$artifact]) || !is_array($target)
                || ($target['artifact'] ?? null) !== $artifact
                || !self::isHash($target['marker_sha256'] ?? null)
                || !self::isHash($target['expected_content_root'] ?? null)) {
                throw new RuntimeException('BOOTSTRAP_TARGETS_INVALID');
            }
            $root = $this->artifactRoots[$artifact];
            $marker = $this->readCanonicalAuthority($root . '/' . self::MARKER, 0444, true);
            if (self::hashBytes($marker['bytes']) !== $target['marker_sha256']
                || ($marker['document']['marker_id'] ?? null) !== ($target['marker_id'] ?? null)
                || ($marker['document']['artifact'] ?? null) !== $artifact
                || ($marker['document']['installation_storage_namespace'] ?? null)
                    !== ($authorization['installation_storage_namespace'] ?? null)
                || ($marker['document']['layout_generation'] ?? null) !== ($authorization['layout_generation'] ?? null)) {
                throw new RuntimeException('BOOTSTRAP_TARGET_MARKER_INVALID');
            }
            $rootHash = self::contentRoot($root);
            if (!hash_equals((string) $target['expected_content_root'], $rootHash)) {
                throw new RuntimeException('BOOTSTRAP_TARGET_CONTENT_INVALID');
            }
            $verified[$artifact] = $rootHash;
        }

        return $verified;
    }

    /** @param array<string,mixed> $authorization @param array<string,mixed> $public */
    private function verifyAuthorization(array $authorization, string $raw, array $public): void
    {
        $required = [
            'schema_version', 'purpose', 'key_id', 'installation_storage_namespace', 'migration_id',
            'operation_id', 'layout_generation', 'issued_authority_revision', 'retention_receipt_sha256',
            'composite_receipt_sha256', 'frozen_manifest_sha256', 'target_policy_sha256',
            'local_setting_intent_sha256', 'targets', 'issued_at', 'signature',
        ];
        if (array_keys($authorization) !== $required || ($authorization['schema_version'] ?? null) !== 1
            || ($authorization['purpose'] ?? null) !== 'bootstrap_target_finalize'
            || array_keys($public) !== ['schema_version', 'key_id', 'public_key']
            || ($public['schema_version'] ?? null) !== 1
            || ($authorization['key_id'] ?? null) !== ($public['key_id'] ?? null)
            || !self::isHash($authorization['key_id'] ?? null)
            || !self::isHash($authorization['retention_receipt_sha256'] ?? null)
            || !self::isHash($authorization['composite_receipt_sha256'] ?? null)
            || !self::isHash($authorization['frozen_manifest_sha256'] ?? null)
            || !self::isHash($authorization['target_policy_sha256'] ?? null)
            || !self::isHash($authorization['local_setting_intent_sha256'] ?? null)
            || preg_match('/^mbs_[a-z0-9][a-z0-9_-]{0,59}$/D', (string) ($authorization['installation_storage_namespace'] ?? '')) !== 1
            || !is_int($authorization['layout_generation'] ?? null) || $authorization['layout_generation'] <= 0
            || !is_int($authorization['issued_authority_revision'] ?? null) || $authorization['issued_authority_revision'] <= 0
            || !is_int($authorization['issued_at'] ?? null) || $authorization['issued_at'] <= 0
            || !is_string($authorization['operation_id'] ?? null)
            || !is_string($authorization['migration_id'] ?? null)
            || $authorization['operation_id'] !== $authorization['migration_id']
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $authorization['operation_id']) !== 1) {
            throw new RuntimeException('BOOTSTRAP_TARGET_AUTHORITY_INVALID');
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('BOOTSTRAP_TARGET_SIGNATURE_UNAVAILABLE');
        }
        $publicKey = base64_decode((string) ($public['public_key'] ?? ''), true);
        $signature = base64_decode((string) ($authorization['signature'] ?? ''), true);
        if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || self::hashBytes($publicKey) !== ($public['key_id'] ?? null)) {
            throw new RuntimeException('BOOTSTRAP_TARGET_AUTHORITY_INVALID');
        }
        $unsigned = $authorization;
        unset($unsigned['signature']);
        if (!sodium_crypto_sign_verify_detached($signature, self::canonicalJson($unsigned), $publicKey)
            || self::canonicalJson($authorization) !== $raw) {
            throw new RuntimeException('BOOTSTRAP_TARGET_SIGNATURE_INVALID');
        }
    }

    /** @param array<string,mixed> $authorization @return array<string,mixed> */
    private function loadOrCreateLocalSettingIntent(
        string $targetDirectory,
        array $authorization,
        string $configuredRoot,
        string $targetAuthorizationSha256,
    ): array {
        $path = $targetDirectory . '/local-setting-intent.json';
        if (is_file($path) && !is_link($path)) {
            $existing = $this->readCanonicalAuthority($path, 0640)['document'];
            $this->assertLocalSettingIntent($existing, $authorization, $targetAuthorizationSha256);
            return $existing;
        }
        $expectedOldValueSha256 = self::hashString($configuredRoot);
        $intentBinding = [
            'schema_version' => 1,
            'purpose' => 'storage_bootstrap_local_setting_intent',
            'operation_id' => $authorization['operation_id'],
            'retention_receipt_sha256' => $authorization['retention_receipt_sha256'],
            'expected_old_value_sha256' => $expectedOldValueSha256,
            'canonical_value' => LocalUploadRootPolicy::CANONICAL_ROOT,
        ];
        $intentSha256 = self::hashBytes(self::canonicalJson($intentBinding));
        if (!hash_equals((string) $authorization['local_setting_intent_sha256'], $intentSha256)) {
            throw new RuntimeException('BOOTSTRAP_LOCAL_UPLOAD_INTENT_INVALID');
        }
        $intent = $intentBinding + [
            'local_setting_intent_sha256' => $intentSha256,
            'target_authorization_sha256' => $targetAuthorizationSha256,
        ];
        $this->publishImmutable($path, self::canonicalJson($intent));

        return $intent;
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $authorization */
    private function assertLocalSettingIntent(
        array $intent,
        array $authorization,
        string $targetAuthorizationSha256,
    ): void {
        $binding = [
            'schema_version' => $intent['schema_version'] ?? null,
            'purpose' => $intent['purpose'] ?? null,
            'operation_id' => $intent['operation_id'] ?? null,
            'retention_receipt_sha256' => $intent['retention_receipt_sha256'] ?? null,
            'expected_old_value_sha256' => $intent['expected_old_value_sha256'] ?? null,
            'canonical_value' => $intent['canonical_value'] ?? null,
        ];
        $computed = self::hashBytes(self::canonicalJson($binding));
        if (array_keys($intent) !== [
            'schema_version', 'purpose', 'operation_id', 'retention_receipt_sha256',
            'expected_old_value_sha256', 'canonical_value', 'local_setting_intent_sha256',
            'target_authorization_sha256',
        ] || !self::isHash($intent['expected_old_value_sha256'] ?? null)
            || !hash_equals((string) ($intent['local_setting_intent_sha256'] ?? ''), $computed)
            || !hash_equals((string) $authorization['local_setting_intent_sha256'], $computed)
            || ($intent['operation_id'] ?? null) !== ($authorization['operation_id'] ?? null)
            || ($intent['retention_receipt_sha256'] ?? null) !== ($authorization['retention_receipt_sha256'] ?? null)
            || ($intent['canonical_value'] ?? null) !== LocalUploadRootPolicy::CANONICAL_ROOT
            || ($intent['target_authorization_sha256'] ?? null) !== $targetAuthorizationSha256) {
            throw new RuntimeException('BOOTSTRAP_LOCAL_UPLOAD_INTENT_INVALID');
        }
    }

    private function requireTargetDirectory(): string
    {
        $path = $this->resultRoot . '/target';
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || is_link($path)
            || (($stat['mode'] & 07777) !== 02770 && ($stat['mode'] & 07777) !== 0770)) {
            throw new RuntimeException('BOOTSTRAP_TARGET_RESULT_ROOT_INVALID');
        }

        return $path;
    }

    /** @return array{document:array<string,mixed>,bytes:string} */
    private function readCanonicalAuthority(string $path, int $mode, bool $agentOwned = false): array
    {
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || (int) $stat['nlink'] !== 1
            || ($stat['mode'] & 0777) !== $mode || $stat['size'] <= 0 || $stat['size'] > self::MAX_AUTHORITY_BYTES) {
            throw new RuntimeException('BOOTSTRAP_AUTHORITY_FILE_INVALID');
        }
        if ($agentOwned) {
            if ((int) $stat['uid'] !== $this->agentUid || (int) $stat['gid'] !== $this->sharedGid) {
                throw new RuntimeException('BOOTSTRAP_AUTHORITY_FILE_INVALID');
            }
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || !str_ends_with($bytes, "\n") || substr_count($bytes, "\n") !== 1) {
            throw new RuntimeException('BOOTSTRAP_AUTHORITY_FILE_INVALID');
        }
        $document = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($document) || self::canonicalJson($document) !== $bytes) {
            throw new RuntimeException('BOOTSTRAP_AUTHORITY_CANONICAL_INVALID');
        }

        return ['document' => $document, 'bytes' => $bytes];
    }

    /** @return resource */
    private function acquireFinalizeLock(string $targetDirectory)
    {
        $path = $targetDirectory . '/.finalize.lock';
        $handle = null;
        if (!file_exists($path) && !is_link($path)) {
            $previousUmask = umask(0137);
            try {
                $handle = @fopen($path, 'x+b');
            } finally {
                umask($previousUmask);
            }
        }
        if (!is_resource($handle)) {
            $stat = @lstat($path);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
                || (int) $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0640
                || (int) $stat['uid'] !== posix_geteuid() || (int) $stat['gid'] !== $this->sharedGid) {
                throw new RuntimeException('BOOTSTRAP_FINALIZE_LOCK_INVALID');
            }
            $handle = @fopen($path, 'c+b');
        }
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('BOOTSTRAP_FINALIZE_LOCK_FAILED');
        }
        $stat = @fstat($handle);
        $pathStat = @lstat($path);
        if (!is_array($stat) || !is_array($pathStat)
            || (int) $stat['dev'] !== (int) $pathStat['dev']
            || (int) $stat['ino'] !== (int) $pathStat['ino']
            || ($stat['mode'] & 0170000) !== 0100000 || (int) $stat['nlink'] !== 1
            || ($stat['mode'] & 0777) !== 0640 || (int) $stat['uid'] !== posix_geteuid()
            || (int) $stat['gid'] !== $this->sharedGid) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException('BOOTSTRAP_FINALIZE_LOCK_INVALID');
        }

        return $handle;
    }

    private function cleanupInterruptedPublications(string $targetDirectory): void
    {
        $entries = @scandir($targetDirectory);
        if (!is_array($entries)) {
            throw new RuntimeException('BOOTSTRAP_RESULT_RECOVERY_FAILED');
        }
        foreach ($entries as $entry) {
            if (!preg_match(
                '/^\.(?:local-setting-intent\.json|local-setting\.json|confirmation\.json)\.[0-9a-f]{24}\.tmp$/D',
                $entry,
            )) {
                continue;
            }
            $path = $targetDirectory . '/' . $entry;
            $stat = @lstat($path);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
                || (int) $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0640
                || (int) $stat['uid'] !== posix_geteuid() || (int) $stat['gid'] !== $this->sharedGid
                || (int) $stat['size'] < 0 || (int) $stat['size'] > self::MAX_AUTHORITY_BYTES
                || !@unlink($path)) {
                throw new RuntimeException('BOOTSTRAP_RESULT_RECOVERY_FAILED');
            }
        }
    }

    private function publishImmutable(string $path, string $bytes): void
    {
        if (is_file($path) && !is_link($path)) {
            $stat = @lstat($path);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
                || (int) $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0640
                || $stat['size'] < 0 || $stat['size'] > self::MAX_AUTHORITY_BYTES) {
                throw new RuntimeException('BOOTSTRAP_RESULT_CONFLICT');
            }
            $existing = file_get_contents($path);
            if (is_string($existing) && hash_equals($existing, $bytes)) {
                return;
            }
            throw new RuntimeException('BOOTSTRAP_RESULT_CONFLICT');
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('BOOTSTRAP_RESULT_CONFLICT');
        }
        $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(12)) . '.tmp';
        $previousUmask = umask(0137);
        try {
            $handle = @fopen($temporary, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new RuntimeException('BOOTSTRAP_RESULT_PUBLISH_FAILED');
        }
        try {
            if (!chmod($temporary, 0640)) {
                throw new RuntimeException('BOOTSTRAP_RESULT_PUBLISH_FAILED');
            }
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('BOOTSTRAP_RESULT_PUBLISH_FAILED');
                }
                $offset += $written;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('BOOTSTRAP_RESULT_PUBLISH_FAILED');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('BOOTSTRAP_RESULT_PUBLISH_FAILED');
        }
        $directory = @fopen(dirname($path), 'rb');
        if ($directory === false || !fsync($directory)) {
            throw new RuntimeException('BOOTSTRAP_RESULT_PUBLISH_FAILED');
        }
        fclose($directory);
    }

    /** @param array<mixed> $value */
    private static function canonicalJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    /** @param array<mixed> $value */
    private static function canonicalJsonLine(array $value): string
    {
        return self::canonicalJson($value);
    }

    private static function hashBytes(string $bytes): string
    {
        return 'sha256:' . hash('sha256', $bytes);
    }

    private static function hashString(string $value): string
    {
        return self::hashBytes(self::canonicalJson([$value]));
    }

    private static function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^sha256:[0-9a-f]{64}$/D', $value) === 1;
    }

    private function assertUuid(string $value): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw new RuntimeException('BOOTSTRAP_RETENTION_ID_INVALID');
        }
    }

    private static function assertRelativeEvidencePath(string $path): void
    {
        if ($path === '' || strlen($path) > 4096 || preg_match('//u', $path) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
            || str_contains('/' . str_replace(DIRECTORY_SEPARATOR, '/', $path) . '/', '/../')) {
            throw new RuntimeException('BOOTSTRAP_TARGET_ENTRY_INVALID');
        }
    }

    private static function requiredIdentityEnvironment(string $name): int
    {
        $value = getenv($name);
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1) {
            throw new RuntimeException('BOOTSTRAP_AUTHORITY_IDENTITY_INVALID');
        }

        return (int) $value;
    }
}
