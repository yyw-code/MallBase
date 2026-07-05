<?php
declare(strict_types=1);

namespace app\controller\admin\distribution;

use app\service\admin\distribution\DistributionManagementService;
use mall_base\base\BaseController;

/**
 * 后台分销佣金规则控制器
 *
 * @extends BaseController<DistributionManagementService>
 */
class RuleController extends BaseController
{
    protected string $serviceClass = DistributionManagementService::class;

    public function list()
    {
        $where = $this->request->param(['target_type', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);
        return $this->success($this->service()->ruleList($where, $page, $limit), '获取成功');
    }

    public function info($id)
    {
        return $this->success($this->service()->ruleInfo((int) $id), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['target_type', 'target_id', 'name', 'first_rate', 'second_rate', 'status', 'remark']);
        return $this->success(['id' => $this->service()->createRule($data)], '创建成功');
    }

    public function update($id)
    {
        $data = $this->request->param(['target_type', 'target_id', 'name', 'first_rate', 'second_rate', 'status', 'remark']);
        $this->service()->updateRule((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    public function delete($id)
    {
        $this->service()->deleteRule((int) $id);
        return $this->success(null, '删除成功');
    }

    public function updateStatus($id)
    {
        $this->service()->updateRuleStatus((int) $id, (int) $this->request->param('status'));
        return $this->success(null, '更新成功');
    }
}
