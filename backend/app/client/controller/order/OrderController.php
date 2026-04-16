<?php
declare(strict_types=1);

namespace app\client\controller\order;

use app\client\service\order\OrderService;
use app\client\validate\order\OrderValidate;
use mall_base\base\BaseController;

/**
 * 买家订单控制器
 *
 * 约束：
 *  - 用户身份来自 JwtAuth 中间件注入的 request->user_id，禁止从 body 读取
 *  - 仅做传输层（参数拼装、校验、响应），业务走 OrderService
 *  - 创建订单：同时支持购物车结算和立即购买两种来源，由 source 字段路由
 *
 * @extends BaseController<OrderService>
 */
class OrderController extends BaseController
{
    protected string $serviceClass = OrderService::class;

    /**
     * 创建订单
     *
     * body:
     *  - source: cart | sku
     *  - cart_ids: int[]（source=cart 必填）
     *  - items: [{sku_id, quantity}]（source=sku 必填）
     *  - address_id: int
     *  - buyer_remark: string (optional)
     *  - idempotency_key: string (UUID v4, 强烈建议)
     */
    public function create()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $data = $this->request->param(['source', 'cart_ids', 'items', 'address_id', 'buyer_remark', 'idempotency_key']);
        $this->validate($data, OrderValidate::class . '.create');

        $service        = $this->service();
        $buyerRemark    = isset($data['buyer_remark']) && $data['buyer_remark'] !== '' ? (string) $data['buyer_remark'] : null;
        $idempotencyKey = isset($data['idempotency_key']) && $data['idempotency_key'] !== '' ? (string) $data['idempotency_key'] : null;

        if ((string) $data['source'] === 'cart') {
            $result = $service->createFromCart(
                userId: $userId,
                cartIds: array_map('intval', (array) ($data['cart_ids'] ?? [])),
                addressId: (int) $data['address_id'],
                buyerRemark: $buyerRemark,
                idempotencyKey: $idempotencyKey,
            );
        } else {
            $items = array_map(
                static fn($row): array => [
                    'sku_id'   => (int) ($row['sku_id'] ?? 0),
                    'quantity' => (int) ($row['quantity'] ?? 0),
                ],
                (array) ($data['items'] ?? []),
            );
            $result = $service->createFromSku(
                userId: $userId,
                items: $items,
                addressId: (int) $data['address_id'],
                buyerRemark: $buyerRemark,
                idempotencyKey: $idempotencyKey,
            );
        }

        return $this->success($result, '下单成功');
    }

    /**
     * Mock 支付（正式接入渠道后由 PaymentAdapter 转发）
     */
    public function pay($sn)
    {
        $data = $this->request->param(['pay_method']);
        $this->validate($data, OrderValidate::class . '.pay');

        $result = $this->service()->pay((string) $sn, (int) $data['pay_method']);
        return $this->success($result, '支付成功');
    }

    /**
     * 买家取消订单（仅 PENDING_PAY）
     */
    public function cancel($id)
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $data = $this->request->param(['reason']);
        $this->validate($data, OrderValidate::class . '.cancel');

        $reason = isset($data['reason']) && $data['reason'] !== '' ? (string) $data['reason'] : null;
        $this->service()->cancel($userId, (int) $id, $reason);
        return $this->success(null, '取消成功');
    }

    /**
     * 确认收货（SHIPPED → RECEIVED）
     */
    public function confirmReceive($id)
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $this->service()->confirmReceive($userId, (int) $id);
        return $this->success(null, '确认收货成功');
    }

    /**
     * 我的订单列表（分页）
     */
    public function list()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        [$page, $pageSize] = $this->getPagination();

        $filter = [
            'status' => $this->request->param('status', null),
        ];
        return $this->success($this->service()->list($userId, $filter, $page, $pageSize), '获取成功');
    }

    /**
     * 订单详情
     */
    public function detail($id)
    {
        $userId = (int) ($this->request->user_id ?? 0);
        return $this->success($this->service()->detail($userId, (int) $id), '获取成功');
    }
}
