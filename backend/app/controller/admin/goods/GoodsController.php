<?php
declare(strict_types=1);

namespace app\controller\admin\goods;

use app\service\admin\goods\GoodsService;
use app\validate\admin\goods\GoodsValidate;
use mall_base\base\BaseController;

/**
 * 商品控制器
 * @extends BaseController<GoodsService>
 */
class GoodsController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = GoodsService::class;

    /**
     * 获取商品列表
     */
    public function list()
    {
        $where = $this->request->param(['keyword', 'category_id', 'brand_id', 'is_on_sale', 'status']);

        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    /**
     * 获取商品详情
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
     * 创建商品
     */
    public function create()
    {
        $data = $this->request->param([
            'name', 'subtitle', 'category_id', 'brand_id',
            'price', 'market_price', 'stock', 'main_image', 'main_video', 'spec_type', 'spec_meta',
            'unit', 'sort', 'description',
            'status', 'is_on_sale', 'is_recommend', 'is_new', 'is_hot',
            'images', 'skus', 'tag_ids',
        ]);

        $this->validate($data, GoodsValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新商品
     */
    public function update()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param([
            'name', 'subtitle', 'category_id', 'brand_id',
            'price', 'market_price', 'stock', 'main_image', 'main_video', 'spec_type', 'spec_meta',
            'unit', 'sort', 'description',
            'status', 'is_on_sale', 'is_recommend', 'is_new', 'is_hot',
            'images', 'skus', 'tag_ids',
        ]);

        $this->validate($data, GoodsValidate::class . '.update');

        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除商品
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
     * 更新商品状态
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
     * 更新商品上架状态
     */
    public function updateOnSale()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['is_on_sale']);

        if (!isset($data['is_on_sale'])) {
            return $this->error('上架状态不能为空');
        }

        $this->service()->updateOnSale((int) $id, (int) $data['is_on_sale']);
        return $this->success(null, '更新成功');
    }
}
