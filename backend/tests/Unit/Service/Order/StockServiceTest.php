<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\service\order\StockService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 库存服务单元测试
 *
 * 仅覆盖校验路径（qty 正数断言），乐观锁扣减路径由集成测试覆盖
 * —— 因为乐观锁本身需要真实 MySQL + 并发运行时才能验证“不超卖”
 *
 * 并发真值测试将在 commit #4 的 OrderService 集成测试里通过真实数据库完成
 */
final class StockServiceTest extends TestCase
{
    private StockService $service;

    protected function setUp(): void
    {
        $this->service = new StockService();
    }

    public function testDecreaseWithZeroQtyThrows(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('库存变更数量必须大于 0');

        $this->service->decrease(1, 0);
    }

    public function testDecreaseWithNegativeQtyThrows(): void
    {
        $this->expectException(BusinessException::class);

        $this->service->decrease(1, -5);
    }

    public function testRestoreWithZeroQtyThrows(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('库存变更数量必须大于 0');

        $this->service->restore(1, 0);
    }

    public function testRestoreWithNegativeQtyThrows(): void
    {
        $this->expectException(BusinessException::class);

        $this->service->restore(1, -10);
    }

    /**
     * assertPositiveQty 是统一校验入口，不允许绕过
     */
    public function testAssertPositiveQtyIsPrivateEntry(): void
    {
        $ref = new ReflectionClass(StockService::class);
        $this->assertTrue($ref->hasMethod('assertPositiveQty'));
        $method = $ref->getMethod('assertPositiveQty');
        $this->assertTrue($method->isPrivate(), 'assertPositiveQty 必须是私有方法');
    }

    /**
     * 批量扣减必须走正数校验，校验在 DB 访问前生效
     *
     * 把非法项放在第一个，确保异常是 BusinessException 而不是底层 DB 异常
     */
    public function testDecreaseBatchValidatesBeforeDbAccess(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('库存变更数量必须大于 0');

        $this->service->decreaseBatch([
            ['sku_id' => 1, 'quantity' => 0], // 非法项优先触发校验
            ['sku_id' => 2, 'quantity' => 5],
        ]);
    }

    /**
     * 批量回滚同样先校验再访问 DB
     */
    public function testRestoreBatchValidatesBeforeDbAccess(): void
    {
        $this->expectException(BusinessException::class);

        $this->service->restoreBatch([
            ['sku_id' => 1, 'quantity' => -3], // 非法项优先触发校验
            ['sku_id' => 2, 'quantity' => 1],
        ]);
    }
}
