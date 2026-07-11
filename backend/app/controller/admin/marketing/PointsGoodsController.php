<?php
declare(strict_types=1);

namespace app\controller\admin\marketing;

use app\service\admin\marketing\PointsGoodsService;
use app\validate\admin\marketing\PointsGoodsValidate;
use mall_base\base\BaseController;

/**
 * 后台积分商品控制器
 *
 * @extends BaseController<PointsGoodsService>
 */
class PointsGoodsController extends BaseController
{
    protected string $serviceClass = PointsGoodsService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);

        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function info($id)
    {
        $pointsGoodsId = (int) $id;
        if ($pointsGoodsId <= 0) {
            return $this->error('ID不能为空');
        }

        return $this->success($this->service()->getInfo($pointsGoodsId), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['goods_id', 'sku_id', 'points_price', 'exchange_stock', 'limit_per_user', 'sort', 'status', 'remark']);
        $this->validate($data, PointsGoodsValidate::class . '.create');

        $id = $this->service()->create($data);

        return $this->success(['id' => $id], '创建成功');
    }

    public function update($id)
    {
        $pointsGoodsId = (int) $id;
        if ($pointsGoodsId <= 0) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['goods_id', 'sku_id', 'points_price', 'exchange_stock', 'limit_per_user', 'sort', 'status', 'remark']);
        $this->validate($data, PointsGoodsValidate::class . '.update');
        $this->service()->update($pointsGoodsId, $data);

        return $this->success(null, '更新成功');
    }

    public function delete($id)
    {
        $pointsGoodsId = (int) $id;
        if ($pointsGoodsId <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete($pointsGoodsId);

        return $this->success(null, '删除成功');
    }

    public function updateStatus($id)
    {
        $pointsGoodsId = (int) $id;
        if ($pointsGoodsId <= 0) {
            return $this->error('ID不能为空');
        }

        $status = (int) $this->request->param('status');
        $this->service()->updateStatus($pointsGoodsId, $status);

        return $this->success(null, '更新成功');
    }
}
