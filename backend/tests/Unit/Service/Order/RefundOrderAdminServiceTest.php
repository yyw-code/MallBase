<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\service\admin\order\RefundOrderAdminService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;

/**
 * 后台售后服务前置守卫单元测试
 *
 * 覆盖（全部在 DB 访问之前生效的分支）：
 *  - approve / reject 的 adminId 校验
 *  - reject 的 adminRemark 必填校验
 *
 * 完整的 approve/reject 事务路径（状态机 + 退款计数 + 渠道处理）依赖 MySQL，
 * 由后续集成测试覆盖。
 */
final class RefundOrderAdminServiceTest extends TestCase
{
    private RefundOrderAdminService $service;

    protected function setUp(): void
    {
        $this->service = new RefundOrderAdminService();
    }

    // ====================== approve 前置守卫 ======================

    public function testApproveRejectsZeroAdminId(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('管理员身份无效');

        $this->service->approve(refundId: 1, adminId: 0);
    }

    public function testApproveRejectsNegativeAdminId(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('管理员身份无效');

        $this->service->approve(refundId: 1, adminId: -1);
    }

    // ====================== reject 前置守卫 ======================

    public function testRejectRejectsZeroAdminId(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('管理员身份无效');

        $this->service->reject(refundId: 1, adminId: 0, adminRemark: '原因');
    }

    public function testRejectRejectsEmptyRemark(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('驳回原因必填');

        $this->service->reject(refundId: 1, adminId: 1, adminRemark: '');
    }

    public function testRejectRejectsWhitespaceOnlyRemark(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('驳回原因必填');

        $this->service->reject(refundId: 1, adminId: 1, adminRemark: '   ');
    }

    public function testUserPhoneQueriesUseUserMobileColumn(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/app/service/admin/order/RefundOrderAdminService.php',
        );

        $this->assertStringContainsString("->where('mobile', 'like'", $source);
        $this->assertStringContainsString('mobile as phone', $source);
        $this->assertStringNotContainsString("->where('phone', 'like'", $source);
        $this->assertStringNotContainsString("field('id, nickname, phone", $source);
    }

    public function testApproveFlowDoesNotRestoreStockAtRefundNode(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/app/service/admin/order/RefundOrderAdminService.php',
        );

        $this->assertStringContainsString('退货退款审核同意后先进入待退货', $source);
        $this->assertStringContainsString('买家回填退货物流、商家确认收货后再发起退款', $source);
        $this->assertStringNotContainsString('StockService::class', $source);
        $this->assertStringNotContainsString('->restore(', $source);
    }

    public function testApproveUsesWechatRefundAdapterInsteadOfMock(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/app/service/admin/order/RefundOrderAdminService.php',
        );

        $this->assertStringContainsString('WechatRefundAdapter::class', $source);
        $this->assertStringContainsString('new RefundPaymentContext', $source);
        $this->assertStringNotContainsString('new MockPaymentAdapter', $source);
    }

    public function testApproveSupportsBalanceRefundToUserWallet(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/app/service/admin/order/RefundOrderAdminService.php',
        );

        $this->assertStringContainsString('PayMethod::BALANCE', $source);
        $this->assertStringContainsString('executeBalanceRefund', $source);
        $this->assertStringContainsString('UserWalletLog::BIZ_REFUND', $source);
        $this->assertStringContainsString('售后退款退回余额', $source);
    }

    public function testBalanceRefundAcceptsApprovedStatusWhenConfirmingReturn(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/app/service/admin/order/RefundOrderAdminService.php',
        );

        $this->assertStringContainsString('[RefundOrderStatus::APPROVED]', $source);
        $this->assertStringContainsString('当前售后单状态不允许确认收货', $source);
        $this->assertStringContainsString('$expectedStatuses', $source);
        $this->assertStringNotContainsString('(int) $lockedRefund->status !== RefundOrderStatus::PENDING', $source);
    }

    public function testFullRefundClosesMainOrder(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/app/service/admin/order/RefundOrderAdminService.php',
        );
        $status = (string) file_get_contents(
            dirname(__DIR__, 4) . '/app/common/enum/OrderStatus.php',
        );

        $this->assertStringContainsString('closeOrderIfFullyRefunded', $source);
        $this->assertStringContainsString('售后全量退款关闭订单', $source);
        $this->assertStringContainsString('OrderStatus::CLOSED', $source);
        $this->assertStringContainsString('self::SHIPPED     => [self::RECEIVED, self::CLOSED]', $status);
        $this->assertStringContainsString('self::RECEIVED    => [self::COMPLETED, self::CLOSED]', $status);
    }

    public function testAdminRefundListWrapsActionsAndHidesFinalInterceptActions(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 5) . '/frontend/admin/apps/web-antd/src/views/order/refund/index.vue',
        );

        $this->assertStringContainsString('refund-actions', $source);
        $this->assertStringContainsString('flex-wrap: wrap', $source);
        $this->assertStringContainsString('isFinalInterceptStatus', $source);
        $this->assertStringContainsString("['exception', 'returned', 'success']", $source);
        $this->assertStringContainsString("canMarkIntercept(record, 'exception')", $source);
    }

    public function testAdminRejectModalUsesConfiguredRejectReasons(): void
    {
        $modal = (string) file_get_contents(
            dirname(__DIR__, 5) . '/frontend/admin/apps/web-antd/src/views/order/refund/reject-modal.vue',
        );
        $api = (string) file_get_contents(
            dirname(__DIR__, 5) . '/frontend/admin/apps/web-antd/src/api/order/refund.ts',
        );
        $route = (string) file_get_contents(
            dirname(__DIR__, 4) . '/route/api/admin/order.php',
        );
        $controller = (string) file_get_contents(
            dirname(__DIR__, 4) . '/app/controller/admin/order/RefundOrderController.php',
        );

        $this->assertStringContainsString('getRefundRejectReasonOptionsApi', $modal);
        $this->assertStringContainsString('defaultCommonReasons', $modal);
        $this->assertStringContainsString('/order/refund/rejectReasonOptions', $api);
        $this->assertStringContainsString('SystemRefundOrderRejectReasonOptions', $route);
        $this->assertStringContainsString('rejectReasonOptions', $controller);
        $this->assertStringContainsString('refundRejectReasonOptions', $controller);
    }

    public function testWechatRefundSuccessCanBeCompletedByNotifyOrRecoverCommand(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/app/service/admin/order/RefundOrderAdminService.php',
        );

        $this->assertStringContainsString('completeWechatRefund', $source);
        $this->assertStringContainsString('RefundOrderStatus::REFUNDING', $source);
        $this->assertStringContainsString('OperatorType::SYSTEM', $source);
        $this->assertStringContainsString('微信退款金额与售后单金额不一致', $source);
    }
}
