<?php

declare(strict_types=1);

namespace app\service\install;

use Closure;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * 从本地版本文件和共享实例状态生成固定 Agent stdin 契约。
 */
final class AgentHeartbeatPayloadFactory
{
    private const ACTIVE_COMPONENT_WINDOW = 1_296_000;
    private const COMPONENTS = [
        'backend_php', 'admin_web', 'uniapp', 'wechat_miniapp', 'queue', 'cron', 'agent',
    ];

    /** @var Closure():array<string,mixed> */
    private readonly Closure $environmentProvider;

    public function __construct(
        private readonly string $versionPath,
        ?Closure $environmentProvider = null,
    ) {
        $this->environmentProvider = $environmentProvider ?? $this->environment(...);
    }

    public static function fromProjectRoot(): self
    {
        $backendRoot = rtrim((string) root_path(), DIRECTORY_SEPARATOR);

        return new self(dirname($backendRoot) . DIRECTORY_SEPARATOR . '.version');
    }

    /**
     * @param array<string, mixed> $instance
     * @return array<string, mixed>
     */
    public function create(array $instance, string $componentType, int $now): array
    {
        if (!in_array($componentType, self::COMPONENTS, true) || $now < 0 || $now > 4_102_444_800) {
            $this->fail();
        }
        $origin = $instance['platform_base_url'] ?? null;
        $instanceId = $instance['instance_id'] ?? null;
        $token = $instance['token'] ?? null;
        $secret = $instance['activation_secret'] ?? null;
        $components = $instance['components'] ?? null;
        if (!is_string($origin) || !$this->validOrigin($origin)
            || !is_string($instanceId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $instanceId) !== 1
            || !is_string($token) || !$this->validSecret($token, true)
            || !is_string($secret) || !$this->validSecret($secret, true)
            || !is_array($components)) {
            $this->fail();
        }

        $release = $this->releaseInfo();
        $provider = $this->environmentProvider;
        $environment = $provider();
        if (!is_array($environment)) {
            $this->fail();
        }
        try {
            json_encode($environment, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail();
        }

        $active = [];
        foreach ($components as $type => $lastSeenAt) {
            if (!is_string($type) || !in_array($type, self::COMPONENTS, true)
                || !is_int($lastSeenAt) || $lastSeenAt < 0 || $lastSeenAt > $now
                || $now - $lastSeenAt > self::ACTIVE_COMPONENT_WINDOW) {
                continue;
            }
            $active[$type] = ['type' => $type, 'version' => $release['version']];
        }
        if ($token !== '' && !isset($active[$componentType])) {
            $active[$componentType] = ['type' => $componentType, 'version' => $release['version']];
        }
        ksort($active, SORT_STRING);

        return [
            'platform_base_url' => $origin,
            'instance_id' => $instanceId,
            'token' => $token,
            'activation_secret' => $secret,
            'app_version' => $release,
            'environment' => $environment,
            'components' => array_values($active),
        ];
    }

    /** @return array<string, mixed> */
    private function releaseInfo(): array
    {
        $stat = @lstat($this->versionPath);
        if (!$this->validVersionStat($stat)) {
            $this->fail();
        }
        $handle = @fopen($this->versionPath, 'rb');
        if (!is_resource($handle)) {
            $this->fail();
        }
        try {
            $opened = fstat($handle);
            if (!$this->validVersionStat($opened)) {
                $this->fail();
            }
            $this->assertSameFileIdentity($stat, $opened);
            $raw = stream_get_contents($handle, 64 * 1024 + 1);
            if (!is_string($raw) || $raw === '' || strlen($raw) > 64 * 1024 || !feof($handle)) {
                $this->fail();
            }
            $after = fstat($handle);
            $namedAfter = @lstat($this->versionPath);
            if (!$this->validVersionStat($after) || !$this->validVersionStat($namedAfter)) {
                $this->fail();
            }
            $this->assertSameFileIdentity($opened, $after);
            $this->assertSameFileIdentity($opened, $namedAfter);
        } finally {
            fclose($handle);
        }
        try {
            $this->assertStrictJson($raw);
            $document = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->fail();
        }
        if (!is_array($document) || array_is_list($document)) {
            $this->fail();
        }
        foreach (array_keys($document) as $field) {
            if (!in_array($field, ['version', 'released_at', 'notes'], true)) {
                $this->fail();
            }
        }
        $version = $document['version'] ?? null;
        if (!is_string($version) || preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $version) !== 1
            || strlen($version) > 64) {
            $this->fail();
        }
        $result = ['version' => $version];
        if (array_key_exists('released_at', $document)) {
            if (!is_string($document['released_at']) || strlen($document['released_at']) > 128
                || preg_match('/[\x00-\x1f\x7f]/', $document['released_at']) === 1) {
                $this->fail();
            }
            $result['released_at'] = $document['released_at'];
        }
        if (array_key_exists('notes', $document)) {
            if (!is_array($document['notes']) || count($document['notes']) > 100) {
                $this->fail();
            }
            foreach ($document['notes'] as $note) {
                if (!is_string($note) || strlen($note) > 1024 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note) === 1) {
                    $this->fail();
                }
            }
            $result['notes'] = array_values($document['notes']);
        }

