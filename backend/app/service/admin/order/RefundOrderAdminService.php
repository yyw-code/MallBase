<?php

declare(strict_types=1);

namespace app\service\admin\order;

use app\model\auth\Admin;
use app\model\order\Order;
use app\model\order\OrderItem;
use app\model\order\RefundOrder;
use app\model\user\User;
use app\model\user\UserWallet;
use app\model\user\UserWalletLog;
use app\service\order\PaymentAdapter;
use app\service\order\OrderStatusMachine;
use app\service\order\RefundOrderStatusMachine;
use app\service\order\WechatRefundAdapter;
use app\service\order\dto\RefundPaymentContext;
use app\service\upload\AssetHydrator;
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
     * 注意：仅退款审核同意后直接发起退款；退货退款审核同意后先进入待退货，
     * 买家回填退货物流、商家确认收货后再发起退款。
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

        $orderModel = $this->model(Order::class)->where('id', (int) $refund->order_id)->find();
        if ($orderModel === null) {
            throw new BusinessException('关联订单不存在');
        }

        if ((int) $refund->type === RefundOrderStatus::TYPE_RETURN_REFUND) {
            $this->approveReturnRefund($refund, $adminId, $adminRemark);
            return;
        }

        $this->assertCanApproveRefundOnly($refund, $orderModel);
        $this->executeRefund(
            $refund,
            $orderModel,
            $adminId,
            $adminRemark,
            '管理员同意退款',
            [RefundOrderStatus::PENDING],
            '当前售后单状态不允许审核',
        );
    }

    /**
     * 更新已发货未收到货仅退款的物流拦截状态。
     */
    public function updateIntercept(int $refundId, string $status, string $note = ''): void
    {
        $status = trim($status);
        $valid = array_column(RefundOrderStatus::interceptOptions(), 'value');
        if (!in_array($status, $valid, true)) {
            throw new BusinessException('物流拦截状态不合法');
        }

        $refund = $this->findRefund($refundId);
        if ((int) $refund->type !== RefundOrderStatus::TYPE_REFUND_ONLY
            || (int) $refund->receive_status !== RefundOrderStatus::RECEIVE_NOT_RECEIVED) {
            throw new BusinessException('当前售后单不需要物流拦截');
        }
        if ((int) $refund->status !== RefundOrderStatus::PENDING) {
            throw new BusinessException('仅待审核售后单可更新拦截状态');
        }

        $refund->intercept_status = $status;
        $refund->intercept_note = $note !== '' ? mb_substr(trim($note), 0, 255) : null;
        $refund->save();
    }

    /**
     * 商家确认收到退货，随后发起退款。
     */
    public function confirmReturn(int $refundId, int $adminId, string $adminRemark = ''): void
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }

        $refund = $this->findRefund($refundId);
        if ((int) $refund->type !== RefundOrderStatus::TYPE_RETURN_REFUND) {
            throw new BusinessException('仅退货退款需要确认收货');
        }
        if ((int) $refund->status !== RefundOrderStatus::APPROVED) {
            throw new BusinessException('当前售后单状态不允许确认收货');
        }
        if (trim((string) ($refund->return_tracking_no ?? '')) === '') {
            throw new BusinessException('买家尚未填写退货物流单号');
        }

        $orderModel = $this->model(Order::class)->where('id', (int) $refund->order_id)->find();
        if ($orderModel === null) {
            throw new BusinessException('关联订单不存在');
        }
        $this->executeRefund(
            $refund,
            $orderModel,
            $adminId,
            $adminRemark,
            '商家确认退货收货并退款',
            [RefundOrderStatus::APPROVED],
            '当前售后单状态不允许确认收货',
        );
    }

    private function approveReturnRefund(RefundOrder $refund, int $adminId, string $adminRemark): void
    {
        if ((int) $refund->receive_status !== RefundOrderStatus::RECEIVE_RECEIVED) {
            throw new BusinessException('退货退款必须是已收到货场景');
        }

        $receiver = app()->make(\app\service\order\OrderSettingService::class)->returnReceiver();
        if (($receiver['name'] ?? '') === '' || ($receiver['phone'] ?? '') === '' || ($receiver['address'] ?? '') === '') {
            throw new BusinessException('请先在售后设置中配置退货收货信息');
        }

        /** @var RefundOrderStatusMachine $machine */
        $machine = app()->make(RefundOrderStatusMachine::class);
        $this->transaction(function () use ($refund, $adminId, $adminRemark, $receiver, $machine): void {
            $refund->return_receiver_name = $receiver['name'];
            $refund->return_receiver_phone = $receiver['phone'];
            $refund->return_receiver_address = $receiver['address'];
            $refund->save();

            $machine->transit(
                refund: $refund,
                toStatus: RefundOrderStatus::APPROVED,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
                remark: $adminRemark !== '' ? $adminRemark : '商家同意退货，请买家寄回商品',
            );
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
     * 微信退款成功后收口售后单（退款回调 / 主动查单共用）
     */
    public function completeWechatRefund(string $refundSn, int $amountCents, ?string $successTime = null): void
    {
        $refundSn = trim($refundSn);
        if ($refundSn === '') {
            throw new BusinessException('退款单号缺失');
        }
        if ($amountCents <= 0) {
            throw new BusinessException('退款金额必须大于 0');
        }

        /** @var RefundOrder|null $refund */
        $refund = $this->model()
            ->where('sn', $refundSn)
            ->whereNull('delete_time')
            ->find();
        if ($refund === null) {
            throw new BusinessException('售后单不存在');
        }

        if ((int) $refund->status === RefundOrderStatus::COMPLETED) {
            return;
        }
        if ((int) $refund->status !== RefundOrderStatus::REFUNDING) {
            throw new BusinessException('售后单当前状态不允许确认退款成功');
        }

        $expectedCents = $this->decimalToCents((string) $refund->refund_amount);
        if ($expectedCents !== $amountCents) {
            throw new BusinessException('微信退款金额与售后单金额不一致');
        }

        $orderItemId = (int) ($refund->order_item_id ?? 0);
        $quantity = (int) ($refund->quantity ?? 0);

        /** @var RefundOrderStatusMachine $machine */
        $machine = app()->make(RefundOrderStatusMachine::class);

        $this->transaction(function () use ($refund, $machine, $orderItemId, $quantity, $successTime): void {
            $machine->transit(
                refund: $refund,
                toStatus: RefundOrderStatus::COMPLETED,
                operatorType: OperatorType::SYSTEM,
                operatorId: null,
                remark: null,
            );

            if ($successTime !== null && trim($successTime) !== '') {
                $refund->refunded_at = date('Y-m-d H:i:s', strtotime($successTime));
                $refund->save();
            }

            $affected = $this->model(OrderItem::class)
                ->where('id', $orderItemId)
                ->whereRaw('refunded_quantity + ? <= quantity', [$quantity])
                ->inc('refunded_quantity', $quantity)
                ->update();
            if ($affected !== 1) {
                throw new BusinessException('退款数量超出限制或已被其他申请占用');
            }

            $this->closeOrderIfFullyRefunded(
                orderId: (int) $refund->order_id,
                operatorType: OperatorType::SYSTEM,
                operatorId: null,
            );
        });
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
                $hydrated = app()->make(AssetHydrator::class)->hydrateFields([$item], [
                    'goods_image' => 'goods_image_full_url',
                ]);
                $item = $hydrated[0] ?? $item;
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
            $hydratedUser = app()->make(AssetHydrator::class)->hydrateFields([$user], [
                'avatar' => 'avatar_url',
            ]);
            $user = $hydratedUser[0] ?? $user;
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

    private function assertCanApproveRefundOnly(RefundOrder $refund, Order $order): void
    {
        if ((int) $refund->type !== RefundOrderStatus::TYPE_REFUND_ONLY) {
            throw new BusinessException('当前售后类型不支持直接退款');
        }

        if ((int) $order->status === OrderStatus::SHIPPED
            && (int) $refund->receive_status === RefundOrderStatus::RECEIVE_NOT_RECEIVED) {
            $allowed = [
                RefundOrderStatus::INTERCEPT_SUCCESS,
                RefundOrderStatus::INTERCEPT_RETURNED,
                RefundOrderStatus::INTERCEPT_EXCEPTION,
            ];
            if (!in_array((string) $refund->intercept_status, $allowed, true)) {
                throw new BusinessException('已发货未收到货的仅退款申请，需要先完成物流拦截、确认退回或标记物流异常后才能退款');
            }
        }
    }

    private function executeRefund(
        RefundOrder $refund,
        Order $orderModel,
        int $adminId,
        string $adminRemark,
        string $defaultRemark,
        array $expectedStatuses,
        string $statusErrorMessage
    ): void {
        $amountCents = $this->decimalToCents((string) $refund->refund_amount);
        if ($amountCents <= 0) {
            $this->completeZeroAmountRefund(
                $refund,
                $adminId,
                $adminRemark,
                $defaultRemark,
                $expectedStatuses,
                $statusErrorMessage,
            );
            return;
        }

        $payMethod = (int) ($orderModel->pay_method ?? 0);
        if ($payMethod === PayMethod::BALANCE) {
            $this->executeBalanceRefund(
                $refund,
                $orderModel,
                $adminId,
                $adminRemark,
                $defaultRemark,
                $expectedStatuses,
                $statusErrorMessage,
            );
            return;
        }

        if ($payMethod !== PayMethod::WECHAT) {
            throw new BusinessException('当前支付方式暂不支持自动退款');
        }
        $transactionId = trim((string) ($orderModel->trade_no ?? ''));
        if ($transactionId === '' || str_starts_with($transactionId, 'MOCK-')) {
            throw new BusinessException('微信支付交易号缺失，无法发起退款');
        }

        $orderItemId = (int) ($refund->order_item_id ?? 0);
        $quantity = (int) ($refund->quantity ?? 0);

        $context = new RefundPaymentContext(
            transactionId: $transactionId,
            outRefundNo: (string) $refund->sn,
            refundAmountCents: $amountCents,
            totalAmountCents: $this->decimalToCents((string) $orderModel->pay_amount),
            reason: RefundReason::textOf((string) ($refund->reason ?? '')),
        );

        /** @var RefundOrderStatusMachine $machine */
        $machine = app()->make(RefundOrderStatusMachine::class);
        /** @var PaymentAdapter $payment */
        $payment = app()->make(WechatRefundAdapter::class);
        $refundStatus = $payment->refund($context);

        $this->transaction(function () use (
            $refund,
            $adminId,
            $adminRemark,
            $defaultRemark,
            $machine,
            $quantity,
            $orderItemId,
            $refundStatus
        ): void {
            $toStatus = $refundStatus === 'SUCCESS'
                ? RefundOrderStatus::COMPLETED
                : RefundOrderStatus::REFUNDING;

            if ((int) $refund->type === RefundOrderStatus::TYPE_RETURN_REFUND
                && trim((string) ($refund->return_received_at ?? '')) === '') {
                $refund->return_received_at = date('Y-m-d H:i:s');
                $refund->save();
            }

            $machine->transit(
                refund: $refund,
                toStatus: $toStatus,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
                remark: $adminRemark !== '' ? $adminRemark : $defaultRemark,
            );

            if ($toStatus !== RefundOrderStatus::COMPLETED) {
                return;
            }

            $affected = $this->model(OrderItem::class)
                ->where('id', $orderItemId)
                ->whereRaw('refunded_quantity + ? <= quantity', [$quantity])
                ->inc('refunded_quantity', $quantity)
                ->update();
            if ($affected === 0) {
                throw new BusinessException('退款数量超出限制或已被其他申请占用');
            }

            $this->closeOrderIfFullyRefunded(
                orderId: (int) $refund->order_id,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
            );
        });
    }

    private function completeZeroAmountRefund(
        RefundOrder $refund,
        int $adminId,
        string $adminRemark,
        string $defaultRemark,
        array $expectedStatuses,
        string $statusErrorMessage
    ): void {
        $refundId = (int) $refund->id;
        $orderItemId = (int) ($refund->order_item_id ?? 0);
        $quantity = (int) ($refund->quantity ?? 0);

        /** @var RefundOrderStatusMachine $machine */
        $machine = app()->make(RefundOrderStatusMachine::class);

        $this->transaction(function () use (
            $refundId,
            $orderItemId,
            $quantity,
            $adminId,
            $adminRemark,
            $defaultRemark,
            $expectedStatuses,
            $statusErrorMessage,
            $machine
        ): void {
            /** @var RefundOrder|null $lockedRefund */
            $lockedRefund = $this->model()
                ->where('id', $refundId)
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if ($lockedRefund === null) {
                throw new BusinessException('售后单不存在');
            }
            if (!in_array((int) $lockedRefund->status, $expectedStatuses, true)) {
                throw new BusinessException($statusErrorMessage);
            }

            if ((int) $lockedRefund->type === RefundOrderStatus::TYPE_RETURN_REFUND
                && trim((string) ($lockedRefund->return_received_at ?? '')) === '') {
                $lockedRefund->return_received_at = date('Y-m-d H:i:s');
                $lockedRefund->save();
            }

            $machine->transit(
                refund: $lockedRefund,
                toStatus: RefundOrderStatus::COMPLETED,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
                remark: $adminRemark !== '' ? $adminRemark : $defaultRemark,
            );

            $affected = $this->model(OrderItem::class)
                ->where('id', $orderItemId)
                ->whereRaw('refunded_quantity + ? <= quantity', [$quantity])
                ->inc('refunded_quantity', $quantity)
                ->update();
            if ($affected === 0) {
                throw new BusinessException('退款数量超出限制或已被其他申请占用');
            }

            $this->closeOrderIfFullyRefunded(
                orderId: (int) $lockedRefund->order_id,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
            );
        });
    }

    private function executeBalanceRefund(
        RefundOrder $refund,
        Order $orderModel,
        int $adminId,
        string $adminRemark,
        string $defaultRemark,
        array $expectedStatuses,
        string $statusErrorMessage
    ): void {
        $amountCents = $this->decimalToCents((string) $refund->refund_amount);
        if ($amountCents <= 0) {
            throw new BusinessException('退款金额必须大于 0');
        }

        $refundId = (int) $refund->id;
        $orderId = (int) $orderModel->id;
        $orderItemId = (int) ($refund->order_item_id ?? 0);
        $quantity = (int) ($refund->quantity ?? 0);

        /** @var RefundOrderStatusMachine $machine */
        $machine = app()->make(RefundOrderStatusMachine::class);

        $this->transaction(function () use (
            $refundId,
            $orderId,
            $orderItemId,
            $quantity,
            $amountCents,
            $adminId,
            $adminRemark,
            $defaultRemark,
            $expectedStatuses,
            $statusErrorMessage,
            $machine
        ): void {
            /** @var RefundOrder|null $lockedRefund */
            $lockedRefund = $this->model()
                ->where('id', $refundId)
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if ($lockedRefund === null) {
                throw new BusinessException('售后单不存在');
            }
            if (!in_array((int) $lockedRefund->status, $expectedStatuses, true)) {
                throw new BusinessException($statusErrorMessage);
            }

            /** @var Order|null $lockedOrder */
            $lockedOrder = $this->model(Order::class)
                ->where('id', $orderId)
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if ($lockedOrder === null) {
                throw new BusinessException('关联订单不存在');
            }
            if ((int) $lockedOrder->pay_method !== PayMethod::BALANCE) {
                throw new BusinessException('订单不是余额支付，无法退回余额');
            }

            $userId = (int) $lockedRefund->user_id;
            /** @var UserWallet|null $wallet */
            $wallet = $this->model(UserWallet::class)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();
            if ($wallet === null) {
                /** @var UserWallet $wallet */
                $wallet = UserWallet::create([
                    'user_id' => $userId,
                    'balance_cents' => 0,
                    'frozen_cents' => 0,
                    'total_recharge_cents' => 0,
                    'total_consume_cents' => 0,
                ]);
            }

            $before = (int) $wallet->balance_cents;
            $after = $before + $amountCents;
            if ($after > 4_294_967_295) {
                throw new BusinessException('用户余额超出系统限制');
            }

            $wallet->balance_cents = $after;
            $wallet->save();

            UserWalletLog::create([
                'user_id' => $userId,
                'wallet_id' => (int) $wallet->id,
                'biz_type' => UserWalletLog::BIZ_REFUND,
                'biz_id' => (string) $lockedRefund->sn,
                'direction' => UserWalletLog::DIRECTION_INCOME,
                'change_cents' => $amountCents,
                'before_cents' => $before,
                'after_cents' => $after,
                'operator_type' => OperatorType::ADMIN,
                'operator_id' => $adminId,
                'remark' => '售后退款退回余额',
            ]);

            $machine->transit(
                refund: $lockedRefund,
                toStatus: RefundOrderStatus::COMPLETED,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
                remark: $adminRemark !== '' ? $adminRemark : $defaultRemark,
            );

            $affected = $this->model(OrderItem::class)
                ->where('id', $orderItemId)
                ->whereRaw('refunded_quantity + ? <= quantity', [$quantity])
                ->inc('refunded_quantity', $quantity)
                ->update();
            if ($affected === 0) {
                throw new BusinessException('退款数量超出限制或已被其他申请占用');
            }

            $this->closeOrderIfFullyRefunded(
                orderId: (int) $lockedRefund->order_id,
                operatorType: OperatorType::ADMIN,
                operatorId: $adminId,
            );
        });
    }

    private function closeOrderIfFullyRefunded(int $orderId, int $operatorType, ?int $operatorId): void
    {
        if ($orderId <= 0) {
            return;
        }

        /** @var Order|null $order */
        $order = $this->model(Order::class)
            ->where('id', $orderId)
            ->whereNull('delete_time')
            ->lock(true)
            ->find();
        if ($order === null) {
            return;
        }

        $status = (int) ($order->status ?? 0);
        if (!in_array($status, [OrderStatus::PAID, OrderStatus::SHIPPED, OrderStatus::RECEIVED], true)) {
            return;
        }

        $items = $this->model(OrderItem::class)
            ->where('order_id', $orderId)
            ->field('quantity, refunded_quantity')
            ->select()
            ->toArray();
        if ($items === []) {
            return;
        }

        foreach ($items as $item) {
            if ((int) ($item['refunded_quantity'] ?? 0) < (int) ($item['quantity'] ?? 0)) {
                return;
            }
        }

        app()->make(OrderStatusMachine::class)->transit(
            order: $order,
            toStatus: OrderStatus::CLOSED,
            operatorType: $operatorType,
            operatorId: $operatorId,
            remark: '售后全量退款关闭订单',
        );
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
            $rows = app()->make(AssetHydrator::class)->hydrateFields($rows, [
                'goods_image' => 'goods_image_full_url',
            ]);
            foreach ($rows as $row) {
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
