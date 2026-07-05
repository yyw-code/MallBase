<?php
declare(strict_types=1);

namespace app\controller\admin\distribution;

use app\service\admin\distribution\DistributionManagementService;
use mall_base\base\BaseController;

/**
 * 后台分销提现控制器
 *
 * @extends BaseController<DistributionManagementService>
 */
class WithdrawController extends BaseController
{
    protected string $serviceClass = DistributionManagementService::class;

    public function list()
    {
        $where = $this->request->param(['user_id', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);
        return $this->success($this->service()->withdrawList($where, $page, $limit), '获取成功');
    }

    public function approve($id)
    {
        $this->service()->approveWithdraw(
            withdrawId: (int) $id,
            adminId: (int) ($this->request->admin_id ?? 0),
            remark: (string) $this->request->param('admin_remark', ''),
        );
        return $this->success(null, '审核通过');
    }

    public function reject($id)
    {
        $this->service()->rejectWithdraw(
            withdrawId: (int) $id,
            adminId: (int) ($this->request->admin_id ?? 0),
            remark: (string) $this->request->param('admin_remark', ''),
        );
        return $this->success(null, '已驳回');
    }
}
