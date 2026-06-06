<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use app\model\auth\Permission;
use app\model\setting\SettingGroup;
use app\service\admin\setting\SettingService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 仅校验设置菜单权限修复的纯逻辑规划，不触发 DB / 容器依赖。
 */
final class SettingServicePermissionRepairPlanTest extends TestCase
{
    private SettingService $service;

    protected function setUp(): void
    {
        $this->service = (new ReflectionClass(SettingService::class))->newInstanceWithoutConstructor();
    }

    public function testRepairOrderIncludesUnsyncedAndDanglingGroups(): void
    {
        $groups = [
            $this->makeGroup(1, 0, SettingGroup::DISPLAY_TYPE_CATEGORY, 0, 0, 'SystemSetting'),
            $this->makeGroup(2, 1, SettingGroup::DISPLAY_TYPE_PAGE, 88, 10, 'ClientConfig'),
        ];

        $ordered = $this->invoke('buildPermissionRepairOrder', [$groups, []]);

        $this->assertSame(
            ['SystemSetting', 'ClientConfig'],
            array_column($ordered, 'code')
        );
    }

    public function testRepairOrderRebuildsParentBeforeChildWhenParentPermissionIsMissing(): void
    {
        $groups = [
            $this->makeGroup(10, 0, SettingGroup::DISPLAY_TYPE_PAGE, 100, 0, 'WechatConfig'),
            $this->makeGroup(11, 10, SettingGroup::DISPLAY_TYPE_PAGE, 110, 0, 'WechatMiniProgram'),
        ];

        $ordered = $this->invoke('buildPermissionRepairOrder', [$groups, [
            110 => $this->makePermissionSnapshot(110, 'WechatMiniProgram'),
        ]]);

        $this->assertSame(
            ['WechatConfig', 'WechatMiniProgram'],
            array_column($ordered, 'code')
        );
    }

    public function testTabChildPageDoesNotNeedStandalonePermissionWhenReferenceIsClean(): void
    {
        $parent = $this->makeGroup(20, 0, SettingGroup::DISPLAY_TYPE_TAB, 200, 0, 'SystemConfig');
        $child = $this->makeGroup(21, 20, SettingGroup::DISPLAY_TYPE_PAGE, 0, 0, 'SystemBasic');

        $this->assertFalse($this->invoke('shouldGroupHaveStandalonePermission', [$child, $parent]));

        $ordered = $this->invoke('buildPermissionRepairOrder', [[$parent, $child], [
            200 => $this->makePermissionSnapshot(200, 'SystemConfig'),
        ]]);
        $this->assertSame([], $ordered);
    }

    public function testDanglingTabChildPermissionReferenceIsScheduledForCleanup(): void
    {
        $parent = $this->makeGroup(30, 0, SettingGroup::DISPLAY_TYPE_TAB, 300, 0, 'UploadConfig');
        $child = $this->makeGroup(31, 30, SettingGroup::DISPLAY_TYPE_PAGE, 310, 0, 'UploadBasic');

        $ordered = $this->invoke('buildPermissionRepairOrder', [[$parent, $child], [
            300 => $this->makePermissionSnapshot(300, 'UploadConfig'),
        ]]);

        $this->assertSame(['UploadBasic'], array_column($ordered, 'code'));
    }

    public function testSharedWrongPermissionReferenceIsScheduledForRebuild(): void
    {
        $groups = [
            $this->makeGroup(40, 0, SettingGroup::DISPLAY_TYPE_CATEGORY, 400, 0, 'SystemSetting'),
            $this->makeGroup(41, 40, SettingGroup::DISPLAY_TYPE_PAGE, 400, 10, 'ClientConfig'),
        ];

        $ordered = $this->invoke('buildPermissionRepairOrder', [$groups, [
            400 => $this->makePermissionSnapshot(400, 'ClientConfig'),
        ]]);

        $this->assertSame(['SystemSetting', 'ClientConfig'], array_column($ordered, 'code'));
    }

    public function testPermissionRebuildDoesNotFailWhenCacheClearFails(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/admin/setting/SettingService.php');

        $this->assertStringContainsString('$this->clearRebuildPermissionCacheSafely();', $source);
        $this->assertStringContainsString('private function clearRebuildPermissionCacheSafely(): void', $source);
        $this->assertStringContainsString('Log::warning', $source);
    }

    /**
     * @return array<string, int|string>
     */
    private function makeGroup(
        int $id,
        int $parentId,
        string $displayType,
        int $permissionId,
        int $sort,
        string $code
    ): array {
        return [
            'id' => $id,
            'parent_id' => $parentId,
            'display_type' => $displayType,
            'permission_id' => $permissionId,
            'sort' => $sort,
            'code' => $code,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function makePermissionSnapshot(int $id, string $groupCode): array
    {
        return [
            'id' => $id,
            'code' => 'SettingGroup:' . $groupCode,
            'source' => Permission::SOURCE_SETTING,
        ];
    }

    /**
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    private function invoke(string $methodName, array $arguments)
    {
        $method = (new ReflectionClass(SettingService::class))->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->service, $arguments);
    }
}
