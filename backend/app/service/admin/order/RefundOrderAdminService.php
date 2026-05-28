<?php

declare(strict_types=1);

namespace app\service\admin\order;

use app\model\auth\Admin;
use app\model\order\Order;
use app\model\order\OrderItem;
use app\model\order\RefundOrder;
use app\model\user\User;
use app\service\order\PaymentAdapter;
use app\service\order\RefundOrderStatusMachine;
use app\service\order\WechatRefundAdapter;
use app\service\order\dto\RefundPaymentContext;
use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use app\common\enum\PayMethod;
use app\common\enum\RefundOrderStatus;
use app\common\enum\RefundReason;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台售后服务（审核同意 / 驳回 / 列表 / 详情）
 *
 * 原则：
 *  - 审核流转统一走 {@see RefundOrderStatusMachine}
 *  - approve 先发起微信退款，SUCCESS 后完成状态流转与退款数量累加，PROCESSING 进入退款中
 *  - reject 只改状态+审核字段，不动库存、不动计数
 *  - 列表条件同源，返回 compact('total','list')
 *
 * @extends BaseService<RefundOrder>
 */
class RefundOrderAdminService extends BaseService
{
    protected string $modelClass = RefundOrder::class;

    /**
     * 审核同意（PENDING → REFUNDING / COMPLETED，发起退款处理）
     *
     * 执行顺序：
     *  1) 前置校验订单、订单项与可退款数量
     *  2) PaymentAdapter::refund 发起微信退款（外部调用不进入 DB 事务）
     *  3) SUCCESS：事务内 RefundOrderStatusMachine::transit → COMPLETED，并累加退款数量
     *  4) PROCESSING：事务内 RefundOrderStatusMachine::transit → REFUNDING，等待后续查询/回调确认
     *
     * 注意：当前仅开放“仅退款”，买家不退回实物，审核同意不回滚库存。
     * 后续若启用“退货退款”，应在退货入库节点单独处理库存回滚。
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

        // 确认关联订单项存在；库存不在“仅退款”同意节点回滚
        $orderItemModel = $this->model(OrderItem::class)->where('id', $orderItemId)->find();
        if ($orderItemModel === null) {
            throw new BusinessException('关联订单商品不存在');
        }

        $remain = (int) ($orderItemModel->quantity ?? 0) - (int) ($orderItemModel->refunded_quantity ?? 0);
        if ($quantity <= 0 || $quantity > $remain) {
            throw new BusinessException('退款数量超出限制或已被其他申请占用');
        }

        // 获取主订单微信交易号用于退款渠道调用
        $orderModel = $this->model(Order::class)->where('id', (int) $refund->order_id)->find();
        if ($orderModel === null) {
            throw new BusinessException('关联订单不存在');
        }
        if ((int) ($orderModel->pay_method ?? 0) !== PayMethod::WECHAT) {
            throw new BusinessException('仅微信支付订单支持自动退款');
        }
        $transactionId = trim((string) ($orderModel->trade_no ?? ''));
        if ($transactionId === '' || str_starts_with($transactionId, 'MOCK-')) {
            throw new BusinessException('微信支付交易号缺失，无法发起退款');
        }

        $context = new RefundPaymentContext(
            transactionId: $transactionId,
            outRefundNo: (string) $refund->sn,
            refundAmountCents: $this->decimalToCents((string) $refund->refund_amount),
            totalAmountCents: $this->decimalToCents((string) $orderModel->pay_amount),
            reason: RefundReason::textOf((string) ($refund->reason ?? '')),
        );

        /** @var RefundOrderStatusMachine $machine */
        $machine = app()->make(RefundOrderStatusMachine::class);
        /** @var PaymentAdapter $payment */
        $payment = app()->make(WechatRefundAdapter::class);

        $refundStatus = $payment->refund($context);

        $this->transaction(function () use (
            $refund, $adminId, $adminRemark, $machine,
            $quantity, $orderItemId, $refundStatus
        ): void {
            $toStatus = $refundStatus === 'SUCCESS'
                ? RefundOrderStatus::COMPLETED
                : RefundOrderStatus::REFUNDING;

            // 1. 状态流转（内部原子写 reviewed_at/refunded_at/reviewed_by/admin_remark）
            $machine->transit(
                refund: $refund,
                toStatus: $toStatus,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
                remark: $adminRemark !== '' ? $adminRemark : '管理员同意退款',
            );

            if ($toStatus !== RefundOrderStatus::COMPLETED) {
                return;
            }

            // 2. 微信退款成功后乐观锁累加 OrderItem.refunded_quantity
            $affected = $this->model(OrderItem::class)
                ->where('id', $orderItemId)
                ->whereRaw('refunded_quantity + ? <= quantity', [$quantity])
                ->inc('refunded_quantity', $quantity)
                ->update();
            if ($affected === 0) {
                throw new BusinessException('退款数量超出限制或已被其他申请占用');
            }

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

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new BusinessException('金额格式不合法');
        }

        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $yuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
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
