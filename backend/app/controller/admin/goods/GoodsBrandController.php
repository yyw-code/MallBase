<?php
declare(strict_types=1);

namespace app\controller\admin\goods;

use app\service\admin\goods\GoodsBrandService;
use app\validate\admin\goods\GoodsBrandValidate;
use mall_base\base\BaseController;

/**
 * 商品品牌控制器
 * @extends BaseController<GoodsBrandService>
 */
class GoodsBrandController extends BaseController
{
    protected string $serviceClass = GoodsBrandService::class;

    /**
     * 获取品牌列表
     */
    public function list()
    {
        $where = $this->request->param(['name', 'status']);

        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    /**
     * 获取品牌详情
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
     * 获取所有启用品牌
     */
    public function getAllBrands()
    {
        $result = $this->service()->getAllBrands();
        return $this->success($result, '获取成功');
    }

    /**
     * 创建品牌
     */
    public function create()
    {
        $data = $this->request->param([
            'name', 'logo', 'description', 'sort', 'status',
        ]);

        $this->validate($data, GoodsBrandValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新品牌
     */
    public function update()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param([
            'name', 'logo', 'description', 'sort', 'status',
        ]);

        $this->validate($data, GoodsBrandValidate::class . '.update');

        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除品牌
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
     * 更新品牌状态
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
