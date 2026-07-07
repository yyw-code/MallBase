<?php

declare(strict_types=1);

namespace app\service\order;

use app\model\order\Order;
use app\model\order\OrderLog;
use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use app\extension\order\OrderEvent;
use app\extension\order\OrderEventContext;
use app\extension\pipeline\OrderEventDispatcher;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use think\facade\Request;

/**
 * 订单状态机（状态流转唯一入口）
 *
 * 设计原则：
 *  - 业务代码一律调用 {@see transit}，禁止直接 $order->status = X
 *  - 白名单由 {@see OrderStatus::canTransit} 维护，非法流转抛 BusinessException
 *  - 事务内原子完成三件事：
 *      1) 更新 status
 *      2) 写入对应时间戳（paid_at / shipped_at / received_at / completed_at / closed_at）
 *      3) 追加一条 mb_order_log（append-only 审计）
 *  - 不自己启动事务时，调用方负责在外层包裹事务以与库存扣减等组合成原子单元
 *
 * @extends BaseService<Order>
 */
class OrderStatusMachine extends BaseService
{
    protected string $modelClass = Order::class;

    /**
     * 状态 → 时间戳列名映射
     *
     * 新增终态/新态时务必同步此表，避免时间戳缺失导致列表筛选失效。
     */
    private const STATUS_TIMESTAMP = [
        OrderStatus::PAID      => 'paid_at',
        OrderStatus::SHIPPED   => 'shipped_at',
        OrderStatus::RECEIVED  => 'received_at',
        OrderStatus::COMPLETED => 'completed_at',
        OrderStatus::CLOSED    => 'closed_at',
    ];

    /**
     * 执行状态流转
     *
     * @param Order       $order         当前订单（内存中的实例，方法内会直接修改其字段并 save）
     * @param int         $toStatus      目标状态（必须命中 OrderStatus::canTransit 白名单）
     * @param int         $operatorType  操作方类型（见 {@see OperatorType}：0 系统 / 1 买家 / 2 管理员）
     * @param int|null    $operatorId    操作方主键（系统触发时可为 null）
     * @param string|null $remark        备注（取消原因、关闭原因等）
     *
     * @throws BusinessException 状态非法 / 不允许流转
     */
    public function transit(
        Order $order,
        int $toStatus,
        int $operatorType,
        ?int $operatorId = null,
        ?string $remark = null
    ): void {
        if (!OrderStatus::isValid($toStatus)) {
            throw new BusinessException('订单目标状态不合法');
        }

        $orderId = (int) ($order->id ?? 0);
        if ($orderId > 0) {
            $this->transaction(function () use ($order, $orderId, $toStatus, $operatorType, $operatorId, $remark): void {
                /** @var Order|null $lockedOrder */
                $lockedOrder = $this->model()
                    ->where('id', $orderId)
                    ->whereNull('delete_time')
                    ->lock(true)
                    ->find();
                if ($lockedOrder === null) {
                    throw new BusinessException('订单不存在');
                }

                $this->transitLoadedOrder($lockedOrder, $toStatus, $operatorType, $operatorId, $remark);
                $this->syncOrderSnapshot($order, $lockedOrder);
            });
            return;
        }

        $fromStatus = $this->resolvedFromStatus($order, $toStatus);
        if ($fromStatus === null) {
            return;
        }

        $this->transaction(function () use ($order, $fromStatus, $toStatus, $operatorType, $operatorId, $remark): void {
            $this->persistTransit($order, $fromStatus, $toStatus, $operatorType, $operatorId, $remark);
        });
    }

    private function transitLoadedOrder(
        Order $order,
        int $toStatus,
        int $operatorType,
        ?int $operatorId,
        ?string $remark
    ): void {
        $fromStatus = $this->resolvedFromStatus($order, $toStatus);
        if ($fromStatus === null) {
            return;
        }

        $this->persistTransit($order, $fromStatus, $toStatus, $operatorType, $operatorId, $remark);
    }

    private function resolvedFromStatus(Order $order, int $toStatus): ?int
    {
        $fromStatus = (int) ($order->status ?? 0);
        if ($fromStatus === $toStatus) {
            // 幂等保护：重复流转到同一状态不报错、不写日志
            return null;
        }

        if (!OrderStatus::canTransit($fromStatus, $toStatus)) {
            throw new BusinessException(sprintf(
                '订单状态不允许从「%s」流转到「%s」',
                OrderStatus::textOf($fromStatus),
                OrderStatus::textOf($toStatus)
            ));
        }

        return $fromStatus;
    }

    private function persistTransit(
        Order $order,
        int $fromStatus,
        int $toStatus,
        int $operatorType,
        ?int $operatorId,
        ?string $remark
    ): void {
        /** @var OrderEventDispatcher $dispatcher */
        $dispatcher = app()->make(OrderEventDispatcher::class);

        // 1. 更新 status + 对应时间戳
        $order->status = $toStatus;
        $timestampColumn = self::STATUS_TIMESTAMP[$toStatus] ?? null;
        if ($timestampColumn !== null) {
            // 使用 date() 避免依赖框架 Helper，保持与 Model $autoWriteTimestamp 一致的 datetime 格式
            $order->{$timestampColumn} = date('Y-m-d H:i:s');
        }
        $order->save();

        // 2. 写入流转日志（append-only）
        OrderLog::create([
            'order_id'      => (int) $order->id,
            'from_status'   => $fromStatus,
            'to_status'     => $toStatus,
            'operator_type' => $operatorType,
            'operator_id'   => $operatorId,
            'remark'        => $remark !== null ? mb_substr($remark, 0, 255) : null,
            'ip'            => $this->currentIp(),
        ]);

        if ($toStatus === OrderStatus::PAID) {
            $dispatcher->dispatch(OrderEventContext::forOrder(
                OrderEvent::ORDER_PAID,
                $order,
                $fromStatus,
                $toStatus,
            ));
        }
        if ($toStatus === OrderStatus::COMPLETED) {
            $dispatcher->dispatch(OrderEventContext::forOrder(
                OrderEvent::ORDER_COMPLETED,
                $order,
                $fromStatus,
                $toStatus,
            ));
        }
        if ($toStatus === OrderStatus::CLOSED) {
            $dispatcher->dispatch(OrderEventContext::forOrder(
                OrderEvent::ORDER_CLOSED,
                $order,
                $fromStatus,
                $toStatus,
            ));
        }
    }

    private function syncOrderSnapshot(Order $target, Order $source): void
    {
        foreach (['status', 'paid_at', 'shipped_at', 'received_at', 'completed_at', 'closed_at'] as $field) {
            $target->{$field} = $source->{$field} ?? null;
        }
    }

    /**
     * 取当前请求 IP；CLI / 队列 / 无请求上下文时返回 null
     */
    private function currentIp(): ?string
    {
        try {
            $ip = Request::ip();
            return $ip !== '' ? $ip : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
