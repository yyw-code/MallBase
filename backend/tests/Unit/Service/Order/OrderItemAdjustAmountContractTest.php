<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use PHPUnit\Framework\TestCase;

final class OrderItemAdjustAmountContractTest extends TestCase
{
    public function testOrderItemSchemaStoresDiscountAndPaySnapshots(): void
    {
        $schema = $this->readBackendFile('install/data/schema/07_mb_order.sql');

        $this->assertStringContainsString('`discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT \'订单项优惠金额\'', $schema);
        $this->assertStringContainsString('`pay_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT \'订单项实付金额（subtotal - discount）\'', $schema);
    }

    public function testOrderCreationWritesItemPaySnapshot(): void
    {
        $source = $this->readBackendFile('app/service/client/order/OrderService.php');

        $this->assertStringContainsString('$itemDiscounts = $amounts[\'item_discounts\'] ?? $this->allocateItemDiscounts($items, $amounts[\'discount_amount\'])', $source);
        $this->assertStringContainsString("'discount_amount' => \$itemDiscount", $source);
        $this->assertStringContainsString("'pay_amount'      => \$itemPayAmount", $source);
    }

    public function testAdminAdjustPriceSupportsItemDiscountAndPayPercent(): void
    {
        $service = $this->readBackendFile('app/service/admin/order/OrderAdminService.php');
        $controller = $this->readBackendFile('app/controller/admin/order/OrderController.php');
        $modal = $this->readRepoFile('frontend/admin/apps/web-antd/src/views/order/adjust-price-modal.vue');

        $this->assertStringContainsString("ADJUST_MODE_ITEM_DISCOUNT = 'item_discount'", $service);
        $this->assertStringContainsString("ADJUST_MODE_PAY_PERCENT = 'pay_percent'", $service);
        $this->assertStringContainsString('buildPercentAdjustments', $service);
        $this->assertStringContainsString('itemDiscounts:', $controller);
        $this->assertStringContainsString('payPercent:', $controller);
        $this->assertStringContainsString('a-segmented', $modal);
        $this->assertStringContainsString("adjust_mode: form.adjust_mode", $modal);
    }

    public function testRefundUsesOrderItemPayAmount(): void
    {
        $refundSource = $this->readBackendFile('app/service/client/order/RefundService.php');
        $orderSource = $this->readBackendFile('app/service/client/order/OrderService.php');

        $this->assertStringContainsString("\$itemPaidCents = \$this->decimalToCents((string) (\$item['pay_amount'] ?? '0.00'))", $refundSource);
        $this->assertStringContainsString('refundOccupiedCentsByOrderItemIds', $orderSource);
        $this->assertStringContainsString("\$itemPaidCents = \$this->decimalToCents((string) (\$item['pay_amount'] ?? '0.00'))", $orderSource);
    }

    private function readBackendFile(string $path): string
    {
        return $this->readFile($this->backendRoot() . '/' . ltrim($path, '/'));
    }

    private function readRepoFile(string $path): string
    {
        return $this->readFile($this->repoRoot() . '/' . ltrim($path, '/'));
    }

    private function readFile(string $path): string
    {
        if (!is_file($path)) {
            $this->markTestSkipped("契约测试依赖文件不存在：{$path}");
        }

        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        return $contents;
    }

    private function backendRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private function repoRoot(): string
    {
        return dirname($this->backendRoot());
    }
}
