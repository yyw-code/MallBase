<?php

declare(strict_types=1);

namespace Tests\Feature\Setting;

use app\service\admin\setting\SettingService;
use app\support\upload\LocalUploadRootPolicy;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LocalUploadRootSettingContractTest extends TestCase
{
    /** @dataProvider unconditionalScenarios */
    public function testDirectGroupSaveRejectsNonCanonicalRootBeforeAnyStateRead(
        string $driver,
        int $localAssetCount,
        bool $pendingMigration,
    ): void {
        $service = new LocalUploadRootSettingProbeService($localAssetCount, $pendingMigration);

        try {
            $service->saveGroupValues('UploadLocal', [
                'upload_driver' => $driver,
                'local_root_path' => 'alternate-media',
            ]);
            self::fail('Non-canonical local upload root was accepted.');
        } catch (BusinessException $exception) {
            self::assertSame(LocalUploadRootPolicy::MIGRATION_REQUIRED_MESSAGE, $exception->getMessage());
        }

        self::assertSame(0, $service->stateReadCount);
    }

    /** @dataProvider unconditionalScenarios */
    public function testValidatedGroupSaveRejectsBeforeConfigReadRegardlessOfDriverOrMigrationState(
        string $driver,
        int $localAssetCount,
        bool $pendingMigration,
    ): void {
        $service = new LocalUploadRootSettingProbeService($localAssetCount, $pendingMigration);

        try {
            $service->saveGroupValuesWithValidation('UploadLocal', [
                'upload_driver' => $driver,
                'local_root_path' => '/srv/private-media',
            ]);
            self::fail('Non-canonical local upload root was accepted.');
        } catch (BusinessException $exception) {
            self::assertSame(LocalUploadRootPolicy::MIGRATION_REQUIRED_MESSAGE, $exception->getMessage());
        }

        self::assertSame(0, $service->stateReadCount);
    }

    public function testCanonicalRootDoesNotHideAConfigurationReadFailure(): void
    {
        $service = new LocalUploadRootSettingProbeService(0, false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LOCAL_UPLOAD_ROOT_TEST_CONFIG_READ_FAILED');

        $service->saveGroupValuesWithValidation('UploadLocal', ['local_root_path' => 'uploads']);
    }

    /** @return iterable<string, array{string,int,bool}> */
    public static function unconditionalScenarios(): iterable
    {
        yield 'local with zero assets and no migration' => ['local', 0, false];
        yield 'local with assets' => ['local', 3, false];
        yield 'oss with dormant alternate root' => ['oss', 0, false];
        yield 'cos with pending migration' => ['cos', 7, true];
    }
}

final class LocalUploadRootSettingProbeService extends SettingService
{
    public int $stateReadCount = 0;

    public function __construct(
        public readonly int $localAssetCount,
        public readonly bool $pendingMigration,
    ) {
    }

    protected function model(?string $modelClass = null)
    {
        ++$this->stateReadCount;
        throw new RuntimeException('LOCAL_UPLOAD_ROOT_TEST_CONFIG_READ_FAILED');
    }
}
