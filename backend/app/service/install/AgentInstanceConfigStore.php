<?php

declare(strict_types=1);

namespace app\service\install;

use app\service\upgrade\UpgradeSharedFileStore;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * upgrade/config/instance.json 的唯一 PHP 状态机。
 *
 * 服务仅保存不可变配置；所有请求态和并发态均落在共享文件及固定锁中。
 */
final class AgentInstanceConfigStore implements AgentInstanceStateStore
{
    private const MAX_TIMESTAMP = 4_102_444_800;

    private const TOP_LEVEL_FIELDS = [
        'schema_version', 'revision',
        'instance_id', 'token', 'activation_secret', 'activation_generation',
        'activation_secret_expires_at', 'activation_state', 'disabled', 'components',
        'report', 'updated_at',
    ];

    private const LEGACY_TOP_LEVEL_FIELDS_V1 = [
        'schema_version', 'revision', 'platform_base_url', 'upgrade_namespace_id',
        'instance_id', 'token', 'activation_secret', 'activation_generation',
        'activation_secret_expires_at', 'activation_state', 'disabled', 'components',
        'report', 'updated_at',
    ];

    private const LEGACY_TOP_LEVEL_FIELDS_V2 = [
        'schema_version', 'revision', 'platform_base_url', 'upgrade_namespace_id',
        'instance_id', 'token', 'session_derivation_key', 'activation_secret', 'activation_generation',
        'activation_secret_expires_at', 'activation_state', 'disabled', 'components',
        'report', 'updated_at',
    ];

    private const REPORT_FIELDS = [
        'next_after', 'reservation_id', 'reservation_until', 'last_success_at',
        'last_error_code', 'last_error_at',
    ];

    private const COMPONENT_NAMES = [
        'backend_php', 'admin_web', 'uniapp', 'wechat_miniapp', 'queue', 'cron', 'agent',
    ];

