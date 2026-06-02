<?php

declare(strict_types=1);

namespace app\service\client\order;

use app\model\order\Order;
use app\model\order\OrderItem;
use app\model\order\RefundOrder;
use app\service\order\OrderSettingService;
use app\service\order\RefundOrderStatusMachine;
use app\service\order\RefundSnGenerator;
use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use app\common\enum\RefundReason;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 买家售后服务（申请 / 取消 / 列表 / 详情）
 *
 * 原则：
 *  - 「先校验再事务」：订单项归属、订单状态、数量上限、重复申请全部放事务外
 *  - 事务内只做：生成 sn → 落库 RefundOrder
 *  - 状态流转统一走 {@see RefundOrderStatusMachine}，禁止直接 $refund->status = X
 *  - 售后类型与收货状态强绑定，退货退款必须先由商家审核，再由买家回填退货物流
 *
 * @extends BaseService<RefundOrder>
 */
class RefundService extends BaseService
{
    protected string $modelClass = RefundOrder::class;

    /**
     * 允许发起售后的主订单状态白名单
     *
     * 未支付单通过取消订单路径走关闭流程，不进售后；已关闭单不可再售后
     */
    private const ORDERABLE_STATUSES = [
        OrderStatus::PAID,
        OrderStatus::SHIPPED,
        OrderStatus::RECEIVED,
        OrderStatus::COMPLETED,
    ];

    /**
     * 判定「同一订单项存在活跃售后」用的状态集合
     */
    private const ACTIVE_REFUND_STATUSES = [
        RefundOrderStatus::PENDING,
        RefundOrderStatus::APPROVED,
        RefundOrderStatus::REFUNDING,
    ];

    /**
     * 买家发起售后申请
     *
     * @param int                  $userId    登录买家
     * @param array<string, mixed> $payload   请求参数：order_item_id / quantity / type / reason / remark
     *
     * @return array{id:int, sn:string, status:int, refund_amount:string}
     */
    public function apply(int $userId, array $payload): array
    {
        $this->assertUserId($userId);

        $orderItemId = (int) ($payload['order_item_id'] ?? 0);
        $quantity    = (int) ($payload['quantity'] ?? 0);
        $type        = (int) ($payload['type'] ?? RefundOrderStatus::TYPE_REFUND_ONLY);
        $receiveStatus = (int) ($payload['receive_status'] ?? RefundOrderStatus::RECEIVE_NOT_RECEIVED);
        $reason      = (string) ($payload['reason'] ?? '');
        $remark      = isset($payload['remark']) && $payload['remark'] !== ''
            ? mb_substr((string) $payload['remark'], 0, 255)
            : null;

        if ($orderItemId <= 0) {
            throw new BusinessException('缺少订单项参数');
        }
        if ($quantity <= 0) {
            throw new BusinessException('申请数量必须大于 0');
        }
        if (!in_array($type, [RefundOrderStatus::TYPE_REFUND_ONLY, RefundOrderStatus::TYPE_RETURN_REFUND], true)) {
            throw new BusinessException('售后类型不合法');
        }
        if (!in_array($receiveStatus, [RefundOrderStatus::RECEIVE_NOT_RECEIVED, RefundOrderStatus::RECEIVE_RECEIVED], true)) {
            throw new BusinessException('收货状态不合法');
        }
        if (!RefundReason::isValid($reason)) {
            throw new BusinessException('售后原因不合法');
        }

        [$order, $item] = $this->assertOwnedOrderItem($userId, $orderItemId);
        $this->assertOrderRefundable($order);
        $this->assertRefundScenario($order, $type, $receiveStatus);
        $this->assertQuantityLimit($item, $quantity);
        $this->assertNoActiveRefund($orderItemId);

        $refundAmount = $this->calcRefundAmount($order, $item, $quantity);

        /** @var RefundSnGenerator $snGen */
        $snGen = app()->make(RefundSnGenerator::class);

        /** @var RefundOrder $refund */
        $refund = $this->transaction(function () use (
            $userId, $order, $item, $orderItemId, $quantity, $type, $receiveStatus, $reason, $remark, $refundAmount, $snGen
        ) {
            /** @var RefundOrder $refund */
            $refund = $this->model()->create([
                'sn'            => $snGen->next(),
                'order_id'      => (int) $order['id'],
                'order_item_id' => $orderItemId,
                'user_id'       => $userId,
                'type'          => $type,
                'receive_status'=> $receiveStatus,
                'status'        => RefundOrderStatus::PENDING,
                'quantity'      => $quantity,
                'refund_amount' => $refundAmount,
                'reason'        => $reason,
                'remark'        => $remark,
                'intercept_status' => $this->defaultInterceptStatus((int) $order['status'], $type, $receiveStatus),
            ]);
            return $refund;
        });

        return [
            'id'            => (int) $refund->id,
            'sn'            => (string) $refund->sn,
            'status'        => (int) $refund->status,
            'refund_amount' => (string) $refund->refund_amount,
        ];
    }

