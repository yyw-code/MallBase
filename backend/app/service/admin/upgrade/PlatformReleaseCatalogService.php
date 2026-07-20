<?php

declare(strict_types=1);

namespace app\service\admin\upgrade;

use app\service\install\AgentBinaryTrustValidator;
use app\service\install\AgentProcessRunner;
use app\service\upgrade\UpgradeStrictJsonDecoder;
use Closure;
use RuntimeException;
use Throwable;

/**
 * 通过固定 Agent 子命令读取公开发布目录；仅做版本发现，不代替执行前 resolve。
 */
final class PlatformReleaseCatalogService
{
    private const APP_CODE = 'mallbase';

    /** stdout 包含唯一的终止换行。 */
    private const MAXIMUM_STDOUT_BYTES = 262_144;

    private const MAXIMUM_STDERR_BYTES = 64 * 1024;

    /** @var Closure(array<int,string>,string,int):array<string,mixed>|null */
    private readonly ?Closure $executor;

    public function __construct(
        ?Closure $executor = null,
        private readonly ?string $binaryPath = null,
        private readonly int $timeoutMilliseconds = 10_000,
        private readonly ?AgentBinaryTrustValidator $trustValidator = null,
        private readonly ?Closure $clock = null,
    ) {
        $this->executor = $executor;
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
        $binary = $this->binaryPath ?? $this->defaultBinaryPath();
        if ($binary === null) {
            $this->fail();
        }

        try {
            $process = (new AgentProcessRunner($this->executor, $this->trustValidator))->run(
                $binary,
                'catalog',
                '',
                $this->timeoutMilliseconds,
                self::MAXIMUM_STDOUT_BYTES,
                self::MAXIMUM_STDERR_BYTES,
            );
        } catch (Throwable) {
            $this->fail();
        }
        if (!$this->exactObject($process, ['exit_code', 'stdout', 'stderr', 'timed_out'])
            || !is_int($process['exit_code']) || $process['exit_code'] !== 0
            || !is_string($process['stdout']) || !is_string($process['stderr'])
            || !is_bool($process['timed_out']) || $process['timed_out']
            || $process['stderr'] !== '') {
            $this->fail();
        }

        $stdout = $process['stdout'];
        if ($stdout === '' || strlen($stdout) > self::MAXIMUM_STDOUT_BYTES
            || !str_ends_with($stdout, "\n") || !mb_check_encoding($stdout, 'UTF-8')) {
            $this->fail();
        }
        $raw = substr($stdout, 0, -1);
        if ($raw === '' || str_ends_with($raw, "\n") || !$this->isCompactJsonObject($raw)) {
            $this->fail();
        }

        try {
            $document = (new UpgradeStrictJsonDecoder(self::MAXIMUM_STDOUT_BYTES))->decode(
                $raw,
                'application/json',
                strlen($raw),
                ['data'],
            );
        } catch (Throwable) {
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

        $hasDirectFull = false;
        foreach ($release['packages'] as $package) {
            if ($this->isDirectFullPackage($package, $currentVersion)) {
                $hasDirectFull = true;
            }
        }
        if ($release['channel'] !== 'stable' || $this->compareVersions($version, $currentVersion) <= 0
            || !$hasDirectFull) {
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
            'package_kind' => 'full',
            'released_at' => $releasedAt,
        ];
    }

    private function isDirectFullPackage(mixed $package, string $currentVersion): bool
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
            || $bootstrap !== null && !$this->validVersion($bootstrap)
            || !is_string($package['signing_key_id']) || $package['signing_key_id'] === ''
            || strlen($package['signing_key_id']) > 128
            || !is_string($package['package_sha256'])
            || preg_match('/^[0-9a-f]{64}$/D', $package['package_sha256']) !== 1
            || !is_int($package['package_size_bytes']) || $package['package_size_bytes'] < 1) {
            $this->fail();
        }

        return $package['package_kind'] === 'full'
            && $package['from_version'] === $currentVersion
            && $fromLayout === 1
            && $toLayout === 1
            && $bootstrap === null;
    }

    private function defaultBinaryPath(): ?string
    {
        $root = (string) config('agent.upgrade_root', '');
        if ($root === '') {
            return null;
        }

        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'active' . DIRECTORY_SEPARATOR . 'mallbase-agent';
    }

    private function isCompactJsonObject(string $raw): bool
    {
        if ($raw[0] !== '{' || $raw[strlen($raw) - 1] !== '}') {
            return false;
        }

        $inString = false;
        $escaped = false;
        $length = strlen($raw);
        for ($index = 0; $index < $length; $index++) {
            $character = $raw[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($character === '"') {
                $inString = true;
            } elseif ($character === ' ' || $character === "\t" || $character === "\r" || $character === "\n") {
                return false;
            }
        }

        return !$inString && !$escaped;
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
        return is_string($value) && strlen($value) <= 64
            && preg_match('/^(0|[1-9][0-9]{0,18})\.(0|[1-9][0-9]{0,18})\.(0|[1-9][0-9]{0,18})$/D', $value) === 1;
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

    private function fail(): never
    {
        throw new RuntimeException('UPGRADE_CATALOG_UNAVAILABLE');
    }
}
