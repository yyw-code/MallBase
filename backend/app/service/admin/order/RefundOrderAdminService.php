<?php

declare(strict_types=1);

namespace app\service\admin\order;

use app\model\auth\Admin;
use app\model\order\Order;
use app\model\order\OrderItem;
use app\model\order\RefundOrder;
use app\model\user\User;
use app\service\order\MockPaymentAdapter;
use app\service\order\PaymentAdapter;
use app\service\order\RefundOrderStatusMachine;
use app\service\order\StockService;
use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use app\common\enum\RefundReason;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台售后服务（审核同意 / 驳回 / 列表 / 详情）
 *
 * 原则：
 *  - 审核流转统一走 {@see RefundOrderStatusMachine}
 *  - approve 事务内三件事：状态流转 → 库存回滚 → OrderItem.refunded_quantity 乐观锁累加
 *  - reject 只改状态+审核字段，不动库存、不动计数
 *  - 列表条件同源，返回 compact('total','list')
 *
 * @extends BaseService<RefundOrder>
 */
class RefundOrderAdminService extends BaseService
{
    protected string $modelClass = RefundOrder::class;

    /**
     * 审核同意（PENDING → COMPLETED，Mock 退款）
     *
     * 事务内原子完成：
     *  1) RefundOrderStatusMachine::transit → COMPLETED（含 reviewed_at/refunded_at/reviewed_by）
     *  2) StockService::restore(sku_id, quantity) — 仅退款单回滚库存
     *  3) OrderItem.refunded_quantity 乐观锁累加
     *  4) PaymentAdapter::refund — MVP 返回 true
     */
    public function approve(int $refundId, int $adminId, string $adminRemark = ''): void
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }

        $refund = $this->findRefund($refundId);
        if ((int) $refund->status !== RefundOrderStatus::PENDING) {
            throw new BusinessException('当前售后单状态不允许审核');
        }

        $orderItemId = (int) ($refund->order_item_id ?? 0);
        $quantity    = (int) ($refund->quantity ?? 0);
        $type        = (int) ($refund->type ?? 0);

        // 获取 SKU ID 用于库存回滚
        $orderItemModel = $this->model(OrderItem::class)->where('id', $orderItemId)->find();
        if ($orderItemModel === null) {
            throw new BusinessException('关联订单商品不存在');
        }
        $skuId = (int) ($orderItemModel->sku_id ?? 0);

        // 获取主订单 trade_no 用于退款渠道调用
        $orderModel = $this->model(Order::class)->where('id', (int) $refund->order_id)->find();
        $tradeNo = $orderModel !== null ? (string) ($orderModel->trade_no ?? '') : '';

        /** @var RefundOrderStatusMachine $machine */
        $machine = app()->make(RefundOrderStatusMachine::class);
        /** @var StockService $stock */
        $stock = app()->make(StockService::class);
        /** @var PaymentAdapter $payment */
        $payment = new MockPaymentAdapter();

        $this->transaction(function () use (
            $refund, $adminId, $adminRemark, $machine,
            $stock, $payment, $skuId, $quantity, $type,
            $orderItemId, $tradeNo
        ): void {
            // 1. 状态流转（内部原子写 reviewed_at/refunded_at/reviewed_by/admin_remark）
            $machine->transit(
                refund: $refund,
                toStatus: RefundOrderStatus::COMPLETED,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
                remark: $adminRemark !== '' ? $adminRemark : '管理员同意退款',
            );

            // 2. 仅退款 → 回滚库存
            if ($type === RefundOrderStatus::TYPE_REFUND_ONLY) {
                $stock->restore($skuId, $quantity);
            }

            // 3. 乐观锁累加 OrderItem.refunded_quantity
            $affected = $this->model(OrderItem::class)
                ->where('id', $orderItemId)
                ->whereRaw('refunded_quantity + ? <= quantity', [$quantity])
                ->inc('refunded_quantity', $quantity)
                ->update();
            if ($affected === 0) {
                throw new BusinessException('退货数量超出限制或已被其他申请占用');
            }

            // 4. Mock 退款（MVP 直接 true）
            $payment->refund($tradeNo, (string) $refund->refund_amount);
        });
    }

    /**
     * 审核驳回（PENDING → REJECTED）
     *
     * 不动库存、不动计数；admin_remark 必填
     */
    public function reject(int $refundId, int $adminId, string $adminRemark): void
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }
        if (trim($adminRemark) === '') {
            throw new BusinessException('驳回原因必填');
        }

        $refund = $this->findRefund($refundId);
        if ((int) $refund->status !== RefundOrderStatus::PENDING) {
            throw new BusinessException('当前售后单状态不允许审核');
        }

        app()->make(RefundOrderStatusMachine::class)->transit(
            refund: $refund,
            toStatus: RefundOrderStatus::REJECTED,
            operatorType: OperatorType::ADMIN,
            operatorId: $adminId,
            remark: mb_substr(trim($adminRemark), 0, 255),
        );
    }

    /**
     * 后台售后列表（分页 + 筛选）
     *
     * 条件同源：clone + count
     *
     * @param array{sn?:string, order_sn?:string, status?:int|null, type?:int|null, user_phone?:string, created_start?:string, created_end?:string, reviewed_start?:string, reviewed_end?:string} $filter
     * @return array{total:int, list:array<int, array<string, mixed>>}
     */
    public function adminList(array $filter = [], int $page = 1, int $pageSize = 15): array
    {
        $query = $this->model()->whereNull('delete_time');

        if (!empty($filter['sn'])) {
            $query->where('sn', 'like', '%' . trim((string) $filter['sn']) . '%');
        }
        if (isset($filter['status']) && $filter['status'] !== null && $filter['status'] !== '') {
            $query->where('status', (int) $filter['status']);
        }
        if (isset($filter['type']) && $filter['type'] !== null && $filter['type'] !== '') {
            $query->where('type', (int) $filter['type']);
        }
        if (!empty($filter['created_start'])) {
            $query->where('create_time', '>=', (string) $filter['created_start']);
        }
        if (!empty($filter['created_end'])) {
            $query->where('create_time', '<=', (string) $filter['created_end']);
        }
        if (!empty($filter['reviewed_start'])) {
            $query->where('reviewed_at', '>=', (string) $filter['reviewed_start']);
        }
        if (!empty($filter['reviewed_end'])) {
            $query->where('reviewed_at', '<=', (string) $filter['reviewed_end']);
        }

        // 按订单号筛选需要子查询
        if (!empty($filter['order_sn'])) {
            $orderIds = $this->model(Order::class)
                ->where('sn', 'like', '%' . trim((string) $filter['order_sn']) . '%')
                ->column('id');
            $query->whereIn('order_id', $orderIds ?: [0]);
        }

        // 按买家手机筛选需要 join user 表
        if (!empty($filter['user_phone'])) {
            $userIds = $this->model(User::class)
                ->where('mobile', 'like', '%' . trim((string) $filter['user_phone']) . '%')
                ->column('id');
            $query->whereIn('user_id', $userIds ?: [0]);
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
     * 后台售后详情
     */
    public function adminDetail(int $refundId): array
    {
        $refund = $this->findRefund($refundId);
        $data   = $refund->toArray();

        // 关联主订单摘要
        $orderModel = $this->model(Order::class)
            ->where('id', (int) $refund->order_id)
            ->field('id, sn, status, pay_amount, receiver_name, receiver_phone, receiver_province, receiver_city, receiver_district, receiver_address, create_time, paid_at, shipped_at, received_at')
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

        // 买家信息
        $userModel = $this->model(User::class)
            ->where('id', (int) $refund->user_id)
            ->field('id, nickname, mobile as phone, avatar')
            ->find();
        $user = $userModel?->toArray();
        if ($user !== null && !empty($user['avatar'])) {
            $user['avatar_url'] = buildUploadUrl((string) $user['avatar']);
        }
        $data['user'] = $user;

        // 审核人信息
        $reviewedBy = (int) ($refund->reviewed_by ?? 0);
        if ($reviewedBy > 0) {
            $reviewerModel = $this->model(Admin::class)
                ->where('id', $reviewedBy)
                ->field('id, nickname, username')
                ->find();
            $data['reviewer'] = $reviewerModel?->toArray();
        } else {
            $data['reviewer'] = null;
        }

        $data['reason_text'] = RefundReason::textOf((string) ($refund->reason ?? ''));

        return $data;
    }

    // ---------------- 内部 ----------------

    private function findRefund(int $refundId): RefundOrder
    {
        /** @var RefundOrder|null $refund */
        $refund = $this->model()
            ->where('id', $refundId)
            ->whereNull('delete_time')
            ->find();
        if ($refund === null) {
            throw new BusinessException('售后单不存在');
        }
        return $refund;
    }

    /**
     * 列表数据补齐关联信息
     *
     * @param array<int, array<string, mixed>> $list
     */
    private function hydrateListRelations(array &$list): void
    {
        if ($list === []) {
            return;
        }

        // 订单号
        $orderIds = array_values(array_unique(array_map(
            static fn(array $r): int => (int) ($r['order_id'] ?? 0),
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

        // 订单项快照
        $orderItemIds = array_values(array_filter(array_map(
            static fn(array $r): int => (int) ($r['order_item_id'] ?? 0),
            $list,
        )));
        $itemMap = [];
        if ($orderItemIds !== []) {
            $rows = $this->model(OrderItem::class)
                ->whereIn('id', $orderItemIds)
                ->field('id, goods_name, goods_image, sku_spec, unit_price, quantity')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $row['goods_image_full_url'] = buildUploadUrl((string) ($row['goods_image'] ?? ''));
                $itemMap[(int) $row['id']]   = $row;
            }
        }

        // 买家信息
        $userIds = array_values(array_unique(array_map(
            static fn(array $r): int => (int) ($r['user_id'] ?? 0),
            $list,
        )));
        $userMap = [];
        if ($userIds !== []) {
            $rows = $this->model(User::class)
                ->whereIn('id', $userIds)
                ->field('id, nickname, mobile as phone')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $userMap[(int) $row['id']] = $row;
            }
        }

        foreach ($list as &$row) {
            $orderId     = (int) ($row['order_id'] ?? 0);
            $orderItemId = (int) ($row['order_item_id'] ?? 0);
            $userId      = (int) ($row['user_id'] ?? 0);

            $row['order']       = $orderMap[$orderId] ?? null;
            $row['order_item']  = $itemMap[$orderItemId] ?? null;
            $row['user']        = $userMap[$userId] ?? null;
            $row['reason_text'] = RefundReason::textOf((string) ($row['reason'] ?? ''));
        }
        unset($row);
    }
}
