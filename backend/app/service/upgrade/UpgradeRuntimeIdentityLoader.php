<?php

declare(strict_types=1);

namespace app\service\upgrade;

use JsonException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * 从镜像本地文件加载并冻结运行身份。
 *
 * 路径和稳定卷 marker reader 只能由服务端启动代码注入；I/O 替换表仅供单元测试注入故障。
 */
final class UpgradeRuntimeIdentityLoader
{
    private const OPERATION_NAMES = ['lstat', 'fstat', 'fopen', 'fault'];
    private const MARKER_COMMON_FIELDS = [
        'app_version',
        'deployment_id',
        'provenance_kind',
        'release_inventory_sha256',
        'schema_version',
        'storage_layout_generation',
        'storage_layout_version',
    ];

    /** @var array<string, callable> */
    private readonly array $operations;

    /**
     * @param array<string, callable> $testOperations
     */
    public function __construct(
        private readonly UpgradeMountedStorageIdentityReader $mountedStorageIdentityReader,
        private readonly string $versionPath = '/.version',
        private readonly string $deploymentMarkerPath = '/.mallbase-deployment.json',
        private readonly int $expectedOwnerUid = 0,
        private readonly int $maxJsonBytes = 65536,
        array $testOperations = [],
    ) {
        if (!$this->isFixedAbsolutePath($this->versionPath)
            || !$this->isFixedAbsolutePath($this->deploymentMarkerPath)
            || hash_equals($this->versionPath, $this->deploymentMarkerPath)
            || $this->expectedOwnerUid < 0
            || $this->maxJsonBytes < 1
            || $this->maxJsonBytes > 1_048_576) {
            $this->throwUnavailable();
        }
        foreach ($testOperations as $name => $operation) {
            if (!in_array($name, self::OPERATION_NAMES, true) || !is_callable($operation)) {
                $this->throwUnavailable();
            }
        }

        $this->operations = array_replace($this->nativeOperations(), $testOperations);
    }

    public function load(): UpgradeRuntimeIdentity
    {
        $versionFile = null;
        $deploymentFile = null;

        try {
            $versionFile = $this->openPinnedFile($this->versionPath);
            $deploymentFile = $this->openPinnedFile($this->deploymentMarkerPath);
            $versionRaw = $this->readPinnedFile($versionFile);
            $deploymentRaw = $this->readPinnedFile($deploymentFile);
            $this->checkpoint('after_documents_read');

            $versionDocument = $this->decodeStrictObject($versionRaw);
            $deploymentMarker = $this->decodeStrictObject($deploymentRaw);
            $version = $this->validateVersionDocument($versionDocument);
            $marker = $this->validateDeploymentMarker($deploymentMarker, $version);
            $mountedStorageIdentity = $this->mountedStorageIdentityReader->read(
                $version,
                $marker['deployment_id'],
                $marker['release_inventory_sha256'],
                $marker['storage_layout_version'],
                $marker['storage_layout_generation'],
            );
            if (!$mountedStorageIdentity->matchesImage(
                $version,
                $marker['deployment_id'],
                $marker['release_inventory_sha256'],
                $marker['storage_layout_version'],
                $marker['storage_layout_generation'],
            )) {
                throw new RuntimeException('mounted storage identity mismatch');
            }
            $this->checkpoint('before_final_recheck');

            $this->recheckPinnedFile($versionFile);
            $this->recheckPinnedFile($deploymentFile);

            return new UpgradeRuntimeIdentity(
                $version,
                $marker['deployment_id'],
                $marker['storage_layout_version'],
                $marker['storage_layout_generation'],
            );
        } catch (Throwable) {
            $this->throwUnavailable();
        } finally {
            if (is_array($deploymentFile) && is_resource($deploymentFile['handle'])) {
                @fclose($deploymentFile['handle']);
            }
            if (is_array($versionFile) && is_resource($versionFile['handle'])) {
                @fclose($versionFile['handle']);
            }
        }
    }

    /**
     * @return array{handle:resource,path:string,stat:array<string|int, int>}
     */
    private function openPinnedFile(string $path): array
    {
        $nameStat = $this->lstat($path);
        $this->validateImageFileStat($nameStat);

        $handle = $this->operation('fopen', $path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('open image file');
        }

        try {
            $descriptorStat = $this->operation('fstat', $handle);
            if (!is_array($descriptorStat)) {
                throw new RuntimeException('stat image file');
            }
            $this->validateImageFileStat($descriptorStat);
            $this->assertSameInode($nameStat, $descriptorStat);
        } catch (Throwable $exception) {
            @fclose($handle);
            throw $exception;
        }

        return ['handle' => $handle, 'path' => $path, 'stat' => $descriptorStat];
    }

