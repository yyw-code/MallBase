<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use PHPUnit\Framework\TestCase;

final class OrderItemAdjustAmountContractTest extends TestCase
{
    public function testOrderItemSchemaStoresDiscountAndPaySnapshots(): void
    {
        $schema = file_get_contents(__DIR__ . '/../../../../../backend/install/data/schema/07_mb_order.sql');

        $this->assertStringContainsString('`discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT \'订单项优惠金额\'', $schema);
        $this->assertStringContainsString('`pay_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT \'订单项实付金额（subtotal - discount）\'', $schema);
    }

    public function testOrderCreationWritesItemPaySnapshot(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../backend/app/service/client/order/OrderService.php');

        $this->assertStringContainsString("'discount_amount' => '0.00'", $source);
        $this->assertStringContainsString("'pay_amount'      => \$subtotal", $source);
    }

    public function testAdminAdjustPriceSupportsItemDiscountAndPayPercent(): void
    {
        $service = file_get_contents(__DIR__ . '/../../../../../backend/app/service/admin/order/OrderAdminService.php');
        $controller = file_get_contents(__DIR__ . '/../../../../../backend/app/controller/admin/order/OrderController.php');
        $modal = file_get_contents(__DIR__ . '/../../../../../frontend/admin/apps/web-antd/src/views/order/adjust-price-modal.vue');

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
        $refundSource = file_get_contents(__DIR__ . '/../../../../../backend/app/service/client/order/RefundService.php');
        $orderSource = file_get_contents(__DIR__ . '/../../../../../backend/app/service/client/order/OrderService.php');

        $this->assertStringContainsString("\$itemPaidCents = \$this->decimalToCents((string) (\$item['pay_amount'] ?? '0.00'))", $refundSource);
        $this->assertStringContainsString('refundOccupiedCentsByOrderItemIds', $orderSource);
        $this->assertStringContainsString("\$itemPaidCents = \$this->decimalToCents((string) (\$item['pay_amount'] ?? '0.00'))", $orderSource);
    }
}
