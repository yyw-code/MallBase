<?php
declare(strict_types=1);

namespace app\controller\admin\distribution;

use app\service\admin\distribution\DistributionManagementService;
use mall_base\base\BaseController;

/**
 * 后台分销员申请控制器
 *
 * @extends BaseController<DistributionManagementService>
 */
class ApplyController extends BaseController
{
    protected string $serviceClass = DistributionManagementService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);
        return $this->success($this->service()->applyList($where, $page, $limit), '获取成功');
    }

    public function approve($id)
    {
        $this->service()->approveApply(
            applyId: (int) $id,
            adminId: (int) ($this->request->admin_id ?? 0),
            levelId: (int) $this->request->param('level_id', 0),
            remark: (string) $this->request->param('review_remark', ''),
        );

        return $this->success(null, '审核通过');
    }

    public function reject($id)
    {
        $this->service()->rejectApply(
            applyId: (int) $id,
            adminId: (int) ($this->request->admin_id ?? 0),
            remark: (string) $this->request->param('review_remark', ''),
        );

        return $this->success(null, '审核驳回');
    }
}
