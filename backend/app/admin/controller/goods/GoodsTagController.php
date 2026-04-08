<?php
declare(strict_types=1);

namespace app\admin\controller\goods;

use app\admin\service\goods\GoodsTagService;
use app\admin\validate\goods\GoodsTagValidate;
use mall_base\base\BaseController;

/**
 * 商品标签控制器
 * @extends BaseController<GoodsTagService>
 */
class GoodsTagController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = GoodsTagService::class;

    /**
     * 获取标签列表
     */
    public function list()
    {
        $where = $this->request->param(['name', 'status']);

        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    /**
     * 获取标签详情
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
     * 获取所有启用标签
     */
    public function getAllTags()
    {
        $result = $this->service()->getAllTags();
        return $this->success($result, '获取成功');
    }

    /**
     * 创建标签
     */
    public function create()
    {
        $data = $this->request->param([
            'name', 'color', 'sort', 'status',
        ]);

        $this->validate($data, GoodsTagValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新标签
     */
    public function update()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param([
            'name', 'color', 'sort', 'status',
        ]);

        $this->validate($data, GoodsTagValidate::class . '.update');

        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除标签
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
     * 更新标签状态
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
