<?php
declare(strict_types=1);

namespace app\controller\client\points;

use app\service\client\points\PointsMallService;
use app\validate\client\points\PointsMallValidate;
use mall_base\base\BaseController;
use mall_base\exception\BusinessException;

/**
 * 前台积分商城控制器
 *
 * @extends BaseController<PointsMallService>
 */
class PointsMallController extends BaseController
{
    protected string $serviceClass = PointsMallService::class;

    public function list()
    {
        $where = $this->request->param(['keyword']);
        [$page, $limit] = $this->getPagination(1, 20);

        return $this->success($this->service()->goodsList($where, $page, $limit), '获取成功');
    }

    public function detail($id)
    {
        $pointsGoodsId = (int) $id;
        if ($pointsGoodsId <= 0) {
            return $this->error('ID不能为空');
        }

        return $this->success($this->service()->goodsDetail($pointsGoodsId), '获取成功');
    }

    public function exchange()
    {
        $userId = $this->userId();
        $data = $this->request->param(['points_goods_id', 'address_id', 'quantity', 'buyer_remark', 'idempotency_key']);
        $this->validate($data, PointsMallValidate::class . '.exchange');

        $result = $this->service()->exchange(
            userId: $userId,
            pointsGoodsId: (int) ($data['points_goods_id'] ?? 0),
            addressId: (int) ($data['address_id'] ?? 0),
            quantity: (int) ($data['quantity'] ?? 1),
            buyerRemark: (string) ($data['buyer_remark'] ?? ''),
            idempotencyKey: (string) ($data['idempotency_key'] ?? ''),
        );

        return $this->success($result, '兑换成功');
    }

    public function orders()
    {
        $userId = $this->userId();
        $where = $this->request->param(['status']);
        [$page, $limit] = $this->getPagination(1, 20);

        return $this->success($this->service()->myOrders($userId, $where, $page, $limit), '获取成功');
    }

    public function order($id)
    {
        $orderId = (int) $id;
        if ($orderId <= 0) {
            return $this->error('ID不能为空');
        }

        return $this->success($this->service()->myOrderDetail($this->userId(), $orderId), '获取成功');
    }

    public function cancel($id)
    {
        $orderId = (int) $id;
        if ($orderId <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->cancelOrder($this->userId(), $orderId);

        return $this->success([], '取消成功');
    }

    private function userId(): int
    {
        $userId = (int) ($this->request->user_id ?? 0);
        if ($userId <= 0) {
            throw new BusinessException('未登录');
        }
        return $userId;
    }
}
