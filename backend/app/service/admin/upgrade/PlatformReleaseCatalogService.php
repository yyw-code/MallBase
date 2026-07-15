<?php

declare(strict_types=1);

namespace app\service\admin\upgrade;

use Closure;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * 读取平台公开发布目录；仅做版本发现，不代替 Agent 的执行前兼容校验。
 */
final class PlatformReleaseCatalogService
{
    private const APP_CODE = 'mallbase';

    private const MAXIMUM_RESPONSE_BYTES = 262_144;

    /** @var Closure(string):array{status:int,body:string} */
    private readonly Closure $requester;

    private readonly string $platformOrigin;

    public function __construct(
        ?string $platformOrigin = null,
        ?Closure $requester = null,
        private readonly ?Closure $clock = null,
    ) {
        $origin = $platformOrigin ?? (string) config('agent.platform_origin', '');
        $this->platformOrigin = $this->normalizeOrigin($origin);
        $this->requester = $requester ?? fn(string $url): array => $this->request($url);
    }

    /**
     * @return array{
     *   checked_at:int,
     *   current_version:string,
     *   releases:list<array{
     *     version:string,from_version:string,channel:string,summary:string,
     *     package_kind:string,released_at:string
     *   }>
     * }
     */
    public function getCatalog(string $currentVersion): array
    {
        if (!$this->validVersion($currentVersion)) {
            throw new RuntimeException('UPGRADE_CATALOG_ARGUMENT_INVALID');
        }
        $requester = $this->requester;
        try {
            $response = $requester(
                $this->platformOrigin . '/api/v1/releases?' . http_build_query(['app_code' => self::APP_CODE]),
            );
        } catch (Throwable) {
            $this->fail();
        }
        if (!is_array($response) || array_keys($response) !== ['status', 'body']
            || ($response['status'] ?? null) !== 200 || !is_string($response['body'] ?? null)) {
            $this->fail();
        }
        $raw = $response['body'];
        if ($raw === '' || strlen($raw) > self::MAXIMUM_RESPONSE_BYTES || !mb_check_encoding($raw, 'UTF-8')) {
            $this->fail();
        }
        try {
            $document = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail();
        }
        if (!$this->exactObject($document, ['data'])) {
            $this->fail();
        }
        $data = $document['data'];
        if (!$this->exactObject($data, ['app_code', 'delivery', 'releases'])
            || $data['app_code'] !== self::APP_CODE || $data['delivery'] !== 'agent_authenticated'
            || !is_array($data['releases']) || !array_is_list($data['releases'])
            || count($data['releases']) > 50) {
            $this->fail();
        }

        $releases = [];
        $seenVersions = [];
        foreach ($data['releases'] as $release) {
            $candidate = $this->candidate($release, $currentVersion);
            if ($candidate === null) {
                continue;
            }
            if (isset($seenVersions[$candidate['version']])) {
                $this->fail();
            }
            $seenVersions[$candidate['version']] = true;
            $releases[] = $candidate;
        }
        usort(
            $releases,
            fn(array $left, array $right): int => -$this->compareVersions($left['version'], $right['version']),
        );

        return [
            'checked_at' => $this->clock === null ? time() : (int) ($this->clock)(),
            'current_version' => $currentVersion,
            'releases' => $releases,
        ];
    }

    /** @return array<string, string>|null */
    private function candidate(mixed $release, string $currentVersion): ?array
    {
        if (!$this->exactObject($release, [
            'version', 'channel', 'min_agent_version', 'release_notes', 'released_at', 'packages',
        ])) {
            $this->fail();
        }
        $version = $release['version'];
        $releasedAt = $release['released_at'];
        if (!$this->validVersion($version) || !$this->validVersion($release['min_agent_version'])
            || !is_string($release['channel']) || !is_string($releasedAt) || strlen($releasedAt) > 64
            || !is_array($release['release_notes']) || !array_is_list($release['release_notes'])
            || count($release['release_notes']) > 100
            || !is_array($release['packages']) || !array_is_list($release['packages'])
            || count($release['packages']) < 1 || count($release['packages']) > 100) {
            $this->fail();
        }
        try {
            new \DateTimeImmutable($releasedAt);
        } catch (Throwable) {
            $this->fail();
        }
        $notes = [];
        foreach ($release['release_notes'] as $note) {
            if (!is_string($note) || $note === '' || strlen($note) > 1024
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note) === 1) {
                $this->fail();
            }
            $notes[] = $note;
        }

