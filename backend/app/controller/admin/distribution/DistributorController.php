<?php
declare(strict_types=1);

namespace app\controller\admin\distribution;

use app\service\admin\distribution\DistributionManagementService;
use mall_base\base\BaseController;

/**
 * 后台分销员控制器
 *
 * @extends BaseController<DistributionManagementService>
 */
class DistributorController extends BaseController
{
    protected string $serviceClass = DistributionManagementService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'status', 'level_id']);
        [$page, $limit] = $this->getPagination(1, 15);
        return $this->success($this->service()->distributorList($where, $page, $limit), '获取成功');
    }

    public function info($id)
    {
        return $this->success($this->service()->distributorInfo((int) $id), '获取成功');
    }

    public function open()
    {
        $data = $this->request->param(['user_id', 'level_id', 'remark']);
        $id = $this->service()->openDistributor(
            userId: (int) ($data['user_id'] ?? 0),
            levelId: (int) ($data['level_id'] ?? 1),
            adminId: (int) ($this->request->admin_id ?? 0),
            remark: (string) ($data['remark'] ?? ''),
        );

        return $this->success(['id' => $id], '开通成功');
    }

    public function updateStatus($id)
    {
        $this->service()->updateDistributorStatus(
            userId: (int) $id,
            status: (int) $this->request->param('status'),
            adminId: (int) ($this->request->admin_id ?? 0),
        );

        return $this->success(null, '更新成功');
    }
}