        return $result;
    }

    /** @param array<string|int,mixed>|false $stat */
    private function validVersionStat(array|false $stat): bool
    {
        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000
            && ($stat['nlink'] ?? 0) === 1 && ($stat['size'] ?? 0) >= 1 && $stat['size'] <= 64 * 1024;
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private function assertSameFileIdentity(array $left, array $right): void
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size'] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                $this->fail();
            }
        }
    }

    private function validOrigin(string $origin): bool
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || ($parts['path'] ?? '') !== '' || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }
        $host = strtolower((string) $parts['host']);

        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function assertStrictJson(string $json): void
    {
        if ($json === '' || !mb_check_encoding($json, 'UTF-8')) {
            throw new RuntimeException('invalid json');
        }
        $offset = 0;
        $this->scanJsonValue($json, $offset, 0);
        $this->skipJsonWhitespace($json, $offset);
        if ($offset !== strlen($json)) {
            throw new RuntimeException('invalid json');
        }
    }

    private function scanJsonValue(string $json, int &$offset, int $depth): void
    {
        if ($depth > 16) {
            throw new RuntimeException('invalid json');
        }
        $this->skipJsonWhitespace($json, $offset);
        $character = $json[$offset] ?? '';
        if ($character === '{') {
            $this->scanJsonObject($json, $offset, $depth + 1);

            return;
        }
        if ($character === '[') {
            $this->scanJsonArray($json, $offset, $depth + 1);

            return;
        }
        if ($character === '"') {
            $this->scanJsonString($json, $offset);

            return;
        }
        foreach (['true', 'false', 'null'] as $literal) {
            if (substr($json, $offset, strlen($literal)) === $literal) {
                $offset += strlen($literal);

                return;
            }
        }
        if (preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', substr($json, $offset), $match) !== 1) {
            throw new RuntimeException('invalid json');
        }
        $offset += strlen($match[0]);
    }

    private function scanJsonObject(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $seen = [];
        $this->skipJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') === '}') {
            $offset++;

            return;
        }
        while (true) {
            $this->skipJsonWhitespace($json, $offset);
            $key = $this->scanJsonString($json, $offset);
            if (array_key_exists($key, $seen)) {
                throw new RuntimeException('duplicate json key');
            }
            $seen[$key] = true;
            $this->skipJsonWhitespace($json, $offset);
            if (($json[$offset] ?? '') !== ':') {
                throw new RuntimeException('invalid json');
            }
            $offset++;
            $this->scanJsonValue($json, $offset, $depth);
            $this->skipJsonWhitespace($json, $offset);
            $delimiter = $json[$offset] ?? '';
            if ($delimiter === '}') {
                $offset++;

                return;
            }
            if ($delimiter !== ',') {
                throw new RuntimeException('invalid json');
            }
            $offset++;
        }
    }

    private function scanJsonArray(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $this->skipJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') === ']') {
            $offset++;

            return;
        }
        while (true) {
            $this->scanJsonValue($json, $offset, $depth);
            $this->skipJsonWhitespace($json, $offset);
            $delimiter = $json[$offset] ?? '';
            if ($delimiter === ']') {
                $offset++;

                return;
            }
            if ($delimiter !== ',') {
                throw new RuntimeException('invalid json');
            }
            $offset++;
        }
    }

    private function scanJsonString(string $json, int &$offset): string
    {
        if (($json[$offset] ?? '') !== '"') {
            throw new RuntimeException('invalid json');
        }
        $start = $offset++;
        $length = strlen($json);
        while ($offset < $length) {
            $character = $json[$offset++];
            if ($character === '\\') {
                if ($offset >= $length) {
                    throw new RuntimeException('invalid json');
                }
                $offset++;
                continue;
            }
            if ($character === '"') {
                $encoded = substr($json, $start, $offset - $start);
                $decoded = json_decode($encoded, false, 16, JSON_THROW_ON_ERROR);
                if (!is_string($decoded)) {
                    throw new RuntimeException('invalid json');
                }

                return $decoded;
            }
        }
        throw new RuntimeException('invalid json');
    }

    private function skipJsonWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }

    private function validSecret(string $value, bool $allowEmpty): bool
    {
        if ($value === '') {
            return $allowEmpty;
        }

        return strlen($value) <= 4096 && trim($value) === $value
            && preg_match('/[\x00-\x20\x7f]/', $value) !== 1;
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'db_driver' => (string) config('database.default', 'mysql'),
            'os' => PHP_OS_FAMILY,
            'arch' => (string) (php_uname('m') ?: ''),
            'timezone' => date_default_timezone_get(),
        ];
    }

    private function fail(): never
    {
        throw new RuntimeException('AGENT_PAYLOAD_INVALID');
    }
}
