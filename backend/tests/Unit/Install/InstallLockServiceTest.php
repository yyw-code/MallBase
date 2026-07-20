<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\InstallLockService;
use PHPUnit\Framework\TestCase;

final class InstallLockServiceTest extends TestCase
{
    private string $root;

    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/mallbase-install-lock-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/runtime/install', 0755, true);
        $this->lockPath = $this->root . '/runtime/install/install.lock';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testWriteInstalledLockCreatesJsonLock(): void
    {
        $service = new InstallLockService($this->lockPath);

        $service->writeInstalledLock('2026-06-19 12:00:00');

        $this->assertTrue($service->isInstalled());
        $this->assertSame([
            'installed_at' => '2026-06-19 12:00:00',
        ], $service->getLockInfo());
        clearstatcache(true, $this->lockPath);
        $this->assertSame(0600, fileperms($this->lockPath) & 0777);
    }

    public function testRejectsLeafSymlinkForReadAndWriteWithoutChangingTarget(): void
    {
        $target = $this->root . '/target.lock';
        $content = json_encode(['installed_at' => '2026-06-19 12:00:00'], JSON_THROW_ON_ERROR);
        file_put_contents($target, $content);
        if (!@symlink($target, $this->lockPath)) {
            self::markTestSkipped('symlink unavailable');
        }
        $service = new InstallLockService($this->lockPath);

        foreach ([
            static fn() => $service->getLockInfo(),
            static fn() => $service->writeInstalledLock('2026-07-21 12:00:00'),
        ] as $operation) {
            try {
                $operation();
                self::fail('leaf symlink was accepted');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('安装锁文件不能是符号链接', $exception->getMessage());
            }
        }

        self::assertSame($content, file_get_contents($target));
    }

    public function testRejectsLockFileWithMultipleHardLinks(): void
    {
        $content = json_encode(['installed_at' => '2026-06-19 12:00:00'], JSON_THROW_ON_ERROR);
        file_put_contents($this->lockPath, $content);
        $alias = $this->root . '/install-lock-alias';
        if (!@link($this->lockPath, $alias)) {
            self::markTestSkipped('hard links unavailable');
        }
        $service = new InstallLockService($this->lockPath);

        foreach ([
            static fn() => $service->getLockInfo(),
            static fn() => $service->writeInstalledLock('2026-07-21 12:00:00'),
        ] as $operation) {
            try {
                $operation();
                self::fail('hard-linked lock was accepted');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('安装锁文件存在硬链接，已拒绝', $exception->getMessage());
            }
        }

        self::assertSame($content, file_get_contents($alias));
    }

    public function testReadRejectsLockPathWhoseIdentityChangesAfterOpen(): void
    {
        $original = json_encode(['installed_at' => '2026-06-19 12:00:00'], JSON_THROW_ON_ERROR);
        $replacement = json_encode(['installed_at' => '2026-07-21 12:00:00'], JSON_THROW_ON_ERROR);
        file_put_contents($this->lockPath, $original);
        $swapped = false;
        $service = new InstallLockService(
            $this->lockPath,
            function (string $event, string $path) use (&$swapped, $replacement): void {
                if ($event !== 'lock_opened' || $swapped) {
                    return;
                }
                $swapped = true;
                unlink($path);
                file_put_contents($path, $replacement);
                chmod($path, 0600);
            },
        );

        try {
            $service->getLockInfo();
            self::fail('lock path identity race was accepted');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('安装锁并发读取冲突，请重试', $exception->getMessage());
        }

        self::assertTrue($swapped);
        self::assertSame($replacement, file_get_contents($this->lockPath));
    }

    public function testExistingLockIsUnchangedWhenTemporaryPublishFails(): void
    {
        (new InstallLockService($this->lockPath))->writeInstalledLock('2026-06-19 12:00:00');
        $original = file_get_contents($this->lockPath);
        $temporaryObserved = false;
        $service = new InstallLockService(
            $this->lockPath,
            function (string $event, string $path) use (&$temporaryObserved, $original): void {
                if ($event !== 'temporary_flushed') {
                    return;
                }
                $temporaryObserved = true;
                self::assertSame($original, file_get_contents($this->lockPath));
                $temporaryData = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('mbt_atomic', $temporaryData['platform']['token'] ?? null);
                throw new \RuntimeException('模拟临时发布失败');
            },
        );

        try {
            $service->savePlatformState(['token' => 'mbt_atomic']);
            self::fail('temporary publish failure was ignored');
        } catch (\RuntimeException $exception) {
            self::assertSame('模拟临时发布失败', $exception->getMessage());
        }

        self::assertTrue($temporaryObserved);
        self::assertSame($original, file_get_contents($this->lockPath));
        self::assertSame([], $this->temporaryFiles());
    }

