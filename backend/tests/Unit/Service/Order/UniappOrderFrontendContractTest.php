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
        $this->assertStringContainsString('orderStatus === ORDER_STATUS_PAID', $source);
        $this->assertStringContainsString("status.value = 'success'", $source);
        $this->assertStringContainsString("queryStatus === 'success' && !orderId.value", $source);
        $this->assertStringContainsString('pollOrderStatus()', $source);
    }

    public function testPayResultResolvesOrderIdBySnForRetryPay(): void
    {
        $payResultSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/order/pay-result.vue');
        $controllerSource = file_get_contents(__DIR__ . '/../../../../../backend/app/controller/client/order/OrderController.php');
        $serviceSource = file_get_contents(__DIR__ . '/../../../../../backend/app/service/client/order/OrderService.php');

        $this->assertIsString($payResultSource);
        $this->assertIsString($controllerSource);
        $this->assertIsString($serviceSource);

        $this->assertStringContainsString('getOrderDetail, getOrderList', $payResultSource);
        $this->assertStringContainsString('resolveOrderBySn', $payResultSource);
        $this->assertStringContainsString("getOrderList({ sn: sn.value, page: 1, limit: 1 })", $payResultSource);
        $this->assertStringContainsString('ensureOrderId', $payResultSource);
        $this->assertStringContainsString('订单信息缺失，请查看订单', $payResultSource);

        $this->assertStringContainsString("'sn'     => \$this->request->param('sn', null)", $controllerSource);
        $this->assertStringContainsString("trim((string) (\$filter['sn'] ?? ''))", $serviceSource);
        $this->assertStringContainsString("->where('sn', \$sn)", $serviceSource);
        $this->assertStringContainsString('compact(\'total\', \'list\')', $serviceSource);
    }

    public function testWechatJsapiPayResultWaitsForBackendConfirmation(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/utils/payment.js');
        $this->assertIsString($source);

        $this->assertSame(
            2,
            substr_count($source, "return { status: 'pending', message: '正在确认支付结果' }")
        );
        $this->assertStringNotContainsString('JSAPI 已调起且 SDK 回调成功', $source);
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
        $this->assertStringContainsString('selectedItemInputs', $source);
        $this->assertStringContainsString('exactBackendAmount', $source);
        $this->assertStringContainsString('applyRefundBatch', $source);
        $this->assertStringContainsString('以后端实时计算为准', $source);
        $this->assertStringNotContainsString("query?.refundable_amount", $source);
        $this->assertStringNotContainsString('price.value * quantity.value', $source);
    }

    public function testOrderRefundEntryUsesItemSheetInsteadOfSystemActionSheet(): void
    {
        $listSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages/order/index.vue');
        $detailSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/pages-sub/order/detail.vue');
        $sheetSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/components/mb-refund-item-sheet/mb-refund-item-sheet.vue');

        $this->assertIsString($listSource);
        $this->assertIsString($detailSource);
        $this->assertIsString($sheetSource);

        foreach ([$listSource, $detailSource] as $source) {
            $this->assertStringContainsString('mb-refund-item-sheet', $source);
            $this->assertStringContainsString('openRefundSheet', $source);
            $this->assertStringContainsString('@confirm="onRefundItemsConfirm"', $source);
            $this->assertStringContainsString('selected_items=', $source);
            $this->assertStringNotContainsString('uni.showActionSheet', $source);
            $this->assertStringNotContainsString('receive_status=', $source);
            $this->assertStringNotContainsString('refundable_amount=', $source);
        }

        $this->assertStringContainsString('选择售后商品', $sheetSource);
        $this->assertStringContainsString('勾选商品并选择申请数量', $sheetSource);
        $this->assertStringContainsString('mb-refund-sheet__stepper', $sheetSource);
        $this->assertStringContainsString('全选可退', $sheetSource);
    }

    public function testRefundBatchApplyApiContract(): void
    {
        $apiSource = file_get_contents(__DIR__ . '/../../../../../frontend/uniapp/api/order/refund.js');
        $routeSource = file_get_contents(__DIR__ . '/../../../../../backend/route/api/client/refund.php');
        $controllerSource = file_get_contents(__DIR__ . '/../../../../../backend/app/controller/client/order/RefundOrderController.php');
        $serviceSource = file_get_contents(__DIR__ . '/../../../../../backend/app/service/client/order/RefundService.php');
        $validateSource = file_get_contents(__DIR__ . '/../../../../../backend/app/validate/client/order/RefundValidate.php');

        $this->assertIsString($apiSource);
        $this->assertIsString($routeSource);
        $this->assertIsString($controllerSource);
        $this->assertIsString($serviceSource);
        $this->assertIsString($validateSource);

        $this->assertStringContainsString('applyRefundBatch', $apiSource);
        $this->assertStringContainsString('/client/api/refund/batchApply', $apiSource);
        $this->assertStringContainsString("Route::post('batchApply', 'batchApply')", $routeSource);
        $this->assertStringContainsString('public function batchApply()', $controllerSource);
        $this->assertStringContainsString('applyBatch', $serviceSource);
        $this->assertStringContainsString('normalizeBatchItems', $serviceSource);
        $this->assertStringContainsString('sceneBatchApply', $validateSource);
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