    /**
     * 买家取消（撤销）售后申请（仅 PENDING 可撤销）
     */
    public function cancel(int $userId, int $refundId): void
    {
        $this->assertUserId($userId);
        $refund = $this->findOwnedRefund($userId, $refundId);

        if ((int) $refund->status !== RefundOrderStatus::PENDING) {
            throw new BusinessException('当前售后单状态不允许撤销');
        }

        app()->make(RefundOrderStatusMachine::class)->transit(
            refund: $refund,
            toStatus: RefundOrderStatus::CLOSED,
            operatorType: OperatorType::BUYER,
            operatorId: $userId,
            remark: '买家撤销申请',
        );
    }

    public function submitReturnShipment(int $userId, int $refundId, string $company, string $trackingNo): void
    {
        $this->assertUserId($userId);
        $company = trim($company);
        $trackingNo = trim($trackingNo);
        if ($company === '' || $trackingNo === '') {
            throw new BusinessException('请填写退货物流公司和物流单号');
        }

        $refund = $this->findOwnedRefund($userId, $refundId);
        if ((int) $refund->type !== RefundOrderStatus::TYPE_RETURN_REFUND) {
            throw new BusinessException('仅退货退款需要填写退货物流');
        }
        if ((int) $refund->status !== RefundOrderStatus::APPROVED) {
            throw new BusinessException('商家同意退货后才能填写退货物流');
        }
        if ((string) ($refund->return_tracking_no ?? '') !== '') {
            throw new BusinessException('退货物流已提交');
        }

        $refund->return_company = mb_substr($company, 0, 50);
        $refund->return_tracking_no = mb_substr($trackingNo, 0, 64);
        $refund->return_shipped_at = date('Y-m-d H:i:s');
        $refund->save();
    }

    /**
     * 我的售后列表（分页）
     *
     * 条件同源：先 count 再 select，builder 通过 clone 复用
     *
     * @param array{status?:int|null, start_time?:string|null, end_time?:string|null} $filter
     * @return array{total:int, list:array<int, array<string, mixed>>}
     */
    public function list(int $userId, array $filter = [], int $page = 1, int $pageSize = 10): array
    {
        $this->assertUserId($userId);

        $query = $this->model()
            ->where('user_id', $userId)
            ->whereNull('delete_time');

        if (isset($filter['status']) && $filter['status'] !== null && $filter['status'] !== '') {
            $query->where('status', (int) $filter['status']);
        }
        if (!empty($filter['start_time'])) {
            $query->where('create_time', '>=', (string) $filter['start_time']);
        }
        if (!empty($filter['end_time'])) {
            $query->where('create_time', '<=', (string) $filter['end_time']);
        }

        $total = (clone $query)->count();
        $list  = $query
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $this->hydrateListRelations($list);

        return compact('total', 'list');
    }

