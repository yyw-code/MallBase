<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;

final class RefundRouteContractTest extends TestCase
{
    public function testAdminApproveRouteDescribesWechatRefund(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/route/api/admin/order.php',
        );

        $this->assertStringContainsString('审核同意售后申请并发起微信退款', $source);
        $this->assertStringNotContainsString('Mock 退款 + 回滚库存', $source);
    }
}
