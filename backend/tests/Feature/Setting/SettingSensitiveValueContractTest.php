<?php

declare(strict_types=1);

namespace Tests\Feature\Setting;

use app\service\admin\setting\SettingService;
use app\service\cache\SettingCacheService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;
use Throwable;

final class SettingSensitiveValueContractTest extends TestCase
{
    private const GROUP_CODE = 'CodexSensitiveConfig';
    private const SETTING_CODE = 'codex_sensitive_secret';

    private ?App $app = null;
    private bool $dbReady = false;

    protected function setUp(): void
    {
        try {
            $this->app = new App(dirname(__DIR__, 3));
            $this->app->initialize();
            $this->dbReady = true;
            $this->resetFixture();
        } catch (Throwable $e) {
            $this->markTestSkipped('数据库不可用，跳过敏感配置合同测试：' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!$this->dbReady) {
            return;
        }

        try {
            $this->resetFixture();
        } catch (Throwable) {
            // setUp 已经保证不可用时跳过；清理失败不覆盖测试结论。
        }
    }

    public function testSensitiveSettingIsMaskedAndBlankSaveKeepsStoredValue(): void
    {
        $groupId = (int) Db::name('setting_group')->insertGetId([
            'parent_id' => 0,
            'permission_id' => 0,
            'name' => 'Codex 敏感配置',
            'code' => self::GROUP_CODE,
            'icon' => '',
            'description' => '敏感配置合同测试',
            'sort' => 9999,
            'display_type' => 'page',
            'status' => 1,
            'is_system' => 0,
        ]);

        Db::name('setting')->insert([
            'group_id' => $groupId,
            'name' => '敏感密钥',
            'code' => self::SETTING_CODE,
            'value' => 'stored-secret',
            'type' => 'password',
            'options' => null,
            'rules' => null,
            'ui' => json_encode(['sensitive' => true], JSON_UNESCAPED_UNICODE),
            'placeholder' => '请输入密钥',
            'remark' => '测试敏感配置',
            'sort' => 10,
            'is_system' => 0,
        ]);
        $this->clearSettingCache();

        $listResult = $this->settingService()->getSettingList([
            'keyword' => self::SETTING_CODE,
        ]);
        $listSetting = $listResult['list'][0] ?? [];
        $this->assertSame('', $listSetting['value'] ?? null);
        $this->assertSame(true, $listSetting['has_value'] ?? null);

        $setting = $this->findSensitiveSetting($this->settingService()->getGroupConfig(self::GROUP_CODE));
        $this->assertSame('', $setting['value'] ?? null);
        $this->assertSame(true, $setting['has_value'] ?? null);

        $this->settingService()->saveGroupValuesWithValidation(self::GROUP_CODE, [
            self::SETTING_CODE => '',
        ]);

        $this->assertSame(
            'stored-secret',
            (string) Db::name('setting')->where('code', self::SETTING_CODE)->value('value'),
            '敏感配置保存空值时必须保留原密钥。'
        );

        $this->settingService()->saveGroupValuesWithValidation(self::GROUP_CODE, [
            self::SETTING_CODE => 'new-secret',
        ]);

        $this->assertSame(
            'new-secret',
            (string) Db::name('setting')->where('code', self::SETTING_CODE)->value('value'),
            '敏感配置输入新值时应更新密钥。'
        );

        $settingId = (int) Db::name('setting')->where('code', self::SETTING_CODE)->value('id');
        $this->settingService()->updateSetting($settingId, ['value' => '']);

        $this->assertSame(
            'new-secret',
            (string) Db::name('setting')->where('code', self::SETTING_CODE)->value('value'),
            '设置项编辑提交空密码时必须保留原密钥。'
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function findSensitiveSetting(array $config): array
    {
        foreach (($config['settings'] ?? []) as $setting) {
            if (is_array($setting) && ($setting['code'] ?? null) === self::SETTING_CODE) {
                return $setting;
            }
        }

        $this->fail('未找到敏感配置测试项。');
    }

    private function settingService(): SettingService
    {
        return $this->app?->make(SettingService::class) ?? app()->make(SettingService::class);
    }

    private function resetFixture(): void
    {
        $groupId = (int) Db::name('setting_group')->where('code', self::GROUP_CODE)->value('id');
        if ($groupId > 0) {
            Db::name('setting')->where('group_id', $groupId)->delete();
            Db::name('setting_group')->where('id', $groupId)->delete();
        }
        Db::name('setting')->where('code', self::SETTING_CODE)->delete();
        $this->clearSettingCache();
    }

    private function clearSettingCache(): void
    {
        try {
            ($this->app?->make(SettingCacheService::class) ?? app()->make(SettingCacheService::class))->clearAll();
        } catch (Throwable) {
            // 缓存不可用不影响数据库行为验证。
        }
    }
}