    /**
     * 售后详情（含订单摘要、订单项快照）
     */
    public function detail(int $userId, int $refundId): array
    {
        $this->assertUserId($userId);
        $refund = $this->findOwnedRefund($userId, $refundId);

        $data = $refund->toArray();

        // 关联主订单摘要
        $orderModel = $this->model(Order::class)
            ->where('id', (int) $refund->order_id)
            ->field('id, sn, status, pay_amount, receiver_name, receiver_phone, create_time, paid_at, shipped_at, received_at')
            ->find();
        $order = $orderModel?->toArray();
        if ($order !== null) {
            $order['status_text'] = OrderStatus::textOf((int) $order['status']);
        }
        $data['order'] = $order;

        // 关联订单项快照
        $orderItemId = (int) ($refund->order_item_id ?? 0);
        if ($orderItemId > 0) {
            $itemModel = $this->model(OrderItem::class)->where('id', $orderItemId)->find();
            $item = $itemModel?->toArray();
            if ($item !== null) {
                $item['goods_image_full_url'] = buildUploadUrl((string) ($item['goods_image'] ?? ''));
            }
            $data['order_item'] = $item;
        } else {
            $data['order_item'] = null;
        }

        // 原因文案（前端展示用）
        $data['reason_text'] = RefundReason::textOf((string) ($refund->reason ?? ''));
        $data['return_receiver'] = $this->returnReceiver();

        return $data;
    }

    // ---------------- 内部：校验 / 装配 ----------------

    /**
     * 校验订单项归属并返回 [订单, 订单项]
     *
     * @return array{0:array<string, mixed>, 1:array<string, mixed>}
     */
    private function assertOwnedOrderItem(int $userId, int $orderItemId): array
    {
        $itemModel = $this->model(OrderItem::class)->where('id', $orderItemId)->find();
        if ($itemModel === null) {
            throw new BusinessException('订单商品不存在');
        }
        $item = $itemModel->toArray();

        $orderModel = $this->model(Order::class)
            ->where('id', (int) $item['order_id'])
            ->whereNull('delete_time')
            ->find();
        if ($orderModel === null) {
            throw new BusinessException('订单不存在');
        }
        $order = $orderModel->toArray();
        if ((int) $order['user_id'] !== $userId) {
            // 严防越权：不暴露具体归属，只给通用错误
            throw new BusinessException('订单不存在');
        }

        return [$order, $item];
    }

    /**
     * 订单当前状态必须允许发起售后
     *
     * @param array<string, mixed> $order
     */
    private function assertOrderRefundable(array $order): void
    {
        $status = (int) ($order['status'] ?? 0);
        if (!in_array($status, self::ORDERABLE_STATUSES, true)) {
            throw new BusinessException('当前订单状态不允许发起售后');
        }

        $afterSaleDays = $this->afterSaleDays();
        if ($afterSaleDays <= 0) {
            return;
        }

        $receivedAt = (string) ($order['received_at'] ?? '');
        if ($receivedAt === '') {
            return;
        }

        if (strtotime($receivedAt) + ($afterSaleDays * 86400) < time()) {
            throw new BusinessException('订单已超过售后申请期限');
        }
    }

    /**
     * 严谨售后场景：
     * - 待发货：仅退款，未收到货
     * - 待收货：未收到货可仅退款；已收到货可仅退款或退货退款
     * - 已收货/已完成：视为已收到货，可仅退款或退货退款
     */
    private function assertRefundScenario(array $order, int $type, int $receiveStatus): void
    {
        $status = (int) ($order['status'] ?? 0);

        if ($type === RefundOrderStatus::TYPE_RETURN_REFUND && $receiveStatus !== RefundOrderStatus::RECEIVE_RECEIVED) {
            throw new BusinessException('退货退款仅适用于已收到货的订单');
        }

        if ($status === OrderStatus::PAID) {
            if ($type !== RefundOrderStatus::TYPE_REFUND_ONLY || $receiveStatus !== RefundOrderStatus::RECEIVE_NOT_RECEIVED) {
                throw new BusinessException('待发货订单仅支持未收到货仅退款');
            }
            return;
        }

        if (in_array($status, [OrderStatus::RECEIVED, OrderStatus::COMPLETED], true)
            && $receiveStatus !== RefundOrderStatus::RECEIVE_RECEIVED) {
            throw new BusinessException('已收货订单请选择已收到货');
        }
    }

