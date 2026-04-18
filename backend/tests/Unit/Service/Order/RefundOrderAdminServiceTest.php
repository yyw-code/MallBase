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
 * 完整的 approve/reject 事务路径（状态机 + 库存 + 乐观锁）依赖 MySQL，
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
}
