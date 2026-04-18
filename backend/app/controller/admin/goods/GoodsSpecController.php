<?php
declare(strict_types=1);

namespace app\controller\admin\goods;

use app\service\admin\goods\GoodsSpecService;
use app\validate\admin\goods\GoodsSpecValidate;
use mall_base\base\BaseController;

/**
 * 商品规格控制器
 * @extends BaseController<GoodsSpecService>
 */
class GoodsSpecController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = GoodsSpecService::class;

    /**
     * 获取规格列表
     */
    public function list()
    {
        $where = $this->request->param(['name', 'status']);

        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    /**
     * 获取规格详情
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
     * 获取所有启用规格（供商品表单使用）
     */
    public function getAllSpecs()
    {
        $result = $this->service()->getAllSpecs();
        return $this->success($result, '获取成功');
    }

    /**
     * 创建规格组
     */
    public function create()
    {
        $data = $this->request->param([
            'name', 'description', 'sort', 'status',
        ]);

        $this->validate($data, GoodsSpecValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新规格组
     */
    public function update()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param([
            'name', 'description', 'sort', 'status',
        ]);

        $this->validate($data, GoodsSpecValidate::class . '.update');

        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除规格组
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
     * 更新规格状态
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

    /**
     * 添加规格值
     */
    public function createSpecValue()
    {
        $data = $this->request->param(['spec_id', 'value', 'sort']);

        $this->validate($data, GoodsSpecValidate::class . '.specValue');

        $id = $this->service()->createSpecValue((int) $data['spec_id'], $data['value']);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 批量添加规格值
     */
    public function batchCreateSpecValues()
    {
        $data = $this->request->param(['spec_id', 'values']);

        $this->validate($data, GoodsSpecValidate::class . '.batchSpecValues');

        $ids = $this->service()->batchCreateSpecValues((int) $data['spec_id'], $data['values']);
        return $this->success(['ids' => $ids], '创建成功');
    }

    /**
     * 删除规格值
     */
    public function deleteSpecValue()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $this->service()->deleteSpecValue((int) $id);
        return $this->success(null, '删除成功');
    }
}
