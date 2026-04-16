<?php
declare(strict_types=1);

namespace app\admin\controller\order;

use app\admin\service\order\OrderAdminService;
use app\common\enum\OrderStatus;
use app\common\enum\PayMethod;
use mall_base\base\BaseController;
use mall_base\exception\BusinessException;

/**
 * 后台订单控制器
 *
 * 约束：
 *  - 操作人身份来自 JwtAuth 中间件注入的 request->admin_id
 *  - 状态流转统一由 OrderAdminService 委托 OrderStatusMachine
 *  - 列表接口 after_sale_tag_text 由 Service 实时聚合 refund_order，不落库
 *
 * @extends BaseController<OrderAdminService>
 */
class OrderController extends BaseController
{
    protected string $serviceClass = OrderAdminService::class;

    /**
     * 订单列表（分页 + 筛选）
     *
     * 筛选字段：sn / status / user_id / logistics_sn / created_start / created_end / has_after_sale
     */
    public function list()
    {
        $filter = $this->request->param([
            'sn', 'status', 'user_id', 'logistics_sn',
            'created_start', 'created_end', 'has_after_sale',
        ]);
        [$page, $pageSize] = $this->getPagination(1, 15);

        return $this->success($this->service()->adminList($filter, $page, $pageSize), '获取成功');
    }

    /**
     * 订单详情
     */
    public function detail($id)
    {
        return $this->success($this->service()->adminDetail((int) $id), '获取成功');
    }

    /**
     * 发货（PAID → SHIPPED）
     */
    public function ship($id)
    {
        $adminId = (int) ($this->request->admin_id ?? 0);
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }
        $data = $this->request->param(['logistics_company', 'logistics_sn']);

        $this->service()->ship(
            orderId: (int) $id,
            logisticsCompany: (string) ($data['logistics_company'] ?? ''),
            logisticsSn: (string) ($data['logistics_sn'] ?? ''),
            adminId: $adminId,
        );
        return $this->success(null, '发货成功');
    }

    /**
     * 关闭订单（PENDING_PAY / PAID 可关闭，同步回滚库存）
     */
    public function close($id)
    {
        $adminId = (int) ($this->request->admin_id ?? 0);
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }
        $reason = (string) $this->request->param('reason', '');

        $this->service()->closeOrder(
            orderId: (int) $id,
            adminId: $adminId,
            reason: $reason !== '' ? $reason : null,
        );
        return $this->success(null, '关闭成功');
    }

    /**
     * 枚举选项（前端下拉用）
     *
     * 单接口返回 status / pay_method，避免前端多次请求
     */
    public function statusOptions()
    {
        return $this->success([
            'status'     => OrderStatus::options(),
            'pay_method' => PayMethod::options(),
        ], '获取成功');
    }
}