    public function testNewLockIsPublishedOnlyAfterCompleteTemporaryFileIsFlushed(): void
    {
        $temporaryObserved = false;
        $service = new InstallLockService(
            $this->lockPath,
            function (string $event, string $path) use (&$temporaryObserved): void {
                if ($event !== 'temporary_flushed') {
                    return;
                }
                $temporaryObserved = true;
                self::assertFileDoesNotExist($this->lockPath);
                $temporaryData = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('2026-07-21 12:00:00', $temporaryData['installed_at'] ?? null);
                clearstatcache(true, $path);
                self::assertSame(0600, fileperms($path) & 0777);
            },
        );

        $service->writeInstalledLock('2026-07-21 12:00:00');

        self::assertTrue($temporaryObserved);
        self::assertSame('2026-07-21 12:00:00', $service->getLockInfo()['installed_at'] ?? null);
        self::assertSame([], $this->temporaryFiles());
    }

    public function testFailureCleanupDoesNotDeleteReplacementAtOwnedTemporaryPath(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('open temporary replacement semantics differ on Windows');
        }
        $replacementPath = null;
        $service = new InstallLockService(
            $this->lockPath,
            function (string $event, string $path) use (&$replacementPath): void {
                if ($event !== 'temporary_created') {
                    return;
                }
                $replacementPath = $path;
                unlink($path);
                file_put_contents($path, 'foreign temporary file');
                throw new \RuntimeException('模拟临时文件替换');
            },
        );

        try {
            $service->writeInstalledLock('2026-07-21 12:00:00');
            self::fail('temporary replacement race was ignored');
        } catch (\RuntimeException $exception) {
            self::assertSame('模拟临时文件替换', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->lockPath);
        self::assertIsString($replacementPath);
        self::assertFileExists($replacementPath);
        self::assertSame('foreign temporary file', file_get_contents($replacementPath));
    }

    public function testUpdateRejectsFinalPathReplacementBeforeAtomicPublish(): void
    {
        (new InstallLockService($this->lockPath))->writeInstalledLock('2026-06-19 12:00:00');
        $replacement = json_encode(['installed_at' => 'external replacement'], JSON_THROW_ON_ERROR);
        $swapped = false;
        $service = new InstallLockService(
            $this->lockPath,
            function (string $event, string $path) use (&$swapped, $replacement): void {
                if ($event !== 'before_publish' || $swapped) {
                    return;
                }
                $swapped = true;
                unlink($this->lockPath);
                file_put_contents($this->lockPath, $replacement);
                chmod($this->lockPath, 0600);
            },
        );

        try {
            $service->savePlatformState(['token' => 'must-not-overwrite']);
            self::fail('final path replacement race was accepted');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('安装锁并发更新冲突，请重试', $exception->getMessage());
        }

        self::assertTrue($swapped);
        self::assertSame($replacement, file_get_contents($this->lockPath));
        self::assertSame([], $this->temporaryFiles());
    }

    public function testDirectoryHardeningHappensBeforeNewLockFileIsCreated(): void
    {
        $installDirectory = dirname($this->lockPath);
        $externalDirectory = $this->root . '/external-install';
        rmdir($installDirectory);
        mkdir($externalDirectory, 0755);
        if (!@symlink($externalDirectory, $installDirectory)) {
            self::markTestSkipped('symlink unavailable');
        }

        try {
            (new InstallLockService($this->lockPath))->writeInstalledLock('2026-06-19 12:00:00');
            self::fail('unsafe install directory was accepted');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('安装锁目录权限收紧失败', $exception->getMessage());
        }

        self::assertFileDoesNotExist($externalDirectory . '/install.lock');
    }