    /**
     * @param array{handle:resource,path:string,stat:array<string|int, int>} $file
     */
    private function readPinnedFile(array $file): string
    {
        $raw = '';
        while (!feof($file['handle']) && strlen($raw) <= $this->maxJsonBytes) {
            $maximum = $this->maxJsonBytes + 1 - strlen($raw);
            $chunk = @fread($file['handle'], min(8192, $maximum));
            if (!is_string($chunk)) {
                throw new RuntimeException('read image file');
            }
            if ($chunk === '' && !feof($file['handle'])) {
                throw new RuntimeException('read image file');
            }
            $raw .= $chunk;
        }
        if ($raw === '' || strlen($raw) > $this->maxJsonBytes) {
            throw new RuntimeException('invalid image file');
        }

        $descriptorStat = $this->operation('fstat', $file['handle']);
        if (!is_array($descriptorStat)) {
            throw new RuntimeException('stat image file');
        }
        $this->validateImageFileStat($descriptorStat);
        $this->assertUnchangedStat($file['stat'], $descriptorStat);

        return $raw;
    }

    /**
     * @param array{handle:resource,path:string,stat:array<string|int, int>} $file
     */
    private function recheckPinnedFile(array $file): void
    {
        $descriptorStat = $this->operation('fstat', $file['handle']);
        if (!is_array($descriptorStat)) {
            throw new RuntimeException('restat image file');
        }
        $nameStat = $this->lstat($file['path']);
        $this->validateImageFileStat($descriptorStat);
        $this->validateImageFileStat($nameStat);
        $this->assertUnchangedStat($file['stat'], $descriptorStat);
        $this->assertUnchangedStat($file['stat'], $nameStat);
    }

    /** @param array<string|int, int> $stat */
    private function validateImageFileStat(array $stat): void
    {
        $mode = $stat['mode'] ?? -1;
        $size = $stat['size'] ?? -1;
        if (!is_int($mode) || ($mode & 0170000) !== 0100000
            || ($stat['nlink'] ?? 0) !== 1
            || ($stat['uid'] ?? -1) !== $this->expectedOwnerUid
            || ($mode & 07000) !== 0
            || ($mode & 0133) !== 0
            || !is_int($size) || $size < 1 || $size > $this->maxJsonBytes) {
            throw new RuntimeException('invalid image file');
        }
    }

    /**
     * @param array<string|int, int> $left
     * @param array<string|int, int> $right
     */
    private function assertSameInode(array $left, array $right): void
    {
        if (($left['dev'] ?? null) !== ($right['dev'] ?? null)
            || ($left['ino'] ?? null) !== ($right['ino'] ?? null)) {
            throw new RuntimeException('image file changed');
        }
    }

