<?php
declare(strict_types=1);

namespace app\controller\admin\distribution;

use app\service\admin\distribution\DistributionManagementService;
use mall_base\base\BaseController;

/**
 * 后台分销员等级控制器
 *
 * @extends BaseController<DistributionManagementService>
 */
class LevelController extends BaseController
{
    protected string $serviceClass = DistributionManagementService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);
        return $this->success($this->service()->levelList($where, $page, $limit), '获取成功');
    }

    public function info($id)
    {
        return $this->success($this->service()->levelInfo((int) $id), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['name', 'first_rate', 'second_rate', 'sort', 'status', 'remark']);
        return $this->success(['id' => $this->service()->createLevel($data)], '创建成功');
    }

    public function update($id)
    {
        $data = $this->request->param(['name', 'first_rate', 'second_rate', 'sort', 'status', 'remark']);
        $this->service()->updateLevel((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    public function delete($id)
    {
        $this->service()->deleteLevel((int) $id);
        return $this->success(null, '删除成功');
    }

    public function updateStatus($id)
    {
        $this->service()->updateLevelStatus((int) $id, (int) $this->request->param('status'));
        return $this->success(null, '更新成功');
    }
}
