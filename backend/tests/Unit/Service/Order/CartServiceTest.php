<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\client\service\order\CartService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 购物车服务单元测试（纯逻辑部分）
 *
 * 覆盖：
 *  - normalizeCartIds：去重、过滤非正整数、空数组 & 混合类型兜底
 *  - assertPositiveQty：0 / 负数 / 大数量上限
 *  - MAX_QUANTITY_PER_SKU 契约
 *
 * UPSERT 累加 / 列表聚合 / 事务语义依赖真实 Model + Db，
 * 留到 commit #4 的 OrderService 集成测试里通过真实数据库覆盖。
 */
final class CartServiceTest extends TestCase
{
    private CartService $service;

    protected function setUp(): void
    {
        $this->service = new CartService();
    }

    public function testNormalizeCartIdsDedupsAndPreservesOrder(): void
    {
        $ids = $this->service->normalizeCartIds([3, 1, 3, 2, 1]);
        // 同 ID 的第二次出现应被去重，首次出现的顺序保持
        $this->assertSame([3, 1, 2], $ids);
    }

    public function testNormalizeCartIdsFiltersNonPositive(): void
    {
        $ids = $this->service->normalizeCartIds([0, -1, 5, 'abc', null, 7]);
        $this->assertSame([5, 7], $ids);
    }

    public function testNormalizeCartIdsCoercesStringDigits(): void
    {
        // 前端有时会把 JSON 数字序列化成字符串，服务端应兜底转整数
        $ids = $this->service->normalizeCartIds(['3', '1', 3]);
        $this->assertSame([3, 1], $ids);
    }

    public function testNormalizeCartIdsReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame([], $this->service->normalizeCartIds([]));
    }

    public function testNormalizeCartIdsReturnsEmptyWhenAllInvalid(): void
    {
        $this->assertSame([], $this->service->normalizeCartIds([0, -1, 'foo', null, false]));
    }

    /**
     * 数量必须大于 0（add / updateQuantity 共用同一断言）
     *
     * 这里用反射直接调用 assertPositiveQty，避免触碰到后续的 SKU 校验（需要 DB）
     */
    public function testAssertPositiveQtyRejectsZero(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('数量必须大于 0');

        $this->invokePrivate('assertPositiveQty', [0]);
    }

    public function testAssertPositiveQtyRejectsNegative(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('数量必须大于 0');

        $this->invokePrivate('assertPositiveQty', [-5]);
    }

    public function testAssertPositiveQtyAcceptsPositive(): void
    {
        $this->invokePrivate('assertPositiveQty', [1]);
        $this->invokePrivate('assertPositiveQty', [999]);
        $this->assertTrue(true); // 未抛异常即通过
    }

    /**
     * updateQuantity 超过上限应抛业务异常；
     * 这个路径在 assertPositiveQty 之后，但在访问 DB 之前就会触发。
     */
    public function testUpdateQuantityRejectsOverMaxBeforeDbAccess(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('单商品购买上限为 999 件');

        $this->service->updateQuantity(1, 1, 1000);
    }

    /**
     * MAX_QUANTITY_PER_SKU 契约：不能被调低为 0 或负数，
     * 调整上限时测试应同步更新，避免静默放宽。
     */
    public function testMaxQuantityPerSkuIsBounded(): void
    {
        $ref = new ReflectionClass(CartService::class);
        $constants = $ref->getReflectionConstants();

        $max = null;
        foreach ($constants as $constant) {
            if ($constant->getName() === 'MAX_QUANTITY_PER_SKU') {
                $max = $constant->getValue();
                break;
            }
        }

        $this->assertNotNull($max, 'CartService 必须定义 MAX_QUANTITY_PER_SKU 常量');
        $this->assertIsInt($max);
        $this->assertGreaterThan(0, $max);
        $this->assertSame(999, $max);
    }

    /**
     * toggleSelected 传空数组应静默返回（不报错也不访问 DB）
     */
    public function testToggleSelectedWithEmptyIdsIsNoop(): void
    {
        $this->service->toggleSelected(1, [], 1);
        $this->assertTrue(true); // 不抛异常即可
    }

    /**
     * remove 传空数组同样是 noop
     */
    public function testRemoveWithEmptyIdsIsNoop(): void
    {
        $this->service->remove(1, []);
        $this->assertTrue(true);
    }

    /**
     * 反射调用私有方法
     */
    private function invokePrivate(string $method, array $args): mixed
    {
        $ref = new ReflectionClass(CartService::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->service, ...$args);
    }
}
