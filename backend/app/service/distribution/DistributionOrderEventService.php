<?php
declare(strict_types=1);

namespace app\service\distribution;

use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use app\model\distribution\DistributionCommissionLog;
use app\model\distribution\DistributionDistributor;
use app\model\distribution\DistributionOrderCommission;
use app\model\order\Order;
use app\model\order\OrderItem;
use app\model\order\RefundOrder;
use mall_base\base\BaseService;

/**
 * 分销订单生命周期事件服务
 *
 * 订单主链路只调用本服务，不直接承载佣金规则。
 *
 * @extends BaseService<DistributionOrderCommission>
 */
class DistributionOrderEventService extends BaseService
{
    protected string $modelClass = DistributionOrderCommission::class;

    public function handleOrderPaid(Order $order): void
    {
        /** @var DistributionConfigService $configService */
        $configService = app()->make(DistributionConfigService::class);
        $settings = $configService->settings();
        if (!$settings['distribution_enabled']) {
            return;
        }

        $orderId = (int) $order->id;
        $orderSn = (string) $order->sn;
        $buyerUserId = (int) $order->user_id;
        if ($orderId <= 0 || $orderSn === '' || $buyerUserId <= 0) {
            return;
        }
        if ($this->model()->where('order_sn', $orderSn)->count() > 0) {
            app()->make(DistributionEnrollmentService::class)->handlePaidOrderQualification($order);
            return;
        }

        /** @var DistributionRelationService $relationService */
        $relationService = app()->make(DistributionRelationService::class);
        $beneficiaries = $relationService->beneficiariesForBuyer(
            $buyerUserId,
            (bool) $settings['self_purchase_enabled'],
            (bool) $settings['second_level_enabled']
        );
        if ($beneficiaries === []) {
            app()->make(DistributionEnrollmentService::class)->handlePaidOrderQualification($order);
            return;
        }

        $distributorIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['user_id'], $beneficiaries)));
        /** @var DistributionAccountService $accountService */
        $accountService = app()->make(DistributionAccountService::class);
        $distributors = $accountService->activeDistributorsByUserIds($distributorIds);
        if ($distributors === []) {
            app()->make(DistributionEnrollmentService::class)->handlePaidOrderQualification($order);
            return;
        }

        $items = $this->orderItems($orderId);
        if ($items === []) {
            app()->make(DistributionEnrollmentService::class)->handlePaidOrderQualification($order);
            return;
        }

        /** @var DistributionCommissionCalculator $calculator */
        $calculator = app()->make(DistributionCommissionCalculator::class);
        $touchedUsers = [];

        $this->transaction(function () use ($items, $beneficiaries, $distributors, $calculator, $settings, $orderId, $orderSn, $buyerUserId, $accountService, &$touchedUsers): void {
            if ($this->model()->where('order_sn', $orderSn)->lock(true)->count() > 0) {
                return;
            }

            foreach ($items as $item) {
                foreach ($beneficiaries as $beneficiary) {
                    $distributorUserId = (int) $beneficiary['user_id'];
                    $relationLevel = (int) $beneficiary['relation_level'];
                    /** @var DistributionDistributor|null $distributor */
                    $distributor = $distributors[$distributorUserId] ?? null;
                    if ($distributor === null) {
                        continue;
                    }

                    $quote = $calculator->quote($item, $distributor, $relationLevel, $settings);
                    $amountCents = (int) $quote['amount_cents'];
                    if ($amountCents <= 0) {
                        continue;
                    }

                    /** @var DistributionOrderCommission $commission */
                    $commission = $this->model();
                    $commission->save([
                        'order_id' => $orderId,
                        'order_sn' => $orderSn,
                        'order_item_id' => (int) $item['id'],
                        'buyer_user_id' => $buyerUserId,
                        'distributor_user_id' => $distributorUserId,
                        'relation_id' => (int) ($beneficiary['relation_id'] ?? 0),
                        'relation_level' => $relationLevel,
                        'goods_id' => (int) $item['goods_id'],
                        'sku_id' => (int) $item['sku_id'],
                        'base_amount_cents' => $this->decimalToCents((string) $item['pay_amount']),
                        'rate' => $quote['rate'],
                        'amount_cents' => $amountCents,
                        'recovered_cents' => 0,
                        'rule_type' => $quote['rule_type'],
                        'rule_id' => $quote['rule_id'],
                        'attribution_scene' => (string) ($beneficiary['attribution_scene'] ?? ''),
                        'attribution_target_type' => (string) ($beneficiary['attribution_target_type'] ?? ''),
                        'attribution_target_id' => (int) ($beneficiary['attribution_target_id'] ?? 0),
                        'status' => DistributionOrderCommission::STATUS_FROZEN,
                    ]);

                    $accountService->addFrozen(
                        userId: $distributorUserId,
                        amountCents: $amountCents,
                        commissionId: (int) $commission->id,
                        bizId: $orderSn,
                        remark: '订单支付后冻结分销佣金'
                    );
                    $touchedUsers[$distributorUserId] = true;
                }
            }

            foreach (array_keys($touchedUsers) as $userId) {
                $this->model(DistributionDistributor::class)
                    ->where('user_id', (int) $userId)
                    ->inc('order_count')
                    ->update();
            }
        });

        app()->make(DistributionEnrollmentService::class)->handlePaidOrderQualification($order);
    }

    public function handleOrderCompleted(Order $order): void
    {
        if ((int) $order->id <= 0) {
            return;
        }
        /** @var DistributionConfigService $config */
        $config = app()->make(DistributionConfigService::class);
        $settings = $config->settings();
        $baseTime = (string) ($order->completed_at ?? '');
        $baseTimestamp = $baseTime !== '' ? (strtotime($baseTime) ?: time()) : time();
        $releaseTime = date('Y-m-d H:i:s', $baseTimestamp + ((int) $settings['settlement_days'] * 86400));

        $rows = $this->model()
            ->where('order_id', (int) $order->id)
            ->where('status', DistributionOrderCommission::STATUS_FROZEN)
            ->field('id')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $this->markPendingOrSettle((int) ($row['id'] ?? 0), $releaseTime);
        }
    }

    public function handleOrderClosed(Order $order): void
    {
        if ((int) $order->id <= 0) {
            return;
        }
        $rows = $this->model()
            ->where('order_id', (int) $order->id)
            ->whereIn('status', [
                DistributionOrderCommission::STATUS_FROZEN,
                DistributionOrderCommission::STATUS_PENDING_SETTLE,
            ])
            ->field('id')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $this->cancelCommission((int) ($row['id'] ?? 0), '订单关闭取消分销佣金');
        }
    }

    public function handleRefundCompleted(RefundOrder $refund): void
    {
        if ((int) ($refund->status ?? 0) !== RefundOrderStatus::COMPLETED) {
            return;
        }
        $orderItemId = (int) ($refund->order_item_id ?? 0);
        $refundCents = $this->decimalToCents((string) ($refund->refund_amount ?? '0.00'));
        if ($orderItemId <= 0 || $refundCents <= 0) {
            return;
        }

        /** @var OrderItem|null $item */
        $item = $this->model(OrderItem::class)->where('id', $orderItemId)->find();
        if ($item === null) {
            return;
        }
        $itemPayCents = max(0, $this->decimalToCents((string) $item->pay_amount));
        if ($itemPayCents <= 0) {
            return;
        }

        $rows = $this->model()
            ->where('order_item_id', $orderItemId)
            ->whereIn('status', [
                DistributionOrderCommission::STATUS_FROZEN,
                DistributionOrderCommission::STATUS_PENDING_SETTLE,
                DistributionOrderCommission::STATUS_SETTLED,
            ])
            ->field('id')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $this->recoverCommissionByRefund((int) ($row['id'] ?? 0), $refundCents, $itemPayCents, (string) $refund->sn);
        }
    }

    /**
     * @return array{released:int,scanned:int}
     */
    public function releaseDueCommissions(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $rows = $this->model()
            ->where('status', DistributionOrderCommission::STATUS_PENDING_SETTLE)
            ->where('release_time', '<=', date('Y-m-d H:i:s'))
            ->order('id', 'asc')
            ->limit($limit)
            ->field('id')
            ->select()
            ->toArray();

        $released = 0;
        foreach ($rows as $row) {
            $released += $this->settleCommission((int) ($row['id'] ?? 0)) ? 1 : 0;
        }
        return ['released' => $released, 'scanned' => count($rows)];
    }

    private function markPendingOrSettle(int $commissionId, string $releaseTime): void
    {
        if ($commissionId <= 0) {
            return;
        }
        if (strtotime($releaseTime) <= time()) {
            $this->settleCommission($commissionId);
            return;
        }

        $this->model()
            ->where('id', $commissionId)
            ->where('status', DistributionOrderCommission::STATUS_FROZEN)
            ->update([
                'status' => DistributionOrderCommission::STATUS_PENDING_SETTLE,
                'release_time' => $releaseTime,
            ]);
    }

    private function settleCommission(int $commissionId): bool
    {
        if ($commissionId <= 0) {
            return false;
        }

        /** @var DistributionAccountService $accountService */
        $accountService = app()->make(DistributionAccountService::class);

        return (bool) $this->transaction(function () use ($commissionId, $accountService): bool {
            /** @var DistributionOrderCommission|null $commission */
            $commission = $this->model()
                ->where('id', $commissionId)
                ->lock(true)
                ->find();
            if ($commission === null || !in_array((int) $commission->status, [
                DistributionOrderCommission::STATUS_FROZEN,
                DistributionOrderCommission::STATUS_PENDING_SETTLE,
            ], true)) {
                return false;
            }

            $remain = max(0, (int) $commission->amount_cents - (int) $commission->recovered_cents);
            if ($remain <= 0) {
                $commission->status = DistributionOrderCommission::STATUS_RECOVERED;
                $commission->save();
                return false;
            }

            $accountService->settleFrozenToAvailable(
                userId: (int) $commission->distributor_user_id,
                amountCents: $remain,
                commissionId: (int) $commission->id,
                bizId: (string) $commission->order_sn,
            );
            $commission->status = DistributionOrderCommission::STATUS_SETTLED;
            $commission->release_time = $commission->release_time ?: date('Y-m-d H:i:s');
            $commission->settled_at = date('Y-m-d H:i:s');
            $commission->save();

            return true;
        });
    }

    private function cancelCommission(int $commissionId, string $remark): void
    {
        if ($commissionId <= 0) {
            return;
        }

        /** @var DistributionAccountService $accountService */
        $accountService = app()->make(DistributionAccountService::class);
        $this->transaction(function () use ($commissionId, $accountService, $remark): void {
            /** @var DistributionOrderCommission|null $commission */
            $commission = $this->model()
                ->where('id', $commissionId)
                ->lock(true)
                ->find();
            if ($commission === null || !in_array((int) $commission->status, [
                DistributionOrderCommission::STATUS_FROZEN,
                DistributionOrderCommission::STATUS_PENDING_SETTLE,
            ], true)) {
                return;
            }

            $remain = max(0, (int) $commission->amount_cents - (int) $commission->recovered_cents);
            if ($remain > 0) {
                $accountService->recoverFromFrozenOrDebt(
                    userId: (int) $commission->distributor_user_id,
                    amountCents: $remain,
                    commissionId: (int) $commission->id,
                    bizId: (string) $commission->order_sn,
                );
                $commission->recovered_cents = (int) $commission->recovered_cents + $remain;
            }
            $commission->status = DistributionOrderCommission::STATUS_CANCELED;
            $commission->save();
        });
    }

    private function recoverCommissionByRefund(int $commissionId, int $refundCents, int $itemPayCents, string $refundSn): void
    {
        if ($commissionId <= 0 || $refundCents <= 0 || $itemPayCents <= 0) {
            return;
        }

        /** @var DistributionAccountService $accountService */
        $accountService = app()->make(DistributionAccountService::class);

        $this->transaction(function () use ($commissionId, $refundCents, $itemPayCents, $refundSn, $accountService): void {
            /** @var DistributionOrderCommission|null $commission */
            $commission = $this->model()
                ->where('id', $commissionId)
                ->lock(true)
                ->find();
            if ($commission === null || !in_array((int) $commission->status, [
                DistributionOrderCommission::STATUS_FROZEN,
                DistributionOrderCommission::STATUS_PENDING_SETTLE,
                DistributionOrderCommission::STATUS_SETTLED,
            ], true)) {
                return;
            }

            $remaining = max(0, (int) $commission->amount_cents - (int) $commission->recovered_cents);
            if ($remaining <= 0) {
                return;
            }

            $recover = (int) floor(((int) $commission->amount_cents * min($refundCents, $itemPayCents) / $itemPayCents) + 0.5);
            $recover = min($remaining, max(0, $recover));
            if ($recover <= 0) {
                return;
            }

            if ((int) $commission->status === DistributionOrderCommission::STATUS_SETTLED) {
                $accountService->recoverFromAvailableOrDebt(
                    userId: (int) $commission->distributor_user_id,
                    amountCents: $recover,
                    commissionId: (int) $commission->id,
                    bizId: $refundSn,
                );
            } else {
                $accountService->recoverFromFrozenOrDebt(
                    userId: (int) $commission->distributor_user_id,
                    amountCents: $recover,
                    commissionId: (int) $commission->id,
                    bizId: $refundSn,
                );
            }

            $commission->recovered_cents = (int) $commission->recovered_cents + $recover;
            if ((int) $commission->recovered_cents >= (int) $commission->amount_cents) {
                $commission->status = DistributionOrderCommission::STATUS_RECOVERED;
            }
            $commission->save();
        });
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function orderItems(int $orderId): array
    {
        return $this->model(OrderItem::class)
            ->where('order_id', $orderId)
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            return 0;
        }
        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $yuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
    }
}
