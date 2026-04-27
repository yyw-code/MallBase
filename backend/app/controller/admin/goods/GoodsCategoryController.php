<?php
declare(strict_types=1);

namespace app\controller\admin\goods;

use app\service\admin\goods\GoodsCategoryService;
use app\validate\admin\goods\GoodsCategoryValidate;
use mall_base\base\BaseController;

/**
 * 商品分类控制器
 * @extends BaseController<GoodsCategoryService>
 */
class GoodsCategoryController extends BaseController
{
    protected string $serviceClass = GoodsCategoryService::class;

    /**
     * 获取分类列表
     */
    public function list()
    {
        $where = $this->request->param(['name', 'pid', 'status']);

        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    /**
     * 获取分类树
     */
    public function tree()
    {
        $where = $this->request->param(['name', 'status']);

        $tree = $this->service()->getTree($where);
        return $this->success($tree, '获取成功');
    }

    /**
     * 获取分类详情
     */
    public function info()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $info = $this->service()->getInfo((int) $id);
        return $this->success($info, '获取成功');
    }

    /**
     * 获取所有启用分类（树形结构）
     */
    public function getAllCategories()
    {
        $result = $this->service()->getAllCategories();
        return $this->success($result, '获取成功');
    }

    /**
     * 创建分类
     */
    public function create()
    {
        $data = $this->request->param([
            'pid', 'name', 'icon', 'image', 'description', 'sort', 'status',
        ]);

        $this->validate($data, GoodsCategoryValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新分类
     */
    public function update()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param([
            'pid', 'name', 'icon', 'image', 'description', 'sort', 'status',
        ]);

        $this->validate($data, GoodsCategoryValidate::class . '.update');

        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除分类
     */
    public function delete()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete((int) $id);
        return $this->success(null, '删除成功');
    }

    /**
     * 更新分类状态
     */
    public function updateStatus()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['status']);

        if (!isset($data['status'])) {
            return $this->error('状态不能为空');
        }

        $this->service()->updateStatus((int) $id, (int) $data['status']);
        return $this->success(null, '更新成功');
    }
}
