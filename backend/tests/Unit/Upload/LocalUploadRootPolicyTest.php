<?php

declare(strict_types=1);

namespace Tests\Unit\Upload;

use app\job\UploadAssetMigrationJob;
use app\service\UploadService;
use app\support\upload\LocalUploadRootPolicy;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LocalUploadRootPolicyTest extends TestCase
{
    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/mallbase-local-upload-root-' . bin2hex(random_bytes(8));
        mkdir($base . '/public/uploads', 0770, true);
        mkdir($base . '/outside', 0770, true);
        $this->root = (string) realpath($base . '/public');
        $this->outside = (string) realpath($base . '/outside');
    }

    protected function tearDown(): void
    {
        $base = dirname($this->root);
        if (is_link($this->root . '/uploads')) {
            unlink($this->root . '/uploads');
        } else {
            @rmdir($this->root . '/uploads');
        }
        @rmdir($this->outside);
        @rmdir($this->root);
        @rmdir($base);
        parent::tearDown();
    }

    public function testAcceptsOnlyCanonicalPhysicalUploadsDirectory(): void
    {
        $policy = new LocalUploadRootPolicy();

        $policy->assertCanonical('uploads');

        self::assertSame(
            $this->root . '/uploads',
            $policy->assertSupported('uploads', $this->root),
        );
    }

    /** @dataProvider unsupportedRoots */
    public function testRejectsAlternateAbsoluteTraversalAndInvalidTypes(mixed $configuredRoot): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(LocalUploadRootPolicy::ERROR_UNSUPPORTED);

        (new LocalUploadRootPolicy())->assertCanonical($configuredRoot);
    }

    public function testRejectsCanonicalNameWhenPhysicalRootIsSymlink(): void
    {
        rmdir($this->root . '/uploads');
        symlink($this->outside, $this->root . '/uploads');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(LocalUploadRootPolicy::ERROR_UNAVAILABLE);

        (new LocalUploadRootPolicy())->assertSupported('uploads', $this->root);
    }

    public function testRuntimeUploadRejectsDatabaseDriftBeforeReadingUploadedFile(): void
    {
        $service = new LocalUploadRootUploadServiceProbe('alternate-media', $this->root);
        $file = new LocalUploadRootUnreadFile();

        try {
            $service->upload($file, [], 'admin');
            self::fail('Runtime upload accepted a non-canonical local root.');
        } catch (BusinessException $exception) {
            self::assertSame(LocalUploadRootPolicy::MIGRATION_REQUIRED_MESSAGE, $exception->getMessage());
        }

        self::assertSame(0, $file->accessCount);
    }

    public function testRuntimeUploadSanitizesConfigurationReadFailureBeforeFileAccess(): void
    {
        $service = new LocalUploadRootUploadServiceProbe('uploads', $this->root, true);
        $file = new LocalUploadRootUnreadFile();

        try {
            $service->upload($file, [], 'admin');
            self::fail('Runtime upload ignored the local-root configuration failure.');
        } catch (BusinessException $exception) {
            self::assertSame(LocalUploadRootPolicy::MIGRATION_REQUIRED_MESSAGE, $exception->getMessage());
        }

        self::assertSame(0, $file->accessCount);
    }

    public function testPendingAssetMigrationRejectsDatabaseDriftBeforeDriverAccess(): void
    {
        $job = new LocalUploadRootMigrationJobProbe('alternate-media', $this->root);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(LocalUploadRootPolicy::MIGRATION_REQUIRED_MESSAGE);

        $job->verifyLocalUploadRootForTest();
    }

    public function testPendingAssetMigrationSanitizesConfigurationReadFailure(): void
    {
        $job = new LocalUploadRootMigrationJobProbe('uploads', $this->root, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(LocalUploadRootPolicy::MIGRATION_REQUIRED_MESSAGE);

        $job->verifyLocalUploadRootForTest();
    }

    /** @return iterable<string, array{mixed}> */
    public static function unsupportedRoots(): iterable
    {
        yield 'alternate relative' => ['media'];
        yield 'absolute' => ['/srv/uploads'];
        yield 'parent traversal' => ['../uploads'];
        yield 'nested traversal' => ['uploads/../media'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'array' => [['uploads']];
    }
}

final class LocalUploadRootUploadServiceProbe extends UploadService
{
    public function __construct(
        private readonly mixed $configuredRoot,
        private readonly string $publicRoot,
        private readonly bool $failConfigurationRead = false,
    ) {
    }

    protected function configuredLocalUploadRoot(): mixed
    {
        if ($this->failConfigurationRead) {
            throw new RuntimeException('LOCAL_UPLOAD_ROOT_TEST_CONFIG_READ_FAILED');
        }

        return $this->configuredRoot;
    }

    protected function localUploadPublicRoot(): string
    {
        return $this->publicRoot;
    }
}

final class LocalUploadRootMigrationJobProbe extends UploadAssetMigrationJob
{
    public function __construct(
        private readonly mixed $configuredRoot,
        private readonly string $publicRoot,
        private readonly bool $failConfigurationRead = false,
    ) {
        parent::__construct(['migration_id' => 42]);
    }

    public function verifyLocalUploadRootForTest(): string
    {
        return $this->assertCanonicalLocalUploadRoot();
    }

    protected function configuredLocalUploadRoot(): mixed
    {
        if ($this->failConfigurationRead) {
            throw new RuntimeException('LOCAL_UPLOAD_ROOT_TEST_CONFIG_READ_FAILED');
        }

        return $this->configuredRoot;
    }

    protected function localUploadPublicRoot(): string
    {
        return $this->publicRoot;
    }
}

final class LocalUploadRootUnreadFile
{
    public int $accessCount = 0;

    public function getSize(): int
    {
        ++$this->accessCount;
        return 1;
    }

    public function getMime(): string
    {
        ++$this->accessCount;
        return 'image/png';
    }

    public function getOriginalName(): string
    {
        ++$this->accessCount;
        return 'never-read.png';
    }

    public function getPathname(): string
    {
        ++$this->accessCount;
        return '/never-read';
    }
}