    /**
     * @param array<string|int, int> $expected
     * @param array<string|int, int> $actual
     */
    private function assertUnchangedStat(array $expected, array $actual): void
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'size', 'mtime', 'ctime'] as $field) {
            if (($expected[$field] ?? null) !== ($actual[$field] ?? null)) {
                throw new RuntimeException('image file changed');
            }
        }
    }

    private function validateVersionDocument(stdClass $document): string
    {
        $values = get_object_vars($document);
        $this->assertExactFields($values, ['notes', 'released_at', 'version']);
        if (!is_string($values['version']) || !$this->isSemver($values['version'])) {
            throw new RuntimeException('invalid version document');
        }
        if (!is_string($values['released_at']) || !$this->validReleasedAt($values['released_at'])) {
            throw new RuntimeException('invalid version document');
        }
        if (!is_array($values['notes']) || !array_is_list($values['notes']) || count($values['notes']) > 256) {
            throw new RuntimeException('invalid version document');
        }
        foreach ($values['notes'] as $note) {
            if (!is_string($note) || strlen($note) > 4096) {
                throw new RuntimeException('invalid version document');
            }
        }

        return $values['version'];
    }

    private function validReleasedAt(string $value): bool
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) === 1) {
            return true;
        }

        return preg_match(
            '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',
            $value,
        ) === 1;
    }

    /**
     * @return array{deployment_id:string,release_inventory_sha256:string,storage_layout_version:int,storage_layout_generation:int}
     */
    private function validateDeploymentMarker(stdClass $document, string $version): array
    {
        $values = get_object_vars($document);
        $kind = $values['provenance_kind'] ?? null;
        $lineageFields = match ($kind) {
            'initial' => ['release_id'],
            'upgrade', 'rollback' => ['job_id', 'main_manifest_sha256'],
            default => throw new RuntimeException('invalid deployment lineage'),
        };
        $this->assertExactFields($values, [...self::MARKER_COMMON_FIELDS, ...$lineageFields]);

        if (($values['schema_version'] ?? null) !== 1
            || !is_string($values['app_version']) || !$this->isSemver($values['app_version'])
            || !hash_equals($version, $values['app_version'])
            || !is_string($values['deployment_id']) || !$this->isUuid($values['deployment_id'])
            || !is_string($values['release_inventory_sha256']) || !$this->isSha256($values['release_inventory_sha256'])
            || !is_int($values['storage_layout_version'])
            || $values['storage_layout_version'] < 1 || $values['storage_layout_version'] > 1_000_000
            || !is_int($values['storage_layout_generation'])
            || $values['storage_layout_generation'] < 1) {
            throw new RuntimeException('invalid deployment marker');
        }

        if ($kind === 'initial') {
            if (!is_string($values['release_id'])
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $values['release_id']) !== 1) {
                throw new RuntimeException('invalid deployment lineage');
            }
        } elseif (!is_string($values['job_id']) || !$this->isUuid($values['job_id'])
            || !is_string($values['main_manifest_sha256']) || !$this->isSha256($values['main_manifest_sha256'])) {
            throw new RuntimeException('invalid deployment lineage');
        }

        return [
            'deployment_id' => $values['deployment_id'],
            'release_inventory_sha256' => $values['release_inventory_sha256'],
            'storage_layout_version' => $values['storage_layout_version'],
            'storage_layout_generation' => $values['storage_layout_generation'],
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $allowedFields
     * @param list<string> $optionalFields
     */
    private function assertExactFields(array $values, array $allowedFields, array $optionalFields = []): void
    {
        $actual = array_keys($values);
        sort($actual, SORT_STRING);
        sort($allowedFields, SORT_STRING);
        $required = array_values(array_diff($allowedFields, $optionalFields));
        sort($required, SORT_STRING);

        if (array_diff($actual, $allowedFields) !== [] || array_diff($required, $actual) !== []) {
            throw new RuntimeException('invalid document fields');
        }
    }

    private function isSemver(string $value): bool
    {
        return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $value) === 1;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function isSha256(string $value): bool
    {
        return preg_match('/^(?:sha256:)?[0-9a-f]{64}$/D', $value) === 1;
    }

    private function decodeStrictObject(string $raw): stdClass
    {
        if ($raw === '' || preg_match('//u', $raw) !== 1) {
            throw new RuntimeException('invalid json');
        }
        $offset = 0;
        try {
            $this->parseJsonValue($raw, $offset, 0);
            $this->skipWhitespace($raw, $offset);
            if ($offset !== strlen($raw)) {
                throw new RuntimeException('invalid json');
            }
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('invalid json');
        }
        if (!$decoded instanceof stdClass) {
            throw new RuntimeException('invalid json');
        }

        return $decoded;
    }

    private function parseJsonValue(string $json, int &$offset, int $depth): void
    {
        if ($depth > 32) {
            throw new RuntimeException('invalid json');
        }
        $this->skipWhitespace($json, $offset);
        $character = $json[$offset] ?? '';
        if ($character === '{') {
            $this->parseJsonObject($json, $offset, $depth + 1);

            return;
        }
        if ($character === '[') {
            $this->parseJsonArray($json, $offset, $depth + 1);

            return;
        }
        if ($character === '"') {
            $this->parseJsonString($json, $offset);

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

    private function parseJsonObject(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $seen = [];
        $this->skipWhitespace($json, $offset);
        if (($json[$offset] ?? '') === '}') {
            $offset++;

            return;
        }
        while (true) {
            $this->skipWhitespace($json, $offset);
            $key = $this->parseJsonString($json, $offset);
            if (array_key_exists($key, $seen)) {
                throw new RuntimeException('duplicate json key');
            }
            $seen[$key] = true;
            $this->skipWhitespace($json, $offset);
            if (($json[$offset] ?? '') !== ':') {
                throw new RuntimeException('invalid json');
            }
            $offset++;
            $this->parseJsonValue($json, $offset, $depth);
            $this->skipWhitespace($json, $offset);
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

    private function parseJsonArray(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $this->skipWhitespace($json, $offset);
        if (($json[$offset] ?? '') === ']') {
            $offset++;

            return;
        }
        while (true) {
            $this->parseJsonValue($json, $offset, $depth);
            $this->skipWhitespace($json, $offset);
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

    private function parseJsonString(string $json, int &$offset): string
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
                try {
                    $decoded = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new RuntimeException('invalid json');
                }
                if (!is_string($decoded)) {
                    throw new RuntimeException('invalid json');
                }

                return $decoded;
            }
        }

        throw new RuntimeException('invalid json');
    }

    private function skipWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }

    /** @return array<string|int, int> */
    private function lstat(string $path): array
    {
        clearstatcache(true, $path);
        $stat = $this->operation('lstat', $path);
        if (!is_array($stat)) {
            throw new RuntimeException('image file unavailable');
        }

        return $stat;
    }

    private function isFixedAbsolutePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 4096
            && str_starts_with($path, DIRECTORY_SEPARATOR)
            && !str_contains($path, "\0")
            && !str_contains($path, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR)
            && !str_ends_with($path, DIRECTORY_SEPARATOR)
            && preg_match('#/(?:\.|\.\.)(?:/|$)#', $path) !== 1;
    }

    private function checkpoint(string $name): void
    {
        $this->operation('fault', $name);
    }

    private function operation(string $name, mixed ...$arguments): mixed
    {
        return ($this->operations[$name])(...$arguments);
    }

    /** @return array<string, callable> */
    private function nativeOperations(): array
    {
        return [
            'lstat' => static fn(string $path): array|false => @lstat($path),
            'fstat' => static fn($handle): array|false => @fstat($handle),
            'fopen' => static fn(string $path, string $mode) => @fopen($path, $mode),
            'fault' => static fn(string $checkpoint): null => null,
        ];
    }

    private function throwUnavailable(): never
    {
        throw new RuntimeException('UPGRADE_RUNTIME_IDENTITY_UNAVAILABLE');
    }
}
