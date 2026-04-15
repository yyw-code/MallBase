<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use app\service\RegionResolverService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 仅针对 validatePathStatusByLevel 的纯逻辑单元测试。
 *
 * 用反射避开 DB，本次重构的目标是把“xx 已停用”提示所需的
 * 停用地区名（name / level）沿调用链透出给前端，替代裸 code。
 */
final class RegionResolverServicePathValidationTest extends TestCase
{
    private RegionResolverService $service;

    protected function setUp(): void
    {
        $this->service = new RegionResolverService();
    }

    public function testReturnsNullWhenPathIsValid(): void
    {
        $path = [
            ['id' => 1, 'name' => '天津市', 'status' => 1, 'parent_id' => 0],
            ['id' => 2, 'name' => '市辖区', 'status' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => '和平区', 'status' => 1, 'parent_id' => 2],
        ];
        $this->assertNull($this->invokeValidate($path, 3));
    }

    public function testReturnsReasonWhenPathLengthMismatch(): void
    {
        $path = [
            ['id' => 1, 'name' => '天津市', 'status' => 1, 'parent_id' => 0],
        ];
        $result = $this->invokeValidate($path, 3);
        $this->assertIsArray($result);
        $this->assertSame('地区路径数据不完整', $result['reason']);
        // 不完整场景下不返回层级/名称（避免误导）
        $this->assertArrayNotHasKey('level', $result);
        $this->assertArrayNotHasKey('name', $result);
    }

    public function testSurfacesDisabledRegionNameForProvince(): void
    {
        $path = [
            ['id' => 1, 'name' => '天津市', 'status' => 0, 'parent_id' => 0],
            ['id' => 2, 'name' => '市辖区', 'status' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => '和平区', 'status' => 1, 'parent_id' => 2],
        ];
        $result = $this->invokeValidate($path, 3);

        $this->assertIsArray($result);
        $this->assertSame('省级已停用', $result['reason']);
        $this->assertSame(1, $result['level']);
        $this->assertSame('天津市', $result['name']);
    }

    public function testSurfacesDisabledRegionNameForDistrict(): void
    {
        $path = [
            ['id' => 1, 'name' => '天津市', 'status' => 1, 'parent_id' => 0],
            ['id' => 2, 'name' => '市辖区', 'status' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => '和平区', 'status' => 0, 'parent_id' => 2],
        ];
        $result = $this->invokeValidate($path, 3);

        $this->assertIsArray($result);
        $this->assertSame('区县已停用', $result['reason']);
        $this->assertSame(3, $result['level']);
        $this->assertSame('和平区', $result['name']);
    }

    public function testReturnsParentChildMismatch(): void
    {
        $path = [
            ['id' => 1, 'name' => '天津市', 'status' => 1, 'parent_id' => 0],
            ['id' => 2, 'name' => '市辖区', 'status' => 1, 'parent_id' => 999],
        ];
        $result = $this->invokeValidate($path, 2);
        $this->assertIsArray($result);
        $this->assertSame('地区父子关系不匹配', $result['reason']);
    }

    /**
     * @param array<int, array<string, mixed>> $path
     * @return array{reason: string, level?: int, name?: string}|null
     */
    private function invokeValidate(array $path, int $level): ?array
    {
        $method = (new ReflectionClass(RegionResolverService::class))
            ->getMethod('validatePathStatusByLevel');
        $method->setAccessible(true);
        /** @var array{reason: string, level?: int, name?: string}|null $out */
        $out = $method->invoke($this->service, $path, $level);
        return $out;
    }
}
