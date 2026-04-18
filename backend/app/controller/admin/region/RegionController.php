<?php
declare(strict_types=1);

namespace app\controller\admin\region;

use app\service\admin\region\RegionService;
use app\validate\admin\region\RegionValidate;
use mall_base\base\BaseController;

/**
 * @extends BaseController<RegionService>
 */
class RegionController extends BaseController
{
    protected string $serviceClass = RegionService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'level', 'status', 'parent_id']);
        [$page, $limit] = $this->getPagination(1, 20);
        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function children()
    {
        $parentId = (int) $this->request->param('parent_id', 0);
        return $this->success($this->service()->getChildren($parentId), '获取成功');
    }

    public function path($id)
    {
        return $this->success($this->service()->getPath((int) $id), '获取成功');
    }

    public function info($id)
    {
        return $this->success($this->service()->getInfo((int) $id), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['parent_id', 'code', 'name', 'level', 'status', 'sort']);
        $this->validate($data, RegionValidate::class . '.create');
        return $this->success(['id' => $this->service()->create($data)], '创建成功');
    }

    public function update($id)
    {
        $data = $this->request->param(['parent_id', 'code', 'name', 'level', 'status', 'sort']);
        $this->validate($data, RegionValidate::class . '.update');
        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    public function updateStatus($id)
    {
        $data = $this->request->param(['status']);
        $this->validate($data, RegionValidate::class . '.status');
        $this->service()->updateStatus((int) $id, (int) $data['status']);
        return $this->success(null, '更新成功');
    }

    public function delete($id)
    {
        $this->service()->delete((int) $id);
        return $this->success(null, '删除成功');
    }
}