        $directKinds = [];
        foreach ($release['packages'] as $package) {
            $kind = $this->directPackageKind($package, $currentVersion);
            if ($kind !== null) {
                $directKinds[$kind] = true;
            }
        }
        if ($release['channel'] !== 'stable' || $this->compareVersions($version, $currentVersion) <= 0
            || $directKinds === []) {
            return null;
        }
        $summary = implode('；', $notes);
        if (strlen($summary) > 4096) {
            $this->fail();
        }

        return [
            'version' => $version,
            'from_version' => $currentVersion,
            'channel' => 'stable',
            'summary' => $summary,
            'package_kind' => isset($directKinds['patch']) ? 'patch' : 'full',
            'released_at' => $releasedAt,
        ];
    }

    private function directPackageKind(mixed $package, string $currentVersion): ?string
    {
        if (!is_array($package) || array_is_list($package)) {
            $this->fail();
        }
        $required = [
            'from_version', 'package_kind', 'from_storage_layout_version', 'to_storage_layout_version',
            'signing_key_id', 'package_sha256', 'package_size_bytes',
        ];
        $allowed = [...$required, 'required_bootstrap_version'];
        $keys = array_keys($package);
        sort($keys);
        $sortedRequired = $required;
        sort($sortedRequired);
        $sortedAllowed = $allowed;
        sort($sortedAllowed);
        if ($keys !== $sortedRequired && $keys !== $sortedAllowed) {
            $this->fail();
        }
        $fromLayout = $package['from_storage_layout_version'];
        $toLayout = $package['to_storage_layout_version'];
        $bootstrap = $package['required_bootstrap_version'] ?? null;
        if (!$this->validVersion($package['from_version'])
            || !in_array($package['package_kind'], ['full', 'patch'], true)
            || !is_int($fromLayout) || $fromLayout < 0 || $fromLayout > 1_000_000
            || !is_int($toLayout) || $toLayout < $fromLayout || $toLayout > 1_000_000
            || !is_null($bootstrap) && !$this->validVersion($bootstrap)
            || !is_string($package['signing_key_id']) || $package['signing_key_id'] === ''
            || strlen($package['signing_key_id']) > 128
            || !is_string($package['package_sha256'])
            || preg_match('/^[0-9a-f]{64}$/D', $package['package_sha256']) !== 1
            || !is_int($package['package_size_bytes']) || $package['package_size_bytes'] < 1) {
            $this->fail();
        }
        if ($package['from_version'] !== $currentVersion || $fromLayout !== $toLayout || $bootstrap !== null) {
            return null;
        }

        return $package['package_kind'];
    }

    /** @param list<string> $fields */
    private function exactObject(mixed $value, array $fields): bool
    {
        if (!is_array($value) || array_is_list($value)) {
            return false;
        }
        $keys = array_keys($value);
        sort($keys);
        sort($fields);

        return $keys === $fields;
    }

    private function validVersion(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 64
            || preg_match('/^(0|[1-9][0-9]{0,18})\.(0|[1-9][0-9]{0,18})\.(0|[1-9][0-9]{0,18})$/D', $value) !== 1) {
            return false;
        }

        return true;
    }

    private function compareVersions(string $left, string $right): int
    {
        $leftParts = explode('.', $left);
        $rightParts = explode('.', $right);
        foreach ([0, 1, 2] as $index) {
            $length = strlen($leftParts[$index]) <=> strlen($rightParts[$index]);
            if ($length !== 0) {
                return $length;
            }
            $compared = strcmp($leftParts[$index], $rightParts[$index]);
            if ($compared !== 0) {
                return $compared <=> 0;
            }
        }

        return 0;
    }

    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['path']) && !in_array($parts['path'], ['', '/'], true)) {
            throw new RuntimeException('UPGRADE_CATALOG_UNAVAILABLE');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return 'https://' . $parts['host'] . $port;
    }

    /** @return array{status:int,body:string} */
    private function request(string $url): array
    {
        if (!function_exists('curl_init')) {
            $this->fail();
        }
        $handle = curl_init($url);
        if ($handle === false) {
            $this->fail();
        }
        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
            CURLOPT_TIMEOUT_MS => 8000,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($raw)) {
            $this->fail();
        }

        return ['status' => $status, 'body' => $raw];
    }

    private function fail(): never
    {
        throw new RuntimeException('UPGRADE_CATALOG_UNAVAILABLE');
    }
}
