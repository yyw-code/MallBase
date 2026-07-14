<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\admin\setting\SettingService;
use app\service\upgrade\BootstrapRetentionFinalizeService;
use app\service\upgrade\LocalUploadRootPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BootstrapRetentionFinalizeServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('libsodium is required for bootstrap authority verification.');
        }
        $path = sys_get_temp_dir() . '/mallbase-bootstrap-target-' . bin2hex(random_bytes(8));
        mkdir($path . '/authority', 0700, true);
        $this->root = (string) realpath($path);
        mkdir($this->root . '/results/target', 02770, true);
        chmod($this->root . '/results/target', 02770);
        mkdir($this->root . '/public/uploads/nested', 02770, true);
        file_put_contents($this->root . '/public/uploads/nested/canary.txt', 'retained-data');
        chmod($this->root . '/public/uploads/nested/canary.txt', 0660);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testFinalizesSettingAndPublishesIdempotentBoundConfirmation(): void
    {
        $operationId = '018f5d35-3f42-7a31-a731-9e45df3356c2';
        $settings = new BootstrapRetentionSettingStub('legacy-media');
        $this->writeAuthority($operationId, 'legacy-media');
        $service = $this->service($settings);

        $first = $service->finalize($operationId);
        self::assertSame('uploads', $settings->getLocalUploadRootForBootstrap());
        self::assertSame($operationId, $first['operation_id']);
        self::assertFileExists($this->root . '/results/target/local-setting-intent.json');
        self::assertFileExists($this->root . '/results/target/local-setting.json');
        self::assertFileExists($this->root . '/results/target/confirmation.json');
        self::assertSame(0640, fileperms($this->root . '/results/target/confirmation.json') & 0777);

        $before = file_get_contents($this->root . '/results/target/confirmation.json');
        self::assertIsString($before);
        $second = $service->finalize($operationId);
        self::assertSame($first, $second);
        self::assertSame($before, file_get_contents($this->root . '/results/target/confirmation.json'));
        self::assertSame(2, $settings->compareCalls);

        $envelope = json_decode($before, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('storage_bootstrap_adopt_target_confirmation', $envelope['purpose']);
        $evidence = $envelope['evidence'];
        $actual = $evidence['confirmation_sha256'];
        unset($evidence['confirmation_sha256']);
        self::assertSame($actual, $this->hash($this->canonical($evidence)));
    }

    public function testRejectsTargetContentChangedAfterAuthorization(): void
    {
        $operationId = '018f5d35-3f42-7a31-a731-9e45df3356c2';
        $this->writeAuthority($operationId, 'legacy-media');
        file_put_contents($this->root . '/public/uploads/nested/canary.txt', 'changed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BOOTSTRAP_TARGET_CONTENT_INVALID');
        $this->service(new BootstrapRetentionSettingStub('legacy-media'))->finalize($operationId);
    }

    public function testRecoversAnInterruptedImmutablePublicationUnderThePersistentLock(): void
    {
        $operationId = '018f5d35-3f42-7a31-a731-9e45df3356c2';
        $settings = new BootstrapRetentionSettingStub('legacy-media');
        $this->writeAuthority($operationId, 'legacy-media');
        $orphan = $this->root . '/results/target/.local-setting-intent.json.'
            . str_repeat('a', 24) . '.tmp';
        file_put_contents($orphan, 'partial');
        chmod($orphan, 0640);

        $result = $this->service($settings)->finalize($operationId);

        self::assertSame($operationId, $result['operation_id']);
        self::assertFileDoesNotExist($orphan);
        self::assertFileExists($this->root . '/results/target/.finalize.lock');
        self::assertSame(0640, fileperms($this->root . '/results/target/.finalize.lock') & 0777);
        self::assertSame(0, filesize($this->root . '/results/target/.finalize.lock'));
    }

    public function testRejectsSettingChangedAfterIntentWithoutOverwritingThirdValue(): void
    {
        $operationId = '018f5d35-3f42-7a31-a731-9e45df3356c2';
        $settings = new BootstrapRetentionChangingSettingStub('legacy-media', 'external-change');
        $this->writeAuthority($operationId, 'legacy-media');

        try {
            $this->service($settings)->finalize($operationId);
            self::fail('A setting changed after the durable intent must conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('BOOTSTRAP_LOCAL_UPLOAD_SETTING_CONFLICT', $exception->getMessage());
        }

        self::assertSame('external-change', $settings->currentValue());
        self::assertSame(0, $settings->compareCalls);
        self::assertFileExists($this->root . '/results/target/local-setting-intent.json');
        self::assertFileDoesNotExist($this->root . '/results/target/confirmation.json');
    }

    public function testRejectsMissingOrExtraTargetAuthority(): void
    {
        $operationId = '018f5d35-3f42-7a31-a731-9e45df3356c2';
        foreach (['missing', 'extra'] as $targetShape) {
            $this->writeAuthority($operationId, 'legacy-media', $targetShape);
            try {
                $this->service(new BootstrapRetentionSettingStub('legacy-media'))->finalize($operationId);
                self::fail('Target authority shape ' . $targetShape . ' must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('BOOTSTRAP_TARGETS_INVALID', $exception->getMessage());
            }
            unlink($this->root . '/authority/bootstrap-target-authority.json');
            unlink($this->root . '/authority/storage-ready.pub');
        }
    }

    private function service(SettingService $settings): BootstrapRetentionFinalizeService
    {
        return new BootstrapRetentionFinalizeService(
            $settings,
            new LocalUploadRootPolicy(),
            $this->root . '/authority',
            $this->root . '/results',
            $this->root . '/public',
            ['uploads' => $this->root . '/public/uploads'],
            posix_geteuid(),
            posix_getegid(),
        );
    }

    private function writeAuthority(
        string $operationId,
        string $expectedOldValue,
        string $targetShape = 'valid',
    ): void
    {
        $pair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($pair);
        $secretKey = sodium_crypto_sign_secretkey($pair);
        $keyId = $this->hash($publicKey);
        $public = [
            'schema_version' => 1,
            'key_id' => $keyId,
            'public_key' => base64_encode($publicKey),
        ];
        $this->writeReadOnly($this->root . '/authority/storage-ready.pub', $this->canonical($public));

        $namespace = 'mbs_test_bootstrap';
        $generation = 1;
        $markerId = '028f5d35-3f42-7a31-a731-9e45df3356c3';
        $marker = [
            'schema_version' => 1,
            'installation_storage_namespace' => $namespace,
            'artifact' => 'uploads',
            'storage_layout_version' => 1,
            'layout_generation' => $generation,
            'marker_id' => $markerId,
        ];
        $markerBytes = $this->canonical($marker);
        $this->writeReadOnly($this->root . '/public/uploads/.mallbase-layout-marker.json', $markerBytes);
        $retentionHash = 'sha256:' . str_repeat('a', 64);
        $expectedOldHash = $this->hash($this->canonical([$expectedOldValue]));
        $intent = [
            'schema_version' => 1,
            'purpose' => 'storage_bootstrap_local_setting_intent',
            'operation_id' => $operationId,
            'retention_receipt_sha256' => $retentionHash,
            'expected_old_value_sha256' => $expectedOldHash,
            'canonical_value' => 'uploads',
        ];
        $authorization = [
            'schema_version' => 1,
            'purpose' => 'bootstrap_target_finalize',
            'key_id' => $keyId,
            'installation_storage_namespace' => $namespace,
            'migration_id' => $operationId,
            'operation_id' => $operationId,
            'layout_generation' => $generation,
            'issued_authority_revision' => 3,
            'retention_receipt_sha256' => $retentionHash,
            'composite_receipt_sha256' => 'sha256:' . str_repeat('b', 64),
            'frozen_manifest_sha256' => 'sha256:' . str_repeat('c', 64),
            'target_policy_sha256' => 'sha256:' . str_repeat('d', 64),
            'local_setting_intent_sha256' => $this->hash($this->canonical($intent)),
            'targets' => [
                'uploads' => [
                    'artifact' => 'uploads',
                    'docker_volume_id' => 'docker-volume-uploads',
                    'marker_id' => $markerId,
                    'marker_sha256' => $this->hash($markerBytes),
                    'expected_content_root' => BootstrapRetentionFinalizeService::contentRoot(
                        $this->root . '/public/uploads',
                    ),
                ],
            ],
            'issued_at' => 1,
        ];
        if ($targetShape === 'missing') {
            $authorization['targets'] = [];
        } elseif ($targetShape === 'extra') {
            $authorization['targets']['unexpected'] = array_replace($authorization['targets']['uploads'], [
                'artifact' => 'unexpected',
            ]);
        }
        $authorization['signature'] = base64_encode(sodium_crypto_sign_detached(
            $this->canonical($authorization),
            $secretKey,
        ));
        $this->writeReadOnly(
            $this->root . '/authority/bootstrap-target-authority.json',
            $this->canonical($authorization),
        );
    }

    /** @param array<mixed> $value */
    private function canonical(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function hash(string $value): string
    {
        return 'sha256:' . hash('sha256', $value);
    }

    private function writeReadOnly(string $path, string $content): void
    {
        if (is_file($path) && !is_link($path)) {
            chmod($path, 0644);
        }
        file_put_contents($path, $content);
        chmod($path, 0444);
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $entry->isDir() && !$entry->isLink() ? rmdir($path) : unlink($path);
        }
        rmdir($root);
    }
}

final class BootstrapRetentionSettingStub extends SettingService
{
    public int $compareCalls = 0;

    public function __construct(private string $rootValue)
    {
    }

    public function getLocalUploadRootForBootstrap(): string
    {
        return $this->rootValue;
    }

    public function compareAndSetLocalUploadRootForBootstrap(string $expectedOldValue): void
    {
        ++$this->compareCalls;
        if ($this->rootValue !== 'uploads' && $this->rootValue !== $expectedOldValue) {
            throw new RuntimeException('BOOTSTRAP_LOCAL_UPLOAD_SETTING_CONFLICT');
        }
        $this->rootValue = 'uploads';
    }
}

final class BootstrapRetentionChangingSettingStub extends SettingService
{
    public int $compareCalls = 0;

    private int $reads = 0;

    public function __construct(private string $rootValue, private readonly string $changedValue)
    {
    }

    public function getLocalUploadRootForBootstrap(): string
    {
        if (++$this->reads === 2) {
            $this->rootValue = $this->changedValue;
        }

        return $this->rootValue;
    }

    public function compareAndSetLocalUploadRootForBootstrap(string $expectedOldValue): void
    {
        ++$this->compareCalls;
        $this->rootValue = 'uploads';
    }

    public function currentValue(): string
    {
        return $this->rootValue;
    }
}