    public function __construct(
        private readonly UpgradeSharedFileStore $files,
        private readonly int $activationProofLifetime,
        private readonly int $componentSeenThrottle,
        private readonly int $legacyLockTimeoutMilliseconds = 2000,
    ) {
        if (PHP_INT_SIZE !== 8 || $this->activationProofLifetime < 1 || $this->componentSeenThrottle < 1
            || $this->legacyLockTimeoutMilliseconds < 1) {
            $this->fail('INSTANCE_ARGUMENT_INVALID');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(): ?array
    {
        return $this->files->withInstanceLock(fn(): ?array => $this->loadUnlocked(true));
    }

    /**
     * @return array<string, mixed>
     */
    public function initializeFromLegacy(InstallLockService $legacy, int $now): array
    {
        $this->requireTimestamp($now);
        if ($this->activationProofLifetime > self::MAX_TIMESTAMP - $now) {
            $this->fail('INSTANCE_ARGUMENT_INVALID');
        }

        return $this->files->withInstanceLock(function () use ($legacy, $now): array {
            $current = $this->loadUnlocked();
            if ($current !== null) {
                return $current;
            }

            $legacyState = $this->readLegacyPlatformState($legacy);
            $identity = $this->legacyIdentity($legacyState);
            $components = $this->legacyComponents($legacyState);
            $disabled = is_bool($legacyState['disabled'] ?? null)
                ? $legacyState['disabled']
                : false;

            $instanceId = $identity['instance_id'] ?? $this->uuid();
            $generation = $this->uuid();
            $token = $identity['token'] ?? '';
            if ($token !== '') {
                $state = 'confirmed';
                $secret = '';
                $expiresAt = 0;
            } elseif (($identity['instance_id'] ?? '') !== '') {
                $state = 'recovery_required';
                $secret = '';
                $expiresAt = 0;
            } else {
                $state = 'activating';
                $secret = $this->activationSecret();
                $expiresAt = $now + $this->activationProofLifetime;
            }

            $instance = [
                'schema_version' => 1,
                'revision' => 1,
                'instance_id' => $instanceId,
                'token' => $token,
                'activation_secret' => $secret,
                'activation_generation' => $generation,
                'activation_secret_expires_at' => $expiresAt,
                'activation_state' => $state,
                'disabled' => $disabled,
                'components' => $components,
                'report' => $this->emptyReport(),
                'updated_at' => $now,
            ];
            $this->writeInstance($instance);

            return $instance;
        });
    }

    /**
     * @return array{reservation_id:string,reservation_revision:int,instance:array<string,mixed>}|null
     */
    public function reserveReportWindow(string $componentType, int $now, int $reservationSeconds): ?array
    {
        $this->requireTimestamp($now);
        if (!in_array($componentType, self::COMPONENT_NAMES, true)
            || $reservationSeconds < 1 || $reservationSeconds > self::MAX_TIMESTAMP - $now) {
            $this->fail('INSTANCE_ARGUMENT_INVALID');
        }

        return $this->files->withInstanceLock(function () use ($componentType, $now, $reservationSeconds): ?array {
            $instance = $this->requireInstance();
            if ($instance['activation_state'] !== 'confirmed' || $instance['disabled'] === true) {
                return null;
            }

            $lastSeen = $instance['components'][$componentType] ?? 0;
            $componentChanged = $now > $lastSeen
                && ($lastSeen === 0 || $now - $lastSeen >= $this->componentSeenThrottle);
            $reportDue = $instance['report']['next_after'] <= $now;
            $reservationAvailable = $instance['report']['reservation_id'] === ''
                || $instance['report']['reservation_until'] < $now;
            $reserve = $reportDue && $reservationAvailable;

            if (!$componentChanged && !$reserve) {
                return null;
            }
            $this->incrementRevision($instance, $now);
            if ($componentChanged) {
                $instance['components'][$componentType] = $now;
            }
            if (!$reserve) {
                $this->writeInstance($instance);

                return null;
            }

            $reservationId = $this->uuid();
            $instance['report']['reservation_id'] = $reservationId;
            $instance['report']['reservation_until'] = $now + $reservationSeconds;
            $this->writeInstance($instance);

            return [
                'reservation_id' => $reservationId,
                'reservation_revision' => $instance['revision'],
                'instance' => $instance,
            ];
        });
    }

    public function recordReportResult(
        string $reservationId,
        int $reservationRevision,
        bool $success,
        int $now,
        int $nextReportAfterSeconds,
        string $errorCode = '',
    ): bool {
        $this->requireTimestamp($now);
        if (!$this->validUuid($reservationId) || $reservationRevision < 1
            || $nextReportAfterSeconds < 1 || $nextReportAfterSeconds > self::MAX_TIMESTAMP - $now
            || ($success && $errorCode !== '')
            || (!$success && !$this->validErrorCode($errorCode))) {
            $this->fail('INSTANCE_ARGUMENT_INVALID');
        }

        return $this->files->withInstanceLock(function () use (
            $reservationId,
            $reservationRevision,
            $success,
            $now,
            $nextReportAfterSeconds,
            $errorCode,
        ): bool {
            $instance = $this->requireInstance();
            $report = $instance['report'];
            if ($report['reservation_id'] !== $reservationId
                || $instance['revision'] < $reservationRevision
                || $report['reservation_until'] === 0
                || $now > $report['reservation_until']) {
                return false;
            }

            $this->incrementRevision($instance, $now);
            $instance['report']['next_after'] = $now + $nextReportAfterSeconds;
            $instance['report']['reservation_id'] = '';
            $instance['report']['reservation_until'] = 0;
            if ($success) {
                $instance['report']['last_success_at'] = $now;
                $instance['report']['last_error_code'] = '';
                $instance['report']['last_error_at'] = 0;
            } else {
                $instance['report']['last_error_code'] = $errorCode;
                $instance['report']['last_error_at'] = $now;
            }
            $this->writeInstance($instance);

            return true;
        });
    }

    /** @return array<string, mixed> */
    public function storeActivationResponse(
        string $generation,
        int $expectedRevision,
        string $instanceId,
        string $token,
        int $now,
    ): array {
        $this->requireTimestamp($now);
        if (!$this->validUuid($generation) || $expectedRevision < 1
            || !$this->validUuid($instanceId) || $token === '' || !$this->validToken($token)) {
            $this->fail('INSTANCE_ARGUMENT_INVALID');
        }

        return $this->files->withInstanceLock(function () use (
            $generation,
            $expectedRevision,
            $instanceId,
            $token,
            $now,
        ): array {
            $instance = $this->requireInstance();
            if ($instance['activation_generation'] !== $generation || $instance['revision'] !== $expectedRevision) {
                $this->fail('INSTANCE_CAS_MISMATCH');
            }
            if ($instance['activation_state'] !== 'activating'
                || $instance['instance_id'] !== $instanceId
                || $instance['activation_secret_expires_at'] <= $now) {
                $this->fail('ACTIVATION_STATE_INVALID');
            }

            $this->incrementRevision($instance, $now);
            $instance['token'] = $token;
            $instance['activation_state'] = 'confirming';
            $this->writeInstance($instance);

            return $instance;
        });
    }

    /** @return array<string, mixed> */
    public function confirmActivation(string $generation, int $expectedRevision, int $now): array
    {
        $this->requireTimestamp($now);
        if (!$this->validUuid($generation) || $expectedRevision < 1) {
            $this->fail('INSTANCE_ARGUMENT_INVALID');
        }

        return $this->files->withInstanceLock(function () use ($generation, $expectedRevision, $now): array {
            $instance = $this->requireInstance();
            if ($instance['activation_generation'] !== $generation || $instance['revision'] !== $expectedRevision) {
                $this->fail('INSTANCE_CAS_MISMATCH');
            }
            if ($instance['activation_state'] !== 'confirming') {
                $this->fail('ACTIVATION_STATE_INVALID');
            }

            $this->incrementRevision($instance, $now);
            $instance['activation_state'] = 'confirmed';
            $instance['activation_secret'] = '';
            $instance['activation_secret_expires_at'] = 0;
            $this->writeInstance($instance);

            return $instance;
        });
    }

    /** @return array<string, mixed> */
    public function markExpiredActivationRecoveryRequired(int $now): array
    {
        $this->requireTimestamp($now);

        return $this->files->withInstanceLock(function () use ($now): array {
            $instance = $this->requireInstance();
            if ($instance['activation_state'] !== 'activating'
                || $instance['activation_secret_expires_at'] > $now) {
                return $instance;
            }

            $this->incrementRevision($instance, $now);
            $instance['activation_state'] = 'recovery_required';
            $instance['token'] = '';
            $instance['activation_secret'] = '';
            $instance['activation_secret_expires_at'] = 0;
            $this->writeInstance($instance);

            return $instance;
        });
    }

    /** @return array<string, mixed>|null */
    private function loadUnlocked(bool $convergeLegacy = false): ?array
    {
        $document = $this->files->readJson('instance');
        if ($document === null) {
            return null;
        }

        try {
            $raw = get_object_vars($document);
            $current = ($raw['schema_version'] ?? null) === 1
                && $this->hasExactFields($raw, self::TOP_LEVEL_FIELDS);
            $instance = $this->validateDocument($document);
            if ($convergeLegacy && !$current) {
                $this->writeInstance($instance);
            }

            return $instance;
        } catch (Throwable) {
            $this->fail('INSTANCE_INVALID');
        }
    }

    /** @return array<string, mixed> */
    private function requireInstance(): array
    {
        $instance = $this->loadUnlocked();
        if ($instance === null) {
            $this->fail('INSTANCE_NOT_INITIALIZED');
        }

        return $instance;
    }

    /** @return array<string, mixed> */
    private function validateDocument(object $document): array
    {
        $raw = get_object_vars($document);
        $schemaVersion = $raw['schema_version'] ?? null;
        $currentDocument = $schemaVersion === 1 && $this->hasExactFields($raw, self::TOP_LEVEL_FIELDS);
        $legacyV1Document = $schemaVersion === 1
            && $this->hasExactFields($raw, self::LEGACY_TOP_LEVEL_FIELDS_V1);
        $legacyV2Document = $schemaVersion === 2
            && $this->hasExactFields($raw, self::LEGACY_TOP_LEVEL_FIELDS_V2);
        if ((!$currentDocument && !$legacyV1Document && !$legacyV2Document)
            || !is_int($schemaVersion)
            || !is_int($raw['revision']) || $raw['revision'] < 1
            || !is_string($raw['instance_id']) || !$this->validUuid($raw['instance_id'])
            || !is_string($raw['token']) || !$this->validToken($raw['token'])
            || ($legacyV2Document
                && (!is_string($raw['session_derivation_key'])
                    || !$this->validBase64Url32($raw['session_derivation_key'])))
            || !is_string($raw['activation_secret']) || !$this->validActivationSecret($raw['activation_secret'])
            || !is_string($raw['activation_generation']) || !$this->validUuid($raw['activation_generation'])
            || !$this->isTimestamp($raw['activation_secret_expires_at'])
            || !is_string($raw['activation_state']) || !is_bool($raw['disabled'])
            || !$raw['components'] instanceof stdClass || !$raw['report'] instanceof stdClass
            || !$this->isTimestamp($raw['updated_at'])) {
            $this->fail('INSTANCE_INVALID');
        }

        $components = get_object_vars($raw['components']);
        if (count($components) > 16) {
            $this->fail('INSTANCE_INVALID');
        }
        foreach ($components as $name => $timestamp) {
            if (!in_array($name, self::COMPONENT_NAMES, true) || !$this->isTimestamp($timestamp)) {
                $this->fail('INSTANCE_INVALID');
            }
        }

        $report = get_object_vars($raw['report']);
        if (!$this->hasExactFields($report, self::REPORT_FIELDS)
            || !$this->isTimestamp($report['next_after'])
            || !is_string($report['reservation_id'])
            || !$this->isTimestamp($report['reservation_until'])
            || !$this->isTimestamp($report['last_success_at'])
            || !is_string($report['last_error_code'])
            || ($report['last_error_code'] !== '' && !$this->validErrorCode($report['last_error_code']))
            || !$this->isTimestamp($report['last_error_at'])
            || (($report['reservation_id'] === '') !== ($report['reservation_until'] === 0))
            || ($report['reservation_id'] !== '' && !$this->validUuid($report['reservation_id']))) {
            $this->fail('INSTANCE_INVALID');
        }

        $instance = [
            'schema_version' => 1,
            'revision' => $raw['revision'],
            'instance_id' => $raw['instance_id'],
            'token' => $raw['token'],
        ];
        $instance += [
            'activation_secret' => $raw['activation_secret'],
            'activation_generation' => $raw['activation_generation'],
            'activation_secret_expires_at' => $raw['activation_secret_expires_at'],
            'activation_state' => $raw['activation_state'],
            'disabled' => $raw['disabled'],
            'components' => $components,
            'report' => $report,
            'updated_at' => $raw['updated_at'],
        ];
        if (!$this->validActivationInvariant($instance)) {
            $this->fail('INSTANCE_INVALID');
        }

        return $instance;
    }

    /** @param array<string, mixed> $instance */
    private function writeInstance(array $instance): void
    {
        $document = [
            'schema_version' => $instance['schema_version'],
            'revision' => $instance['revision'],
            'instance_id' => $instance['instance_id'],
            'token' => $instance['token'],
            'activation_secret' => $instance['activation_secret'],
            'activation_generation' => $instance['activation_generation'],
            'activation_secret_expires_at' => $instance['activation_secret_expires_at'],
            'activation_state' => $instance['activation_state'],
            'disabled' => $instance['disabled'],
            'components' => (object) $instance['components'],
            'report' => (object) [
                'next_after' => $instance['report']['next_after'],
                'reservation_id' => $instance['report']['reservation_id'],
                'reservation_until' => $instance['report']['reservation_until'],
                'last_success_at' => $instance['report']['last_success_at'],
                'last_error_code' => $instance['report']['last_error_code'],
                'last_error_at' => $instance['report']['last_error_at'],
            ],
            'updated_at' => $instance['updated_at'],
        ];
        $this->files->writeJson('instance', (object) $document);
    }

    /** @return array<string, mixed> */
    private function readLegacyPlatformState(InstallLockService $legacy): array
    {
        $path = $legacy->lockFilePath();
        if (!file_exists($path)) {
            return [];
        }
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            $this->fail('LEGACY_PLATFORM_STATE_INVALID');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            $this->fail('LEGACY_PLATFORM_STATE_INVALID');
        }
        $acquired = false;
        try {
            $deadline = hrtime(true) + $this->legacyLockTimeoutMilliseconds * 1_000_000;
            do {
                $acquired = @flock($handle, LOCK_SH | LOCK_NB);
                if ($acquired) {
                    break;
                }
                usleep(5_000);
            } while (hrtime(true) < $deadline);
            if (!$acquired) {
                $this->fail('LEGACY_PLATFORM_STATE_INVALID');
            }

            $descriptorStat = @fstat($handle);
            $lockedNameStat = @lstat($path);
            if (!$this->validLegacyRegularFileStat($descriptorStat)
                || !$this->validLegacyRegularFileStat($lockedNameStat)
                || $descriptorStat['dev'] !== $stat['dev'] || $descriptorStat['ino'] !== $stat['ino']
                || $descriptorStat['dev'] !== $lockedNameStat['dev'] || $descriptorStat['ino'] !== $lockedNameStat['ino']) {
                $this->fail('LEGACY_PLATFORM_STATE_INVALID');
            }

            $raw = stream_get_contents($handle, 65537);
            if (!is_string($raw) || $raw === '' || strlen($raw) > 65536 || !mb_check_encoding($raw, 'UTF-8')) {
                $this->fail('LEGACY_PLATFORM_STATE_INVALID');
            }
            try {
                $this->assertLegacyJsonHasUniqueKeys($raw);
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $this->fail('LEGACY_PLATFORM_STATE_INVALID');
            }
            if (!is_array($decoded)) {
                $this->fail('LEGACY_PLATFORM_STATE_INVALID');
            }
            if (!array_key_exists('platform', $decoded)) {
                return [];
            }
            if (!is_array($decoded['platform'])) {
                $this->fail('LEGACY_PLATFORM_STATE_INVALID');
            }

            return $decoded['platform'];
        } finally {
            if ($acquired) {
                @flock($handle, LOCK_UN);
            }
            @fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $legacyState
     * @return array{instance_id?:string,token?:string}
     */
    private function legacyIdentity(array $legacyState): array
    {
        $hasInstance = array_key_exists('instance_id', $legacyState) && $legacyState['instance_id'] !== '';
        $hasToken = array_key_exists('token', $legacyState) && $legacyState['token'] !== '';
        if (array_key_exists('instance_id', $legacyState) && !is_string($legacyState['instance_id'])
            || array_key_exists('token', $legacyState) && !is_string($legacyState['token'])
            || $hasInstance && !$this->validUuid($legacyState['instance_id'])
            || $hasToken && !$this->validToken($legacyState['token'])
            || $hasToken && !$hasInstance) {
            $this->fail('LEGACY_PLATFORM_STATE_INVALID');
        }
        if (!$hasInstance) {
            return [];
        }

        $identity = ['instance_id' => $legacyState['instance_id']];
        if ($hasToken) {
            $identity['token'] = $legacyState['token'];
        }

        return $identity;
    }

    private function validLegacyRegularFileStat(mixed $stat): bool
    {
        return is_array($stat)
            && isset($stat['mode'], $stat['nlink'], $stat['dev'], $stat['ino'])
            && ($stat['mode'] & 0170000) === 0100000
            && $stat['nlink'] === 1;
    }

    private function assertLegacyJsonHasUniqueKeys(string $json): void
    {
        $offset = 0;
        $this->scanLegacyJsonValue($json, $offset, 0);
        $this->skipLegacyJsonWhitespace($json, $offset);
        if ($offset !== strlen($json)) {
            throw new RuntimeException('invalid legacy json');
        }
    }

    private function scanLegacyJsonValue(string $json, int &$offset, int $depth): void
    {
        if ($depth > 32) {
            throw new RuntimeException('invalid legacy json');
        }
        $this->skipLegacyJsonWhitespace($json, $offset);
        $character = $json[$offset] ?? '';
        if ($character === '{') {
            $this->scanLegacyJsonObject($json, $offset, $depth + 1);

            return;
        }
        if ($character === '[') {
            $this->scanLegacyJsonArray($json, $offset, $depth + 1);

            return;
        }
        if ($character === '"') {
            $this->scanLegacyJsonString($json, $offset);

            return;
        }
        foreach (['true', 'false', 'null'] as $literal) {
            if (substr($json, $offset, strlen($literal)) === $literal) {
                $offset += strlen($literal);

                return;
            }
        }
        if (preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', substr($json, $offset), $match) !== 1) {
            throw new RuntimeException('invalid legacy json');
        }
        $offset += strlen($match[0]);
    }

    private function scanLegacyJsonObject(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $seen = [];
        $this->skipLegacyJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') === '}') {
            $offset++;

            return;
        }
        while (true) {
            $this->skipLegacyJsonWhitespace($json, $offset);
            $key = $this->scanLegacyJsonString($json, $offset);
            if (array_key_exists($key, $seen)) {
                throw new RuntimeException('duplicate legacy json key');
            }
            $seen[$key] = true;
            $this->skipLegacyJsonWhitespace($json, $offset);
            if (($json[$offset] ?? '') !== ':') {
                throw new RuntimeException('invalid legacy json');
            }
            $offset++;
            $this->scanLegacyJsonValue($json, $offset, $depth);
            $this->skipLegacyJsonWhitespace($json, $offset);
            $delimiter = $json[$offset] ?? '';
            if ($delimiter === '}') {
                $offset++;

                return;
            }
            if ($delimiter !== ',') {
                throw new RuntimeException('invalid legacy json');
            }
            $offset++;
        }
    }

    private function scanLegacyJsonArray(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $this->skipLegacyJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') === ']') {
            $offset++;

            return;
        }
        while (true) {
            $this->scanLegacyJsonValue($json, $offset, $depth);
            $this->skipLegacyJsonWhitespace($json, $offset);
            $delimiter = $json[$offset] ?? '';
            if ($delimiter === ']') {
                $offset++;

                return;
            }
            if ($delimiter !== ',') {
                throw new RuntimeException('invalid legacy json');
            }
            $offset++;
        }
    }

    private function scanLegacyJsonString(string $json, int &$offset): string
    {
        if (($json[$offset] ?? '') !== '"') {
            throw new RuntimeException('invalid legacy json');
        }
        $start = $offset++;
        $length = strlen($json);
        while ($offset < $length) {
            $character = $json[$offset++];
            if ($character === '\\') {
                if ($offset >= $length) {
                    throw new RuntimeException('invalid legacy json');
                }
                $offset++;
                continue;
            }
            if ($character === '"') {
                $encoded = substr($json, $start, $offset - $start);
                $decoded = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
                if (!is_string($decoded)) {
                    throw new RuntimeException('invalid legacy json');
                }

                return $decoded;
            }
        }
        throw new RuntimeException('invalid legacy json');
    }

    private function skipLegacyJsonWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }

    /** @param array<string, mixed> $legacyState @return array<string, int> */
    private function legacyComponents(array $legacyState): array
    {
        $components = [];
        if (!is_array($legacyState['components'] ?? null)) {
            return $components;
        }
        foreach ($legacyState['components'] as $name => $timestamp) {
            if (is_string($name) && in_array($name, self::COMPONENT_NAMES, true) && $this->isTimestamp($timestamp)) {
                $components[$name] = $timestamp;
            }
        }

        return $components;
    }

    /** @param array<string, mixed> $instance */
    private function incrementRevision(array &$instance, int $now): void
    {
        if ($instance['revision'] >= PHP_INT_MAX) {
            $this->fail('INSTANCE_REVISION_EXHAUSTED');
        }
        $instance['revision']++;
        $instance['updated_at'] = $now;
    }

    /** @return array<string, int|string> */
    private function emptyReport(): array
    {
        return [
            'next_after' => 0,
            'reservation_id' => '',
            'reservation_until' => 0,
            'last_success_at' => 0,
            'last_error_code' => '',
            'last_error_at' => 0,
        ];
    }

    /** @param array<string, mixed> $fields @param list<string> $expected */
    private function hasExactFields(array $fields, array $expected): bool
    {
        $actual = array_keys($fields);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    /** @param array<string, mixed> $instance */
    private function validActivationInvariant(array $instance): bool
    {
        return match ($instance['activation_state']) {
            'activating' => $instance['token'] === '' && $instance['activation_secret'] !== ''
                && $instance['activation_secret_expires_at'] > 0,
            'confirming' => $instance['token'] !== '' && $instance['activation_secret'] !== ''
                && $instance['activation_secret_expires_at'] > 0,
            'confirmed' => $instance['token'] !== '' && $instance['activation_secret'] === ''
                && $instance['activation_secret_expires_at'] === 0,
            'recovery_required' => $instance['token'] === '' && $instance['activation_secret'] === ''
                && $instance['activation_secret_expires_at'] === 0,
            default => false,
        };
    }

    private function validUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function validToken(string $value): bool
    {
        if (strlen($value) > 4096) {
            return false;
        }
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $byte = ord($value[$index]);
            if ($byte < 0x21 || $byte > 0x7e) {
                return false;
            }
        }

        return true;
    }

    private function validActivationSecret(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        return $this->validBase64Url32($value);
    }

    private function validBase64Url32(string $value): bool
    {
        if (strlen($value) !== 43 || preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) !== 1) {
            return false;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . '=', true);

        return is_string($decoded) && strlen($decoded) === 32
            && rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') === $value;
    }

    private function validErrorCode(string $value): bool
    {
        return preg_match('/^[A-Z0-9_]{1,64}$/D', $value) === 1;
    }

    private function isTimestamp(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_TIMESTAMP;
    }

    private function requireTimestamp(int $value): void
    {
        if (!$this->isTimestamp($value)) {
            $this->fail('INSTANCE_ARGUMENT_INVALID');
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function activationSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function fail(string $code): never
    {
        throw new RuntimeException($code);
    }
}
