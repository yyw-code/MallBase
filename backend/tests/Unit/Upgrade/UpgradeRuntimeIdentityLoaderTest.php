<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\AgentVerifiedMountedStorageIdentityReader;
use app\service\upgrade\UpgradeRuntimeIdentityLoader;
use app\service\upgrade\UpgradeMountedStorageIdentity;
use app\service\upgrade\UpgradeMountedStorageIdentityReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeRuntimeIdentityLoaderTest extends TestCase
{
    private string $root;
    private string $versionPath;
    private string $deploymentMarkerPath;
    private int $ownerUid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/mallbase-runtime-identity-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0750, true);
        $this->versionPath = $this->root . '/.version';
        $this->deploymentMarkerPath = $this->root . '/.mallbase-deployment.json';
        $this->ownerUid = fileowner($this->root);
        $this->writeVersion();
        $this->writeMarker($this->initialMarker());
    }

    protected function tearDown(): void
    {
        putenv('MALLBASE_APP_VERSION');
        putenv('MALLBASE_DEPLOYMENT_ID');
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function testLoadsInitialImageIdentityAndIgnoresEnvironmentOverrides(): void
    {
        putenv('MALLBASE_APP_VERSION=9.9.9');
        putenv('MALLBASE_DEPLOYMENT_ID=aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        $identity = $this->loader()->load();

        self::assertSame([
            'version' => '1.2.3',
            'deployment_id' => '123e4567-e89b-42d3-a456-426614174000',
            'storage_layout_version' => 1,
            'storage_layout_generation' => 7,
        ], $identity->toArray());
    }

    #[DataProvider('upgradeLineageProvider')]
    public function testLoadsUpgradeAndRollbackLineage(string $provenanceKind): void
    {
        $marker = $this->initialMarker();
        $marker['provenance_kind'] = $provenanceKind;
        unset($marker['release_id']);
        $marker['job_id'] = '123e4567-e89b-42d3-a456-426614174001';
        $marker['main_manifest_sha256'] = 'sha256:' . str_repeat('b', 64);
        $marker['release_inventory_sha256'] = 'sha256:' . str_repeat('a', 64);
        $this->writeMarker($marker);

        self::assertSame('1.2.3', $this->loader()->load()->version);
    }

    /** @return iterable<string, array{string}> */
    public static function upgradeLineageProvider(): iterable
    {
        yield 'upgrade' => ['upgrade'];
        yield 'rollback' => ['rollback'];
    }

    #[DataProvider('invalidMarkerProvider')]
    public function testRejectsInvalidMarkerSchemaIdentityInventoryAndLineage(callable $mutate): void
    {
        $marker = $this->initialMarker();
        $mutate($marker);
        $this->writeMarker($marker);

        $this->assertUnavailable(fn() => $this->loader()->load());
    }

    /** @return iterable<string, array{callable(array<string, mixed>&):void}> */
    public static function invalidMarkerProvider(): iterable
    {
        yield 'future schema' => [static function (array &$marker): void {
            $marker['schema_version'] = 2;
        }];
        yield 'unknown field' => [static function (array &$marker): void {
            $marker['required_deployment_id'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        }];
        yield 'version mismatch' => [static function (array &$marker): void {
            $marker['app_version'] = '1.2.4';
        }];
        yield 'invalid semver' => [static function (array &$marker): void {
            $marker['app_version'] = 'v1.2.3';
        }];
        yield 'invalid uuid' => [static function (array &$marker): void {
            $marker['deployment_id'] = 'not-a-uuid';
        }];
        yield 'uppercase inventory hash' => [static function (array &$marker): void {
            $marker['release_inventory_sha256'] = str_repeat('A', 64);
        }];
        yield 'zero layout version' => [static function (array &$marker): void {
            $marker['storage_layout_version'] = 0;
        }];
        yield 'zero layout generation' => [static function (array &$marker): void {
            $marker['storage_layout_generation'] = 0;
        }];
        yield 'initial missing release id' => [static function (array &$marker): void {
            unset($marker['release_id']);
        }];
        yield 'initial with job field' => [static function (array &$marker): void {
            $marker['job_id'] = '123e4567-e89b-42d3-a456-426614174001';
        }];
        yield 'upgrade missing manifest' => [static function (array &$marker): void {
            $marker['provenance_kind'] = 'upgrade';
            unset($marker['release_id']);
            $marker['job_id'] = '123e4567-e89b-42d3-a456-426614174001';
        }];
        yield 'upgrade retaining release id' => [static function (array &$marker): void {
            $marker['provenance_kind'] = 'upgrade';
            $marker['job_id'] = '123e4567-e89b-42d3-a456-426614174001';
            $marker['main_manifest_sha256'] = str_repeat('b', 64);
        }];
    }

    public function testRejectsInvalidVersionDocumentSchemaAndDuplicateJsonKeys(): void
    {
        $this->writeRaw($this->versionPath, '{"version":"1.2.3","version":"1.2.4"}');
        $this->assertUnavailable(fn() => $this->loader()->load());

        $this->writeVersion(['schema_version' => 2]);
        $this->assertUnavailable(fn() => $this->loader()->load());

        $this->writeVersion();
        $this->writeRaw(
            $this->deploymentMarkerPath,
            '{"schema_version":1,"provenance_kind":"initial","app_version":"1.2.3",'
            . '"deployment_id":"123e4567-e89b-42d3-a456-426614174000",'
            . '"release_inventory_sha256":"' . str_repeat('a', 64) . '",'
            . '"storage_layout_version":1,"storage_layout_generation":7,"release_id":"release-1",'
            . '"release_id":"release-2"}',
        );
        $this->assertUnavailable(fn() => $this->loader()->load());
    }

    public function testRejectsMissingMismatchedAndMalformedMountedVolumeMarkers(): void
    {
        $this->assertUnavailable(fn() => $this->loader([], true)->load());
        $this->assertUnavailable(fn() => $this->loader([
            'runtime' => [
                'marker_id' => '123e4567-e89b-42d3-a456-426614174010',
                'marker_sha256' => str_repeat('c', 64),
            ],
        ], false, 8)->load());
        $this->assertUnavailable(fn() => $this->loader([
            'runtime' => [
                'marker_id' => '123e4567-e89b-42d3-a456-426614174010',
                'marker_sha256' => str_repeat('c', 64),
                'authority_revision' => 3,
            ],
        ], true)->load());
    }

    public function testRejectsMissingSymlinkHardlinkAndUnsafeImageLocalFiles(): void
    {
        unlink($this->deploymentMarkerPath);
        $this->assertUnavailable(fn() => $this->loader()->load());

        $outside = $this->root . '-outside';
        $this->writeRaw($outside, json_encode($this->initialMarker(), JSON_THROW_ON_ERROR));
        symlink($outside, $this->deploymentMarkerPath);
        $this->assertUnavailable(fn() => $this->loader()->load());
        unlink($this->deploymentMarkerPath);

        link($outside, $this->deploymentMarkerPath);
        $this->assertUnavailable(fn() => $this->loader()->load());
        unlink($this->deploymentMarkerPath);
        unlink($outside);

        $this->writeMarker($this->initialMarker());
        chmod($this->deploymentMarkerPath, 0666);
        $this->assertUnavailable(fn() => $this->loader()->load());
    }

    public function testRejectsSameNameFileReplacementWhileDescriptorsArePinned(): void
    {
        $replacement = $this->root . '/replacement.json';
        $this->writeRaw($replacement, json_encode($this->initialMarker(), JSON_THROW_ON_ERROR));
        $replaced = false;

        $loader = $this->loader(operations: [
            'fault' => function (string $checkpoint) use ($replacement, &$replaced): void {
                if ($checkpoint === 'after_documents_read' && !$replaced) {
                    rename($replacement, $this->deploymentMarkerPath);
                    $replaced = true;
                }
            },
        ]);

        $this->assertUnavailable(fn() => $loader->load());
        self::assertTrue($replaced);
    }

    public function testRejectsInvalidInjectedPathAndOperationConfiguration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_RUNTIME_IDENTITY_UNAVAILABLE');

        new UpgradeRuntimeIdentityLoader(
            $this->mountedIdentityReader([], true),
            '.version',
            $this->deploymentMarkerPath,
            $this->ownerUid,
        );
    }

    public function testAgentReaderUsesOnlyFixedVerificationCommandAndAcceptsVerifiedProjection(): void
    {
        $reader = new AgentVerifiedMountedStorageIdentityReader(
            static function (array $command, string $stdin, int $timeout): array {
                self::assertSame([
                    '/test/mallbase-agent-linux-amd64',
                    'storage',
                    'verify-ready-projection',
                ], $command);
                self::assertSame('{}', $stdin);
                self::assertSame(5000, $timeout);

                return [
                    'exit_code' => 0,
                    'stdout' => json_encode(self::verifiedProjection(), JSON_THROW_ON_ERROR) . "\n",
                    'stderr' => '',
                    'timed_out' => false,
                ];
            },
            '/test/mallbase-agent-linux-amd64',
        );

        $identity = $reader->read(
            '1.2.3',
            '123e4567-e89b-42d3-a456-426614174000',
            str_repeat('a', 64),
            1,
            7,
        );

        self::assertSame('mbs_test', $identity->installationStorageNamespace);
        self::assertCount(2, $identity->volumeMarkers);
    }

    public function testAgentReaderRejectsProjectionOutputMismatchAndUnknownFields(): void
    {
        $projection = self::verifiedProjection();
        $projection['app_version'] = '1.2.4';
        $projection['gate_revision'] = 9;
        $reader = new AgentVerifiedMountedStorageIdentityReader(
            static fn(): array => [
                'exit_code' => 0,
                'stdout' => json_encode($projection, JSON_THROW_ON_ERROR),
                'stderr' => '',
                'timed_out' => false,
            ],
            '/test/mallbase-agent-linux-amd64',
        );

        try {
            $reader->read(
                '1.2.3',
                '123e4567-e89b-42d3-a456-426614174000',
                str_repeat('a', 64),
                1,
                7,
            );
            self::fail('Expected verified projection mismatch to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('UPGRADE_MOUNTED_STORAGE_IDENTITY_UNAVAILABLE', $exception->getMessage());
        }
    }

    /**
     * @param array<string, array<string, mixed>>|null $volumeMarkers
     * @param array<string, callable> $operations
     */
    private function loader(
        ?array $volumeMarkers = null,
        bool $readerFails = false,
        int $storageLayoutGeneration = 7,
        array $operations = [],
    ): UpgradeRuntimeIdentityLoader
    {
        $volumeMarkers ??= [
            'runtime' => [
                'marker_id' => '123e4567-e89b-42d3-a456-426614174010',
                'marker_sha256' => str_repeat('c', 64),
            ],
            'uploads' => [
                'marker_id' => '123e4567-e89b-42d3-a456-426614174011',
                'marker_sha256' => str_repeat('d', 64),
            ],
        ];

        return new UpgradeRuntimeIdentityLoader(
            $this->mountedIdentityReader($volumeMarkers, $readerFails, $storageLayoutGeneration),
            $this->versionPath,
            $this->deploymentMarkerPath,
            $this->ownerUid,
            65536,
            $operations,
        );
    }

    /** @param array<string, array<string, mixed>> $volumeMarkers */
    private function mountedIdentityReader(
        array $volumeMarkers,
        bool $fails = false,
        int $storageLayoutGeneration = 7,
    ): UpgradeMountedStorageIdentityReader {
        return new class($volumeMarkers, $fails, $storageLayoutGeneration) implements UpgradeMountedStorageIdentityReader {
            /** @param array<string, array<string, mixed>> $volumeMarkers */
            public function __construct(
                private readonly array $volumeMarkers,
                private readonly bool $fails,
                private readonly int $storageLayoutGeneration,
            ) {
            }

            public function read(
                string $appVersion,
                string $deploymentId,
                string $releaseInventorySha256,
                int $storageLayoutVersion,
                int $storageLayoutGeneration,
            ): UpgradeMountedStorageIdentity {
                if ($this->fails) {
                    throw new RuntimeException('verified mounted storage identity unavailable');
                }

                /** @var array<string, array{marker_id:string,marker_sha256:string}> $markers */
                $markers = $this->volumeMarkers;

                return new UpgradeMountedStorageIdentity(
                    'business_boot',
                    'mbs_test',
                    $appVersion,
                    $deploymentId,
                    $releaseInventorySha256,
                    $storageLayoutVersion,
                    $this->storageLayoutGeneration,
                    str_repeat('e', 64),
                    $markers,
                );
            }
        };
    }

    /** @param array<string, mixed> $extra */
    private function writeVersion(array $extra = []): void
    {
        $this->writeRaw($this->versionPath, json_encode(array_replace([
            'version' => '1.2.3',
            'released_at' => '2026-07-13 12:00:00',
            'notes' => ['release'],
        ], $extra), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $marker */
    private function writeMarker(array $marker): void
    {
        $this->writeRaw($this->deploymentMarkerPath, json_encode($marker, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function initialMarker(): array
    {
        return [
            'schema_version' => 1,
            'provenance_kind' => 'initial',
            'app_version' => '1.2.3',
            'deployment_id' => '123e4567-e89b-42d3-a456-426614174000',
            'release_inventory_sha256' => str_repeat('a', 64),
            'storage_layout_version' => 1,
            'storage_layout_generation' => 7,
            'release_id' => 'official-release-1',
        ];
    }

    /** @return array<string, mixed> */
    private static function verifiedProjection(): array
    {
        return [
            'schema_version' => 1,
            'purpose' => 'business_boot',
            'key_id' => 'sha256:' . str_repeat('f', 64),
            'installation_storage_namespace' => 'mbs_test',
            'app_version' => '1.2.3',
            'deployment_id' => '123e4567-e89b-42d3-a456-426614174000',
            'release_inventory_sha256' => str_repeat('a', 64),
            'storage_layout_version' => 1,
            'layout_generation' => 7,
            'issued_authority_revision' => 9,
            'finalize_receipt_sha256' => 'sha256:' . str_repeat('e', 64),
            'volume_markers' => [
                'runtime' => [
                    'marker_id' => '123e4567-e89b-42d3-a456-426614174010',
                    'marker_sha256' => 'sha256:' . str_repeat('c', 64),
                ],
                'uploads' => [
                    'marker_id' => '123e4567-e89b-42d3-a456-426614174011',
                    'marker_sha256' => 'sha256:' . str_repeat('d', 64),
                ],
            ],
            'issued_at' => 1_783_785_600,
            'signature' => base64_encode(str_repeat('s', 64)),
        ];
    }

    private function writeRaw(string $path, string $bytes): void
    {
        file_put_contents($path, $bytes);
        chmod($path, 0644);
        clearstatcache(true, $path);
    }

    private function assertUnavailable(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected runtime identity loading to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('UPGRADE_RUNTIME_IDENTITY_UNAVAILABLE', $exception->getMessage());
        }
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($path);
    }
}
