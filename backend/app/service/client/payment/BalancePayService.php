<?php
declare(strict_types=1);

namespace app\service\client\payment;

use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use app\common\enum\PayMethod;
use app\common\enum\PayScene;
use app\model\order\Order;
use app\model\order\PaymentLog;
use app\model\user\UserWallet;
use app\model\user\UserWalletLog;
use app\service\order\OrderStatusMachine;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 余额支付服务
 *
 * @extends BaseService<PaymentLog>
 */
class BalancePayService extends BaseService
{
    protected string $modelClass = PaymentLog::class;

    /**
     * @return array{order_id:int,sn:string,status:int}
     */
    public function payById(int $userId, int $orderId): array
    {
        if ($userId <= 0) {
            throw new BusinessException('未登录');
        }

        $order = $this->loadPayableOrder($userId, $orderId);
        $amountCents = $this->decimalToCents((string) $order->pay_amount);
        if ($amountCents <= 0) {
            throw new BusinessException('订单金额不合法');
        }

        $tradeNo = $this->generateTradeNo((string) $order->sn);
        /** @var OrderStatusMachine $machine */
        $machine = app()->make(OrderStatusMachine::class);

        $this->transaction(function () use ($userId, $orderId, $amountCents, $tradeNo, $machine): void {
            /** @var Order|null $lockedOrder */
            $lockedOrder = $this->model(Order::class)
                ->where('id', $orderId)
                ->where('user_id', $userId)
                ->whereNull('delete_time')
                ->lock(true)
                ->find();
            if ($lockedOrder === null) {
                throw new BusinessException('订单不存在或不属于当前用户');
            }
            if ((int) $lockedOrder->status !== OrderStatus::PENDING_PAY) {
                throw new BusinessException('订单已支付或已关闭');
            }

            /** @var UserWallet|null $wallet */
            $wallet = $this->model(UserWallet::class)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();
            if ($wallet === null || (int) $wallet->balance_cents < $amountCents) {
                throw new BusinessException('余额不足');
            }

            $before = (int) $wallet->balance_cents;
            $after = $before - $amountCents;

            $wallet->balance_cents = $after;
            $wallet->total_consume_cents = (int) $wallet->total_consume_cents + $amountCents;
            $wallet->save();

            UserWalletLog::create([
                'user_id' => $userId,
                'wallet_id' => (int) $wallet->id,
                'biz_type' => UserWalletLog::BIZ_ORDER_PAY,
                'biz_id' => (string) $lockedOrder->sn,
                'direction' => UserWalletLog::DIRECTION_EXPENSE,
                'change_cents' => $amountCents,
                'before_cents' => $before,
                'after_cents' => $after,
                'operator_type' => OperatorType::SYSTEM,
                'operator_id' => null,
                'remark' => '订单余额支付',
            ]);

            PaymentLog::create([
                'order_id' => (int) $lockedOrder->id,
                'order_sn' => (string) $lockedOrder->sn,
                'out_trade_no' => $tradeNo,
                'transaction_id' => $tradeNo,
                'pay_method' => PayMethod::BALANCE,
                'scene' => PayScene::NONE,
                'event_type' => PaymentLog::EVENT_PAID,
                'trade_state' => 'SUCCESS',
                'amount_cents' => $amountCents,
                'paid_at' => date('Y-m-d H:i:s'),
            ]);

            $lockedOrder->pay_method = PayMethod::BALANCE;
            $lockedOrder->pay_scene = null;
            $lockedOrder->trade_no = $tradeNo;
            $lockedOrder->save();

            $machine->transit(
                order: $lockedOrder,
                toStatus: OrderStatus::PAID,
                operatorType: OperatorType::SYSTEM,
                operatorId: null,
                remark: '支付成功（余额支付）',
            );
        });

        return [
            'order_id' => (int) $order->id,
            'sn' => (string) $order->sn,
            'status' => OrderStatus::PAID,
        ];
    }

    private function loadPayableOrder(int $userId, int $orderId): Order
    {
        /** @var Order|null $order */
        $order = $this->model(Order::class)
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($order === null) {
            throw new BusinessException('订单不存在或不属于当前用户');
        }
        if ((int) $order->status !== OrderStatus::PENDING_PAY) {
            throw new BusinessException('订单已支付或已关闭');
        }
        if ($order->expire_at !== null && strtotime((string) $order->expire_at) < time()) {
            throw new BusinessException('订单已超时，请重新下单');
        }

        return $order;
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

    private function generateTradeNo(string $sn): string
    {
        $suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        return mb_substr('BAL-' . $sn . '-' . $suffix, 0, 32);
    }
}
