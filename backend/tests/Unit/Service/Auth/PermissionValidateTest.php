<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Auth;

use app\validate\admin\auth\PermissionValidate;
use PHPUnit\Framework\TestCase;

final class PermissionValidateTest extends TestCase
{
    public function testPermissionCodeAllowsColonForSettingDerivedPermission(): void
    {
        $validate = new PermissionValidate();

        $this->assertTrue($validate->scene('create')->check($this->payload([
            'code' => 'SettingGroup:SmsRateLimit',
        ])));
    }

    public function testPermissionCodeRejectsUnsupportedCharacters(): void
    {
        $validate = new PermissionValidate();

        $this->assertFalse($validate->scene('create')->check($this->payload([
            'code' => 'SettingGroup-SmsRateLimit',
        ])));
    }

    public function testPermissionTypeRejectsApiType(): void
    {
        $validate = new PermissionValidate();

        $this->assertFalse($validate->scene('create')->check($this->payload([
            'type' => 3,
        ])));
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'parent_id' => 0,
            'name' => '测试权限',
            'code' => 'SystemTest',
            'type' => 1,
            'path' => '/test',
            'icon' => '',
            'component' => '/test/index',
            'sort' => 0,
            'status' => 1,
            'is_show' => 1,
            'remark' => '',
        ], $override);
    }
}
