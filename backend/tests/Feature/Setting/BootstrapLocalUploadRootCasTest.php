<?php

declare(strict_types=1);

namespace Tests\Feature\Setting;

use app\model\setting\Setting;
use app\service\admin\setting\SettingService;
use app\service\cache\SettingCacheService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Cache;
use Throwable;

final class BootstrapLocalUploadRootCasTest extends TestCase
{
    public function testRealSettingCasSupportsReplayConflictAndCacheFailureRecovery(): void
    {
        $app = new App(dirname(__DIR__, 3));
        try {
            $app->initialize();
            Cache::set('bootstrap:cas:probe', 'ok', 10);
            Cache::delete('bootstrap:cas:probe');
            $setting = Setting::where('code', 'local_root_path')->find();
        } catch (Throwable $exception) {
            self::markTestSkipped('数据库或缓存不可用：' . $exception->getMessage());
        }
        if (!$setting) {
            self::markTestSkipped('local_root_path 设置不存在。');
        }

        $original = (string) $setting->value;
        /** @var SettingService $service */
        $service = $app->make(SettingService::class);
        try {
            $this->setRawValue($setting, 'legacy-media');
            $service->compareAndSetLocalUploadRootForBootstrap('legacy-media');
            self::assertSame('uploads', $service->getLocalUploadRootForBootstrap());

            // A response lost after commit is repaired by the exact replay.
            $service->compareAndSetLocalUploadRootForBootstrap('legacy-media');
            self::assertSame('uploads', $service->getLocalUploadRootForBootstrap());

            // An external writer choosing a third value must not be overwritten.
            $this->setRawValue($setting, 'external-change');
            try {
                $service->compareAndSetLocalUploadRootForBootstrap('legacy-media');
                self::fail('A changed expected value must conflict.');
            } catch (Throwable $exception) {
                self::assertSame('BOOTSTRAP_LOCAL_UPLOAD_SETTING_CONFLICT', $exception->getMessage());
            }
            self::assertSame('external-change', $service->getLocalUploadRootForBootstrap());

            // The DB commit may succeed before cache clearing. The first call
            // surfaces the failure, and the normal replay clears cache without
            // applying a second migration.
            $this->setRawValue($setting, 'legacy-cache-failure');
            $failing = new BootstrapCacheFailingSettingService(new BootstrapFailingSettingCacheService());
            try {
                $failing->compareAndSetLocalUploadRootForBootstrap('legacy-cache-failure');
                self::fail('Injected cache failure must be surfaced.');
            } catch (RuntimeException $exception) {
                self::assertSame('BOOTSTRAP_TEST_CACHE_FAILURE', $exception->getMessage());
            }
            self::assertSame('uploads', $service->getLocalUploadRootForBootstrap());
            $service->compareAndSetLocalUploadRootForBootstrap('legacy-cache-failure');
            self::assertSame('uploads', $service->getLocalUploadRootForBootstrap());
        } finally {
            $this->setRawValue($setting, $original);
            try {
                (new SettingCacheService())->clearSettingValue('local_root_path');
                (new SettingCacheService())->clearGroup('UploadLocal');
            } catch (Throwable) {
                // Preserve the primary assertion; the DB value is already restored.
            }
        }
    }

    private function setRawValue(Setting $setting, string $value): void
    {
        Setting::where('id', (int) $setting->id)->update(['value' => $value]);
        Cache::delete('setting:value:local_root_path');
    }
}

final class BootstrapCacheFailingSettingService extends SettingService
{
    public function __construct(SettingCacheService $cache)
    {
        $this->cacheService = $cache;
    }
}

final class BootstrapFailingSettingCacheService extends SettingCacheService
{
    public function clearSettingValue(string $code): void
    {
        throw new RuntimeException('BOOTSTRAP_TEST_CACHE_FAILURE');
    }
}
