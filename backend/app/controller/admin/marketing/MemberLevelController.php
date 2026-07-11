<?php
declare(strict_types=1);

namespace app\controller\admin\marketing;

use app\service\admin\marketing\MemberLevelService;
use app\validate\admin\marketing\MemberLevelValidate;
use mall_base\base\BaseController;

/**
 * 会员等级控制器
 *
 * @extends BaseController<MemberLevelService>
 */
class MemberLevelController extends BaseController
{
    protected string $serviceClass = MemberLevelService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);

        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function info($id)
    {
        $levelId = (int) $id;
        if ($levelId <= 0) {
            return $this->error('ID不能为空');
        }

        return $this->success($this->service()->getInfo($levelId), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['name', 'growth_min', 'discount_percent', 'sort', 'status', 'remark']);
        $this->validate($data, MemberLevelValidate::class . '.create');

        $id = $this->service()->create($data);

        return $this->success(['id' => $id], '创建成功');
    }

    public function update($id)
    {
        $levelId = (int) $id;
        if ($levelId <= 0) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['name', 'growth_min', 'discount_percent', 'sort', 'status', 'remark']);
        $this->validate($data, MemberLevelValidate::class . '.update');
        $this->service()->update($levelId, $data);

        return $this->success(null, '更新成功');
    }

    public function delete($id)
    {
        $levelId = (int) $id;
        if ($levelId <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete($levelId);

        return $this->success(null, '删除成功');
    }

    public function updateStatus($id)
    {
        $levelId = (int) $id;
        if ($levelId <= 0) {
            return $this->error('ID不能为空');
        }

        $status = (int) $this->request->param('status');
        $this->service()->updateStatus($levelId, $status);

        return $this->success(null, '更新成功');
    }
}
