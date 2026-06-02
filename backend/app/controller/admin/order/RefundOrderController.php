<?php
declare(strict_types=1);

namespace app\controller\admin\order;

use app\service\admin\order\RefundOrderAdminService;
use app\service\order\OrderSettingService;
use app\common\enum\RefundOrderStatus;
use app\common\enum\RefundReason;
use mall_base\base\BaseController;
use mall_base\exception\BusinessException;

/**
 * 后台售后控制器
 *
 * 约束：
 *  - 管理员身份来自 JwtAuth 中间件注入的 request->admin_id
 *  - 审核流转委托 RefundOrderAdminService → RefundOrderStatusMachine
 *
 * @extends BaseController<RefundOrderAdminService>
 */
class RefundOrderController extends BaseController
{
    protected string $serviceClass = RefundOrderAdminService::class;

    /**
     * 售后列表（分页 + 筛选）
     */
    public function list()
    {
        $filter = $this->request->param([
            'sn', 'order_sn', 'status', 'type', 'user_phone',
            'created_start', 'created_end',
            'reviewed_start', 'reviewed_end',
        ]);
        [$page, $pageSize] = $this->getPagination(1, 15);

        return $this->success($this->service()->adminList($filter, $page, $pageSize), '获取成功');
    }

    /**
     * 售后详情
     */
    public function detail($id)
    {
        return $this->success($this->service()->adminDetail((int) $id), '获取成功');
    }

    /**
     * 审核同意
     */
    public function approve($id)
    {
        $adminId = (int) ($this->request->admin_id ?? 0);
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }
        $adminRemark = (string) $this->request->param('admin_remark', '');

        $this->service()->approve(
            refundId: (int) $id,
            adminId: $adminId,
            adminRemark: $adminRemark,
        );
        return $this->success(null, '审核通过');
    }

    /**
     * 更新物流拦截状态（已发货未收到货仅退款专用）
     */
    public function updateIntercept($id)
    {
        $adminId = (int) ($this->request->admin_id ?? 0);
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }

        $this->service()->updateIntercept(
            refundId: (int) $id,
            status: (string) $this->request->param('intercept_status', ''),
            note: (string) $this->request->param('intercept_note', ''),
        );

        return $this->success(null, '拦截状态已更新');
    }

    /**
     * 确认收到买家退货并发起退款
     */
    public function confirmReturn($id)
    {
        $adminId = (int) ($this->request->admin_id ?? 0);
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }

        $this->service()->confirmReturn(
            refundId: (int) $id,
            adminId: $adminId,
            adminRemark: (string) $this->request->param('admin_remark', ''),
        );

        return $this->success(null, '已确认收货并发起退款');
    }

    /**
     * 审核驳回
     */
    public function reject($id)
    {
        $adminId = (int) ($this->request->admin_id ?? 0);
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }
        $adminRemark = (string) $this->request->param('admin_remark', '');

        $this->service()->reject(
            refundId: (int) $id,
            adminId: $adminId,
            adminRemark: $adminRemark,
        );
        return $this->success(null, '已驳回');
    }

    /**
     * 枚举选项（状态 + 类型 + 原因）
     */
    public function statusOptions()
    {
        return $this->success([
            'status' => RefundOrderStatus::options(),
            'type'   => RefundOrderStatus::typeOptions(),
            'receive_status' => RefundOrderStatus::receiveOptions(),
            'intercept_status' => RefundOrderStatus::interceptOptions(),
            'reason' => RefundReason::options(),
        ], '获取成功');
    }

    /**
     * 售后原因枚举
     */
    public function reasonOptions()
    {
        return $this->success(RefundReason::options(), '获取成功');
    }

    /**
     * 后台常用驳回原因
     */
    public function rejectReasonOptions()
    {
        /** @var OrderSettingService $setting */
        $setting = app()->make(OrderSettingService::class);
        return $this->success($setting->refundRejectReasonOptions(), '获取成功');
    }
}