    private function defaultInterceptStatus(int $orderStatus, int $type, int $receiveStatus): string
    {
        if ($orderStatus === OrderStatus::SHIPPED
            && $type === RefundOrderStatus::TYPE_REFUND_ONLY
            && $receiveStatus === RefundOrderStatus::RECEIVE_NOT_RECEIVED) {
            return RefundOrderStatus::INTERCEPT_PENDING;
        }

        return RefundOrderStatus::INTERCEPT_NONE;
    }

    private function afterSaleDays(): int
    {
        if (!function_exists('app')) {
            return 0;
        }

        try {
            return \app()->make(OrderSettingService::class)->afterSaleDays();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array{name:string, phone:string, address:string}
     */
    private function returnReceiver(): array
    {
        if (!function_exists('app')) {
            return ['name' => '', 'phone' => '', 'address' => ''];
        }

        try {
            return \app()->make(OrderSettingService::class)->returnReceiver();
        } catch (\Throwable) {
            return ['name' => '', 'phone' => '', 'address' => ''];
        }
    }

    /**
     * 校验申请数量未超过该订单项剩余可退数量
     *
     * @param array<string, mixed> $item
     */
    private function assertQuantityLimit(array $item, int $quantity): void
    {
        $total    = (int) ($item['quantity'] ?? 0);
        $refunded = (int) ($item['refunded_quantity'] ?? 0);
        $remain   = $total - $refunded;

        if ($remain <= 0) {
            throw new BusinessException('该商品已全部申请过售后');
        }
        if ($quantity > $remain) {
            throw new BusinessException(sprintf('申请数量超出可退数量（剩余 %d 件）', $remain));
        }
    }

    /**
     * 同一订单项上不允许存在活跃售后单（PENDING/APPROVED/REFUNDING）
     */
    private function assertNoActiveRefund(int $orderItemId): void
    {
        $exists = $this->model()
            ->where('order_item_id', $orderItemId)
            ->whereIn('status', self::ACTIVE_REFUND_STATUSES)
            ->whereNull('delete_time')
            ->count();
        if ($exists > 0) {
            throw new BusinessException('该商品已有进行中的售后申请');
        }
    }

    /**
     * 退款金额 = 下单快照单价 × 申请数量
     *
     * MVP 不做优惠分摊；真实渠道结算时再细化
     *
     * @param array<string, mixed> $item
     */
    private function calcRefundAmount(array $order, array $item, int $quantity): string
    {
        $orderGoodsTotalCents = $this->decimalToCents((string) ($order['total_amount'] ?? '0.00'));
        if ($orderGoodsTotalCents <= 0) {
            throw new BusinessException('订单商品金额不合法');
        }

        $discountCents = $this->decimalToCents((string) ($order['discount_amount'] ?? '0.00'));
        $payCents = $this->decimalToCents((string) ($order['pay_amount'] ?? '0.00'));
        $goodsPaidCents = max(0, $orderGoodsTotalCents - $discountCents);
        $goodsPaidCents = min($goodsPaidCents, $payCents);
        if ($goodsPaidCents <= 0) {
            throw new BusinessException('订单可退金额不足');
        }

        $unitPriceCents = $this->decimalToCents((string) ($item['unit_price'] ?? '0.00'));
        $requestSubtotalCents = $unitPriceCents * $quantity;
        if ($requestSubtotalCents <= 0) {
            throw new BusinessException('退款金额不合法');
        }

        $baseRefundCents = intdiv($goodsPaidCents * $requestSubtotalCents, $orderGoodsTotalCents);
        if ($baseRefundCents <= 0) {
            $baseRefundCents = min($requestSubtotalCents, $goodsPaidCents);
        }

        $remainingCents = $this->remainingRefundableCents((int) ($order['id'] ?? 0), $goodsPaidCents);
        $refundCents = min($baseRefundCents, $remainingCents);
        if ($refundCents <= 0) {
            throw new BusinessException('订单可退金额不足');
        }

        return $this->centsToDecimal($refundCents);
    }

    private function remainingRefundableCents(int $orderId, int $goodsPaidCents): int
    {
        if ($orderId <= 0) {
            return $goodsPaidCents;
        }

        $rows = $this->model()
            ->where('order_id', $orderId)
            ->whereIn('status', $this->refundOccupiedStatuses())
            ->whereNull('delete_time')
            ->field('refund_amount')
            ->select()
            ->toArray();

        $occupiedCents = 0;
        foreach ($rows as $row) {
            $occupiedCents += $this->decimalToCents((string) ($row['refund_amount'] ?? '0.00'));
        }

        return max(0, $goodsPaidCents - $occupiedCents);
    }

    /**
     * @return array<int, int>
     */
    private function refundOccupiedStatuses(): array
    {
        return array_merge(RefundOrderStatus::activeStatuses(), [RefundOrderStatus::COMPLETED]);
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new BusinessException('金额格式不合法');
        }

        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $yuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
    }

    private function centsToDecimal(int $amountCents): string
    {
        return sprintf('%d.%02d', intdiv($amountCents, 100), $amountCents % 100);
    }

    /**
     * 查询售后单并校验归属
     */
    private function findOwnedRefund(int $userId, int $refundId): RefundOrder
    {
        /** @var RefundOrder|null $refund */
        $refund = $this->model()
            ->where('id', $refundId)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($refund === null) {
            throw new BusinessException('售后单不存在');
        }
        return $refund;
    }

    /**
     * 列表数据补齐订单号与订单项快照（N+1 一次聚合掉）
     *
     * @param array<int, array<string, mixed>> $list 按引用修改
     */
    private function hydrateListRelations(array &$list): void
    {
        if ($list === []) {
            return;
        }

        $orderIds = array_values(array_unique(array_map(
            static fn(array $r): int => (int) ($r['order_id'] ?? 0),
            $list,
        )));
        $orderItemIds = array_values(array_filter(array_map(
            static fn(array $r): int => (int) ($r['order_item_id'] ?? 0),
            $list,
        )));

        $orderMap = [];
        if ($orderIds !== []) {
            $rows = $this->model(Order::class)
                ->whereIn('id', $orderIds)
                ->field('id, sn, status')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $orderMap[(int) $row['id']] = [
                    'sn'          => (string) $row['sn'],
                    'status'      => (int) $row['status'],
                    'status_text' => OrderStatus::textOf((int) $row['status']),
                ];
            }
        }

        $itemMap = [];
        if ($orderItemIds !== []) {
            $rows = $this->model(OrderItem::class)
                ->whereIn('id', $orderItemIds)
                ->field('id, goods_id, sku_id, goods_name, goods_image, sku_spec, unit_price, quantity, refunded_quantity')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $row['goods_image_full_url'] = buildUploadUrl((string) ($row['goods_image'] ?? ''));
                $itemMap[(int) $row['id']]   = $row;
            }
        }

        foreach ($list as &$row) {
            $orderId     = (int) ($row['order_id'] ?? 0);
            $orderItemId = (int) ($row['order_item_id'] ?? 0);
            $row['order']       = $orderMap[$orderId] ?? null;
            $row['order_item']  = $itemMap[$orderItemId] ?? null;
            $row['reason_text'] = RefundReason::textOf((string) ($row['reason'] ?? ''));
        }
        unset($row);
    }

    private function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new BusinessException('用户未登录');
        }
    }
}
