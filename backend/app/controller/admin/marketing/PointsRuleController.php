<?php
declare(strict_types=1);

namespace app\controller\admin\marketing;

use app\service\admin\marketing\PointsRuleService;
use app\validate\admin\marketing\PointsRuleValidate;
use mall_base\base\BaseController;

/**
 * 积分规则控制器
 *
 * @extends BaseController<PointsRuleService>
 */
class PointsRuleController extends BaseController
{
    protected string $serviceClass = PointsRuleService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'scene', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);

        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function info()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        return $this->success($this->service()->getInfo($id), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['scene', 'name', 'description', 'points_per_yuan', 'fixed_points', 'max_points', 'sort', 'status', 'remark']);
        $this->validate($data, PointsRuleValidate::class . '.create');

        $id = $this->service()->create($data);

        return $this->success(['id' => $id], '创建成功');
    }

    public function update()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['scene', 'name', 'description', 'points_per_yuan', 'fixed_points', 'max_points', 'sort', 'status', 'remark']);
        $this->validate($data, PointsRuleValidate::class . '.update');
        $this->service()->update($id, $data);

        return $this->success(null, '更新成功');
    }

    public function delete()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete($id);

        return $this->success(null, '删除成功');
    }

    public function updateStatus()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $status = (int) $this->request->param('status');
        $this->service()->updateStatus($id, $status);

        return $this->success(null, '更新成功');
    }

    public function scenes()
    {
        return $this->success($this->service()->sceneOptions(), '获取成功');
    }
}
