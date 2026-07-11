<?php
declare(strict_types=1);

namespace app\controller\client\order;

use app\common\enum\PayMethod;
use app\service\client\order\OrderService;
use app\service\client\payment\BalancePayService;
use app\service\client\payment\PrepayService;
use app\validate\client\order\OrderValidate;
use mall_base\base\BaseController;
use mall_base\exception\BusinessException;

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
        $data = $this->request->param(['source', 'cart_ids', 'items', 'address_id', 'buyer_remark', 'idempotency_key', 'use_points', 'points_used']);
        $this->validate($data, OrderValidate::class . '.create');

        $service        = $this->service();
        $buyerRemark    = isset($data['buyer_remark']) && $data['buyer_remark'] !== '' ? (string) $data['buyer_remark'] : null;
        $idempotencyKey = isset($data['idempotency_key']) && $data['idempotency_key'] !== '' ? (string) $data['idempotency_key'] : null;
        $usePoints = !empty($data['use_points']);
        $pointsUsed = max(0, (int) ($data['points_used'] ?? 0));

        if ((string) $data['source'] === 'cart') {
            $result = $service->createFromCart(
                userId: $userId,
                cartIds: array_map('intval', (array) ($data['cart_ids'] ?? [])),
                addressId: (int) $data['address_id'],
                buyerRemark: $buyerRemark,
                idempotencyKey: $idempotencyKey,
                usePoints: $usePoints,
                pointsUsed: $pointsUsed,
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
                usePoints: $usePoints,
                pointsUsed: $pointsUsed,
            );
        }

        return $this->success($result, '下单成功');
    }

    /**
     * 订单试算
     *
     * 与 create 同源入参（不含 buyer_remark / idempotency_key），
     * 返回含运费的服务端权威金额，供确认页展示。不进事务、不扣库存。
     *
     * body:
     *  - source: cart | sku
     *  - cart_ids: int[]（source=cart 必填）
     *  - items: [{sku_id, quantity}]（source=sku 必填）
     *  - address_id: int（必填，运费依赖收货区域）
     */
    public function preview()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $data = $this->request->param(['source', 'cart_ids', 'items', 'address_id', 'use_points', 'points_used']);
        $this->validate($data, OrderValidate::class . '.preview');

        $items = array_map(
            static fn($row): array => [
                'sku_id'   => (int) ($row['sku_id'] ?? 0),
                'quantity' => (int) ($row['quantity'] ?? 0),
            ],
            (array) ($data['items'] ?? []),
        );

        $result = $this->service()->preview(
            userId: $userId,
            source: (string) $data['source'],
            cartIds: array_map('intval', (array) ($data['cart_ids'] ?? [])),
            items: $items,
            addressId: (int) $data['address_id'],
            usePoints: !empty($data['use_points']),
            pointsUsed: max(0, (int) ($data['points_used'] ?? 0)),
        );

        return $this->success($result, '试算成功');
    }

    /**
     * 支付入口
     *
     * - pay_method=3 (BALANCE)：余额支付，同步扣减余额并转已支付
     * - pay_method=1 (WECHAT)：调 PrepayService 发起预下单，返回前端调起参数；
     *   订单真正转 PAID 由微信回调走 NotifyService → OrderService::confirmPaid
     *
     * @param int|string $id 订单 ID（路径参数）
     */
    public function pay($id)
    {
        $orderId = (int) $id;
        if ($orderId <= 0) {
            throw new BusinessException('订单ID无效');
        }

        $data = $this->request->param(['pay_method', 'scene']);
        $this->validate($data, OrderValidate::class . '.pay');

        $userId = (int) ($this->request->user_id ?? 0);
        if ($userId <= 0) {
            throw new BusinessException('未登录');
        }

        $payMethod = (int) $data['pay_method'];

        $enabledMap = [
            PayMethod::WECHAT  => ['payment_wechat_enabled', '微信支付未启用'],
            PayMethod::BALANCE => ['payment_balance_enabled', '余额支付未启用'],
        ];
        if (isset($enabledMap[$payMethod])) {
            [$settingCode, $disabledMessage] = $enabledMap[$payMethod];
            if ((string) getSystemSetting($settingCode, '0') !== '1') {
                throw new BusinessException($disabledMessage);
            }
        }

        if ($payMethod === PayMethod::BALANCE) {
            /** @var BalancePayService $balancePayService */
            $balancePayService = app()->make(BalancePayService::class);
            $result = $balancePayService->payById($userId, $orderId);
            return $this->success($result, '支付成功');
        }

        if ($payMethod === PayMethod::WECHAT) {
            $scene = isset($data['scene']) ? (string) $data['scene'] : '';
            if ($scene === '') {
                throw new BusinessException('请指定支付场景');
            }
            /** @var PrepayService $prepayService */
            $prepayService = app()->make(PrepayService::class);
            $result = $prepayService->prepayById($userId, $orderId, $scene);
            return $this->success($result, '支付参数已生成');
        }

        throw new BusinessException('该支付方式暂未开放');
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
            'sn'     => $this->request->param('sn', null),
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
