<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\InstallLockService;
use PHPUnit\Framework\TestCase;

final class InstallLockServiceTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir() . '/mallbase-install-lock-' . bin2hex(random_bytes(6));
        mkdir($dir . '/runtime/install', 0755, true);
        $this->lockPath = $dir . '/runtime/install/install.lock';
    }

    protected function tearDown(): void
    {
        if (is_file($this->lockPath)) {
            unlink($this->lockPath);
        }

        $directory = dirname($this->lockPath);
        foreach ([$directory, dirname($directory), dirname(dirname($directory))] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

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
}
