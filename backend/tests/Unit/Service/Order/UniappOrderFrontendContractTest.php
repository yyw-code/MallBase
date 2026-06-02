<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use PHPUnit\Framework\TestCase;

final class UniappOrderFrontendContractTest extends TestCase
{
    public function testOrderListAndDetailUseExpireAtCountdownAndRefundGate(): void
    {
        $listSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages/order/index.vue');
        $detailSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/order/detail.vue');

        $this->assertIsString($listSource);
        $this->assertIsString($detailSource);

        foreach ([$listSource, $detailSource] as $source) {
            $this->assertStringContainsString('expire_at', $source);
            $this->assertStringContainsString('isPendingPayExpired', $source);
            $this->assertStringContainsString('can_refund', $source);
            $this->assertStringContainsString('after_sale_tag_text', $source);
            $this->assertStringContainsString('refundable_amount', $source);
            $this->assertStringContainsString('refundable_quantity', $source);
            $this->assertStringContainsString('已有进行中的售后申请', $source);
            $this->assertStringContainsString('订单已超过售后申请期限', $source);
        }
    }

    public function testPayResultTreatsBackendPaidStatusAsSuccess(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/order/pay-result.vue');
        $this->assertIsString($source);

        $this->assertStringContainsString('const ORDER_STATUS_PAID = 10', $source);
        $this->assertStringNotContainsString('const ORDER_STATUS_PAID = 2', $source);
    }

    public function testShippedOrderShowsRefundActionBeforeConfirmReceive(): void
    {
        $listSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages/order/index.vue');
        $detailSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/order/detail.vue');

        $this->assertIsString($listSource);
        $this->assertIsString($detailSource);

        $listPattern = "/order\\.status === 20\\)[\\s\\S]*?canApplyRefund\\(order\\)[\\s\\S]*?key: 'refund'[\\s\\S]*?key: 'confirm'/";
        $detailPattern = "/order\\.value\\.status === 20\\)[\\s\\S]*?canApplyRefund\\(order\\.value\\)[\\s\\S]*?key: 'refund'[\\s\\S]*?key: 'confirm'/";

        $this->assertMatchesRegularExpression($listPattern, $listSource);
        $this->assertMatchesRegularExpression($detailPattern, $detailSource);
    }

    public function testClientOrderServiceReturnsAfterSaleTagAndBlocksConfirmReceive(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../backend/app/service/client/order/OrderService.php');
        $this->assertIsString($source);

        $this->assertStringContainsString('after_sale_tag_text', $source);
        $this->assertStringContainsString('after_sale', $source);
        $this->assertStringContainsString('aggregateAfterSaleInfo', $source);
        $this->assertStringContainsString('hasActiveRefund', $source);
        $this->assertStringContainsString('calcItemRefundableAmount', $source);
        $this->assertStringContainsString('refundOccupiedStatuses', $source);
        $this->assertStringContainsString('订单存在进行中的售后申请，暂不能确认收货', $source);
        $this->assertStringContainsString('refunded_at', $source);
        $this->assertStringNotContainsString("->whereIn('status', RefundOrderStatus::activeStatuses())\n            ->whereNull('delete_time')\n            ->order('id', 'desc')", $source);
    }

    public function testRefundCompletedOrderUsesReadableFrontendStatus(): void
    {
        $listSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages/order/index.vue');
        $detailSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/order/detail.vue');

        $this->assertIsString($listSource);
        $this->assertIsString($detailSource);

        foreach ([$listSource, $detailSource] as $source) {
            $this->assertStringContainsString('isRefundCompletedOrder', $source);
            $this->assertStringContainsString('退款完成', $source);
        }
        $this->assertStringContainsString('退款已完成，订单因全额退款结束', $listSource);
        $this->assertStringContainsString('售后退款已完成，订单已结束', $detailSource);
    }

    public function testRefundApplyPageUsesBackendRefundableAmount(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/refund/apply.vue');
        $this->assertIsString($source);

        $this->assertStringContainsString('refundableAmount', $source);
        $this->assertStringContainsString('refundable_amount', $source);
        $this->assertStringContainsString('getOrderDetail', $source);
        $this->assertStringContainsString('fetchRefundableInfo', $source);
        $this->assertStringContainsString('if (refundableAmount.value) return refundableAmount.value', $source);
    }

    public function testRefundFrontendExplainsLogisticsExceptionAsMerchantProcessing(): void
    {
        $listSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/refund/list.vue');
        $detailSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/refund/detail.vue');

        $this->assertIsString($listSource);
        $this->assertIsString($detailSource);

        $this->assertStringContainsString('物流异常/丢件，商家核实处理中', $listSource);
        $this->assertStringContainsString('商家已标记物流异常/丢件，正在核实处理，请等待审核结果', $detailSource);
    }

    public function testAdminDynamicFormSupportsOptionListWithoutJsonEditing(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../frontend/admin/apps/web-antd/src/views/settings/dynamic-form/index.vue');
        $this->assertIsString($source);

        $this->assertStringContainsString("item.type === 'option_list'", $source);
        $this->assertStringContainsString('getOptionListRows', $source);
        $this->assertStringContainsString('addOptionListRow', $source);
        $this->assertStringContainsString('removeOptionListRow', $source);
        $this->assertStringContainsString('新增选项', $source);
        $this->assertStringContainsString('CUSTOM_', $source);
    }
}