    public function testNewLockIsRemovedWhenEncodingFails(): void
    {
        $service = new InstallLockService($this->lockPath);

        try {
            $service->writeInstalledLock("\xB1\x31");
            self::fail('invalid lock content was accepted');
        } catch (\RuntimeException $exception) {
            self::assertSame('安装锁内容编码失败', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->lockPath);
        self::assertFalse($service->isInstalled());
    }

    public function testExistingLockIsPreservedWhenEncodingFails(): void
    {
        $service = new InstallLockService($this->lockPath);
        $service->writeInstalledLock('2026-06-19 12:00:00');
        $original = file_get_contents($this->lockPath);

        try {
            $service->writeInstalledLock("\xB1\x31");
            self::fail('invalid lock content was accepted');
        } catch (\RuntimeException $exception) {
            self::assertSame('安装锁内容编码失败', $exception->getMessage());
        }

        self::assertFileExists($this->lockPath);
        self::assertSame($original, file_get_contents($this->lockPath));
        self::assertSame('2026-06-19 12:00:00', $service->getLockInfo()['installed_at'] ?? null);
    }

    public function testReadingPlatformStateHardensExistingLockPermissions(): void
    {
        file_put_contents($this->lockPath, json_encode([
            'installed_at' => '2026-06-19 12:00:00',
            'platform' => ['token' => 'mbt_token', 'disabled' => false],
        ], JSON_THROW_ON_ERROR));
        chmod($this->lockPath, 0777);
        chmod(dirname($this->lockPath), 0777);
        chmod(dirname(dirname($this->lockPath)), 0777);
        $service = new InstallLockService($this->lockPath);

        self::assertSame('mbt_token', $service->getPlatformState()['token'] ?? null);
        clearstatcache(true, $this->lockPath);
        self::assertSame(0600, fileperms($this->lockPath) & 0777);
        self::assertSame(0755, fileperms(dirname($this->lockPath)) & 0777);
        self::assertSame(0755, fileperms(dirname(dirname($this->lockPath))) & 0777);
    }

    public function testCustomLockPathDoesNotChangeUnrelatedAncestorPermissions(): void
    {
        $base = dirname(dirname(dirname($this->lockPath)));
        $parent = $base . '/custom-parent';
        $directory = $parent . '/custom-leaf';
        mkdir($directory, 0777, true);
        chmod($parent, 0777);
        chmod($directory, 0777);
        $path = $directory . '/custom.lock';

        (new InstallLockService($path))->writeInstalledLock('2026-06-19 12:00:00');

        clearstatcache(true, $parent);
        clearstatcache(true, $directory);
        self::assertSame(0777, fileperms($parent) & 0777);
        self::assertSame(0777, fileperms($directory) & 0777);
        self::assertSame(0600, fileperms($path) & 0777);

        @unlink($path);
        @chmod($directory, 0777);
        @rmdir($directory);
        @chmod($parent, 0777);
        @rmdir($parent);
    }

    public function testSavePlatformStateMergesIntoExistingLock(): void
    {
        $service = new InstallLockService($this->lockPath);
        $service->writeInstalledLock('2026-06-19 12:00:00');

        $saved = $service->savePlatformState([
            'instance_id' => ' d3ec761b-c5d1-4663-8c76-7d2d351efad5 ',
            'token' => ' mbt_token ',
            'last_report_at' => '100',
            'next_report_after' => -1,
            'disabled' => 'false',
            'ignored' => 'value',
        ]);

        $this->assertSame([
            'instance_id' => 'd3ec761b-c5d1-4663-8c76-7d2d351efad5',
            'token' => 'mbt_token',
            'last_report_at' => 100,
            'next_report_after' => 0,
            'disabled' => false,
        ], $saved);

        $this->assertSame($saved, $service->getPlatformState());
        $this->assertSame('2026-06-19 12:00:00', $service->getLockInfo()['installed_at'] ?? null);
    }

    public function testSavePlatformStateRequiresExistingLock(): void
    {
        $service = new InstallLockService($this->lockPath);

        $this->expectException(\RuntimeException::class);
        $service->savePlatformState(['instance_id' => 'd3ec761b-c5d1-4663-8c76-7d2d351efad5']);
    }

    public function testSavePlatformStateKeepsReportFailureMetadata(): void
    {
        $service = new InstallLockService($this->lockPath);
        $service->writeInstalledLock('2026-06-19 12:00:00');

        $saved = $service->savePlatformState([
            'last_report_error' => ' http_500 ',
            'last_report_error_at' => '200',
        ]);

        $this->assertSame('http_500', $saved['last_report_error'] ?? null);
        $this->assertSame(200, $saved['last_report_error_at'] ?? null);
    }

    public function testReservePlatformReportWindowOnlyAllowsOneReportInInterval(): void
    {
        $service = new InstallLockService($this->lockPath);
        $service->writeInstalledLock('2026-06-19 12:00:00');

        $this->assertTrue($service->reservePlatformReportWindow(100, 86400));
        $this->assertFalse($service->reservePlatformReportWindow(101, 86400));
        $this->assertSame(86500, $service->getPlatformState()['next_report_after'] ?? null);
        $this->assertTrue($service->reservePlatformReportWindow(86500, 86400));
    }

    public function testReservePlatformReportWindowRespectsDisabledState(): void
    {
        $service = new InstallLockService($this->lockPath);
        $service->writeInstalledLock('2026-06-19 12:00:00');
        $service->savePlatformState(['disabled' => true]);

        $this->assertFalse($service->reservePlatformReportWindow(100, 86400));
    }

    public function testPlatformComponentsAreThrottledAndReturnedByActiveWindow(): void
    {
        $service = new InstallLockService($this->lockPath);
        $service->writeInstalledLock('2026-06-19 12:00:00');

        $service->markPlatformComponentSeen('admin_web', 100, 3600);
        $service->markPlatformComponentSeen('admin_web', 200, 3600);
        $service->markPlatformComponentSeen('uniapp', 300, 3600);

        $state = $service->getPlatformState();
        $this->assertSame(100, $state['components']['admin_web'] ?? null);
        $this->assertSame(300, $state['components']['uniapp'] ?? null);

        $this->assertSame([
            ['type' => 'admin_web', 'version' => '1.0.0'],
            ['type' => 'uniapp', 'version' => '1.0.0'],
        ], $service->getActivePlatformComponents(400, 3600, '1.0.0'));

        $this->assertSame([], $service->getActivePlatformComponents(4000, 3600, '1.0.0'));
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0770);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }

    /** @return array<int, string> */
    private function temporaryFiles(): array
    {
        return glob(
            dirname($this->lockPath) . '/.' . basename($this->lockPath) . '.*.tmp',
        ) ?: [];
    }
}
