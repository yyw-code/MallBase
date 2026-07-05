<?php
declare(strict_types=1);

namespace app\controller\admin\distribution;

use app\service\admin\distribution\DistributionManagementService;
use mall_base\base\BaseController;

/**
 * 后台分销佣金控制器
 *
 * @extends BaseController<DistributionManagementService>
 */
class CommissionController extends BaseController
{
    protected string $serviceClass = DistributionManagementService::class;

    public function list()
    {
        $where = $this->request->param(['order_sn', 'distributor_user_id', 'buyer_user_id', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);
        return $this->success($this->service()->commissionList($where, $page, $limit), '获取成功');
    }

    public function logs()
    {
        $where = $this->request->param(['user_id', 'biz_type', 'direction']);
        [$page, $limit] = $this->getPagination(1, 15);
        return $this->success($this->service()->logList($where, $page, $limit), '获取成功');
    }

    public function adjust()
    {
        $data = $this->request->param(['user_id', 'direction', 'amount', 'remark']);
        $this->service()->adjustCommission(
            userId: (int) ($data['user_id'] ?? 0),
            direction: (string) ($data['direction'] ?? ''),
            amount: (string) ($data['amount'] ?? ''),
            remark: (string) ($data['remark'] ?? ''),
            adminId: (int) ($this->request->admin_id ?? 0),
        );

        return $this->success(null, '调整成功');
    }
}
