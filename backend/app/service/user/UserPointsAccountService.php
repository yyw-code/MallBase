<?php
declare(strict_types=1);

namespace app\service\user;

use app\common\enum\OperatorType;
use app\common\enum\RefundOrderStatus;
use app\model\goods\Goods;
use app\model\goods\GoodsSku;
use app\model\marketing\PointsRule;
use app\model\order\Order;
use app\model\order\OrderItem;
use app\model\order\OrderPointsDeduction;
use app\model\order\OrderPointsReward;
use app\model\order\OrderPointsRewardItem;
use app\model\order\RefundOrder;
use app\model\user\UserPoints;
use app\model\user\UserPointsLog;
use app\service\marketing\PointsFeatureService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use Throwable;

/**
 * 用户积分账户服务
 *
 * @extends BaseService<UserPoints>
 */
class UserPointsAccountService extends BaseService
{
    private const UINT_MAX_POINTS = 4_294_967_295;

    private const REWARD_MODE_INHERIT = 'inherit';
    private const REWARD_MODE_DISABLED = 'disabled';
    private const REWARD_MODE_RATIO = 'ratio';
    private const REWARD_MODE_FIXED = 'fixed';
    private const REWARD_MODE_GLOBAL = 'global';
    private const REWARD_MODE_SKU = 'sku';

    protected string $modelClass = UserPoints::class;

    /**
     * @return array{balance_points:int,frozen_points:int,debt_points:int,total_income_points:int,total_expense_points:int}
     */
    public function info(int $userId): array
    {
        $points = $this->ensurePoints($userId);

        return [
            'balance_points' => (int) $points->balance_points,
            'frozen_points' => (int) ($points->frozen_points ?? 0),
            'debt_points' => (int) ($points->debt_points ?? 0),
            'total_income_points' => (int) $points->total_income_points,
            'total_expense_points' => (int) $points->total_expense_points,
        ];
    }

    public function isPointsEnabled(): bool
    {
        return app()->make(PointsFeatureService::class)->isEnabled();
    }

    public function isRewardEnabled(): bool
    {
        return app()->make(PointsFeatureService::class)->isRewardEnabled();
    }

    public function isDeductionEnabled(): bool
    {
        return app()->make(PointsFeatureService::class)->isDeductionEnabled();
    }

    public function rewardOrderCompleted(Order $order): void
    {
        if (!$this->isRewardEnabled()) {
            return;
        }

        $userId = (int) ($order->user_id ?? 0);
        $orderId = (int) ($order->id ?? 0);
        $orderSn = (string) ($order->sn ?? '');
        if ($userId <= 0 || $orderId <= 0 || $orderSn === '') {
            return;
        }
        if ($this->model(OrderPointsReward::class)->where('order_sn', $orderSn)->count() > 0) {
            return;
        }

        $rule = $this->activeRule(PointsRule::SCENE_ORDER_COMPLETE);
        if ($rule === null) {
            return;
        }

        $rewardItems = $this->buildOrderRewardItems($order, $rule);
        $rewardPoints = array_sum(array_map(static fn(array $row): int => (int) $row['reward_points'], $rewardItems));
        $maxPoints = (int) ($rule->max_points ?? 0);
        if ($maxPoints > 0 && $rewardPoints > $maxPoints) {
            $rewardItems = $this->capRewardItems($rewardItems, $maxPoints);
            $rewardPoints = $maxPoints;
        }
        if ($rewardPoints <= 0) {
            return;
        }

        $baseTime = (string) ($order->completed_at ?? '');
        $baseTimestamp = $baseTime !== '' ? (strtotime($baseTime) ?: time()) : time();
        $releaseTime = date('Y-m-d H:i:s', $baseTimestamp + ($this->pointsRewardFreezeDays() * 86400));

        $this->transaction(function () use ($userId, $orderId, $orderSn, $rewardPoints, $rewardItems, $releaseTime): void {
            if ($this->model(OrderPointsReward::class)->where('order_sn', $orderSn)->lock(true)->count() > 0) {
                return;
            }

            $account = $this->lockedPoints($userId);
            $beforeFrozen = (int) ($account->frozen_points ?? 0);
            $afterFrozen = $beforeFrozen + $rewardPoints;
            if ($afterFrozen > self::UINT_MAX_POINTS) {
                throw new BusinessException('用户冻结积分超出系统限制');
            }
            $account->frozen_points = $afterFrozen;
            $account->save();

            /** @var OrderPointsReward $reward */
            $reward = $this->model(OrderPointsReward::class);
            $reward->save([
                'order_id' => $orderId,
                'order_sn' => $orderSn,
                'user_id' => $userId,
                'reward_points' => $rewardPoints,
                'frozen_points' => $rewardPoints,
                'released_points' => 0,
                'recovered_points' => 0,
                'debt_points' => 0,
                'release_time' => $releaseTime,
                'status' => OrderPointsReward::STATUS_FROZEN,
            ]);

            foreach ($rewardItems as $item) {
                $item['reward_id'] = (int) $reward->id;
                $this->model(OrderPointsRewardItem::class)->newInstance()->save($item);
            }

            $this->createLog(
                account: $account,
                bizType: UserPointsLog::BIZ_ORDER_COMPLETE,
                bizId: $orderSn,
                direction: UserPointsLog::DIRECTION_INCOME,
                accountType: UserPointsLog::ACCOUNT_FROZEN,
                changePoints: $rewardPoints,
                beforePoints: $beforeFrozen,
                afterPoints: $afterFrozen,
                operatorType: OperatorType::SYSTEM,
                operatorId: null,
                remark: '订单完成奖励积分，售后期内冻结'
            );
        });
    }

    public function rollbackRefundCompleted(RefundOrder $refund): void
    {
        $this->recoverRewardByRefund($refund);
        $this->returnDeductionByRefund($refund);
    }

    /**
     * @return array{released:int,scanned:int}
     */
    public function releaseDueRewards(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $rows = $this->model(OrderPointsReward::class)
            ->where('status', OrderPointsReward::STATUS_FROZEN)
            ->where('release_time', '<=', date('Y-m-d H:i:s'))
            ->where('frozen_points', '>', 0)
            ->order('id', 'asc')
            ->limit($limit)
            ->field('id')
            ->select()
            ->toArray();

        $released = 0;
        foreach ($rows as $row) {
            $released += $this->releaseReward((int) ($row['id'] ?? 0));
        }

        return ['released' => $released, 'scanned' => count($rows)];
    }

    /**
     * @return array{balance_points:int}
     */
    public function adminAdjust(int $userId, string $direction, int $points, string $remark, int $adminId): array
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }
        if (!in_array($direction, [UserPointsLog::DIRECTION_INCOME, UserPointsLog::DIRECTION_EXPENSE], true)) {
            throw new BusinessException('调整方向不合法');
        }
        if ($points <= 0) {
            throw new BusinessException('调整积分必须大于 0');
        }
        if (trim($remark) === '') {
            throw new BusinessException('请填写调整原因');
        }

        $after = $this->changeAvailable(
            userId: $userId,
            direction: $direction,
            points: $points,
            bizType: UserPointsLog::BIZ_ADMIN_ADJUST,
            bizId: $this->adjustNo(),
            operatorType: OperatorType::ADMIN,
            operatorId: $adminId,
            remark: $remark
        );

        return ['balance_points' => $after];
    }

    /**
     * @return array{
     *   enabled:bool,available_points:int,usable_points:int,used_points:int,discount_amount:string,
     *   points_per_yuan:int,max_percent:int
     * }
     */
    public function deductionQuote(int $userId, string $eligibleAmount, bool $usePoints, int $requestedPoints = 0): array
    {
        $enabled = $this->isDeductionEnabled();
        $pointsPerYuan = max(1, $this->settingInt('points_deduction_points_per_yuan', 100));
        $maxPercent = max(0, min(100, $this->settingInt('points_deduction_max_percent', 50)));
        $available = $userId > 0 ? (int) $this->ensurePoints($userId)->balance_points : 0;
        $eligibleCents = $this->decimalToCents($eligibleAmount);
        $maxDiscountCents = $enabled ? intdiv($eligibleCents * $maxPercent, 100) : 0;
        $maxPointsByAmount = intdiv($maxDiscountCents * $pointsPerYuan, 100);
        $usablePoints = max(0, min($available, $maxPointsByAmount));

        $usedPoints = 0;
        $discountCents = 0;
        if ($enabled && $usePoints && $usablePoints > 0) {
            $usedPoints = $requestedPoints > 0
                ? min($requestedPoints, $usablePoints)
                : $usablePoints;
            $discountCents = min($maxDiscountCents, intdiv($usedPoints * 100, $pointsPerYuan));
            if ($discountCents <= 0) {
                $usedPoints = 0;
            }
        }

        return [
            'enabled' => $enabled,
            'available_points' => $available,
            'usable_points' => $usablePoints,
            'used_points' => $usedPoints,
            'discount_amount' => $this->centsToDecimal($discountCents),
            'points_per_yuan' => $pointsPerYuan,
            'max_percent' => $maxPercent,
        ];
    }

    /**
     * 下单前积分赠送试算。
     *
     * 实际发放仍在订单完成后由 rewardOrderCompleted() 幂等落库；
     * 这里仅复用同一套商品/SKU/全局规则，给确认页展示预期赠送积分。
     *
     * @param array<int,array{goods_id:int,sku_id:int,pay_amount:string,quantity:int}> $items
     * @return array{
     *   enabled:bool,reward_points:int,freeze_days:int,max_points:int,
     *   items:array<int,array<string,mixed>>
     * }
     */
    public function rewardQuote(array $items): array
    {
        $empty = [
            'enabled' => false,
            'reward_points' => 0,
            'freeze_days' => $this->pointsRewardFreezeDays(),
            'max_points' => 0,
            'items' => [],
        ];

        if (!$this->isRewardEnabled() || $items === []) {
            return $empty;
        }

        $rule = $this->activeRule(PointsRule::SCENE_ORDER_COMPLETE);
        if ($rule === null) {
            return [
                ...$empty,
                'enabled' => true,
            ];
        }

        $rewardItems = $this->buildRewardItems($items, $rule);
        $rewardPoints = array_sum(array_map(static fn(array $row): int => (int) $row['reward_points'], $rewardItems));
        $maxPoints = (int) ($rule->max_points ?? 0);
        if ($maxPoints > 0 && $rewardPoints > $maxPoints) {
            $rewardItems = $this->capRewardItems($rewardItems, $maxPoints);
            $rewardPoints = $maxPoints;
        }

        return [
            'enabled' => true,
            'reward_points' => $rewardPoints,
            'freeze_days' => $this->pointsRewardFreezeDays(),
            'max_points' => $maxPoints,
            'items' => $rewardItems,
        ];
    }

    public function deductForOrder(int $userId, int $orderId, string $orderSn, int $usedPoints, string $discountAmount): void
    {
        if ($userId <= 0 || $orderId <= 0 || $orderSn === '' || $usedPoints <= 0) {
            return;
        }
        if (!$this->isDeductionEnabled()) {
            throw new BusinessException('积分抵扣未开启');
        }
        if ($this->model(OrderPointsDeduction::class)->where('order_sn', $orderSn)->count() > 0) {
            return;
        }

        $this->transaction(function () use ($userId, $orderId, $orderSn, $usedPoints, $discountAmount): void {
            if ($this->model(OrderPointsDeduction::class)->where('order_sn', $orderSn)->lock(true)->count() > 0) {
                return;
            }
            $this->changeAvailable(
                userId: $userId,
                direction: UserPointsLog::DIRECTION_EXPENSE,
                points: $usedPoints,
                bizType: UserPointsLog::BIZ_ORDER_DEDUCTION,
                bizId: $orderSn,
                operatorType: OperatorType::BUYER,
                operatorId: $userId,
                remark: '订单积分抵扣'
            );

            $this->model(OrderPointsDeduction::class)->save([
                'order_id' => $orderId,
                'order_sn' => $orderSn,
                'user_id' => $userId,
                'used_points' => $usedPoints,
                'discount_amount' => $discountAmount,
                'returned_points' => 0,
                'status' => OrderPointsDeduction::STATUS_USED,
            ]);
        });
    }

    public function deductForExchange(int $userId, string $exchangeSn, int $points): void
    {
        if ($userId <= 0 || $exchangeSn === '' || $points <= 0) {
            return;
        }
        if (!$this->isPointsEnabled()) {
            throw new BusinessException('积分功能未开启');
        }

        $this->changeAvailable(
            userId: $userId,
            direction: UserPointsLog::DIRECTION_EXPENSE,
            points: $points,
            bizType: UserPointsLog::BIZ_POINTS_EXCHANGE,
            bizId: $exchangeSn,
            operatorType: OperatorType::BUYER,
            operatorId: $userId,
            remark: '积分商品兑换'
        );
    }

    public function returnExchange(int $userId, string $exchangeSn, int $points, int $adminId = 0): void
    {
        $this->returnExchangeByOperator(
            userId: $userId,
            exchangeSn: $exchangeSn,
            points: $points,
            operatorType: $adminId > 0 ? OperatorType::ADMIN : OperatorType::SYSTEM,
            operatorId: $adminId > 0 ? $adminId : null,
            remark: '积分兑换关闭返还'
        );
    }

    public function returnExchangeByOperator(
        int $userId,
        string $exchangeSn,
        int $points,
        int $operatorType,
        ?int $operatorId = null,
        string $remark = '积分兑换关闭返还'
    ): void
    {
        if ($userId <= 0 || $exchangeSn === '' || $points <= 0) {
            return;
        }
        if (!OperatorType::isValid($operatorType)) {
            throw new BusinessException('积分操作人类型不合法');
        }

        $this->changeAvailable(
            userId: $userId,
            direction: UserPointsLog::DIRECTION_INCOME,
            points: $points,
            bizType: UserPointsLog::BIZ_POINTS_EXCHANGE_RETURN,
            bizId: $exchangeSn,
            operatorType: $operatorType,
            operatorId: $operatorId,
            remark: $remark
        );
    }

    public function returnOrderDeduction(Order $order): void
    {
        $orderId = (int) ($order->id ?? 0);
        $orderSn = (string) ($order->sn ?? '');
        if ($orderId <= 0 || $orderSn === '') {
            return;
        }

        $this->transaction(function () use ($orderId, $orderSn): void {
            /** @var OrderPointsDeduction|null $deduction */
            $deduction = $this->model(OrderPointsDeduction::class)
                ->where('order_id', $orderId)
                ->lock(true)
                ->find();
            if ($deduction === null) {
                return;
            }
            $remaining = (int) $deduction->used_points - (int) $deduction->returned_points;
            if ($remaining <= 0 || $this->logExists(UserPointsLog::BIZ_ORDER_DEDUCTION_RETURN, $orderSn, UserPointsLog::DIRECTION_INCOME, UserPointsLog::ACCOUNT_BALANCE)) {
                return;
            }

            $after = $this->changeAvailable(
                userId: (int) $deduction->user_id,
                direction: UserPointsLog::DIRECTION_INCOME,
                points: $remaining,
                bizType: UserPointsLog::BIZ_ORDER_DEDUCTION_RETURN,
                bizId: $orderSn,
                operatorType: OperatorType::SYSTEM,
                operatorId: null,
                remark: '订单关闭返还抵扣积分'
            );

            $deduction->returned_points = (int) $deduction->returned_points + $remaining;
            $deduction->status = OrderPointsDeduction::STATUS_RETURNED;
            $deduction->save();
        });
    }

    public function ensurePoints(int $userId): UserPoints
    {
        /** @var UserPoints|null $points */
        $points = $this->model()->where('user_id', $userId)->find();
        if ($points !== null) {
            return $points;
        }

        return $this->createEmptyPointsAccount($userId, false);
    }

    private function lockedPoints(int $userId): UserPoints
    {
        /** @var UserPoints|null $points */
        $points = $this->model()->where('user_id', $userId)->lock(true)->find();
        if ($points !== null) {
            return $points;
        }

        return $this->createEmptyPointsAccount($userId, true);
    }

    private function createEmptyPointsAccount(int $userId, bool $lock): UserPoints
    {
        /** @var UserPoints $created */
        $created = $this->model();
        try {
            $created->save([
                'user_id' => $userId,
                'balance_points' => 0,
                'frozen_points' => 0,
                'debt_points' => 0,
                'total_income_points' => 0,
                'total_expense_points' => 0,
            ]);

            return $created;
        } catch (Throwable $e) {
            if (!$this->isDuplicateUserPointsAccount($e)) {
                throw $e;
            }

            $query = $this->model()->where('user_id', $userId);
            if ($lock) {
                $query->lock(true);
            }

            /** @var UserPoints|null $points */
            $points = $query->find();
            if ($points !== null) {
                return $points;
            }

            throw $e;
        }
    }

    private function isDuplicateUserPointsAccount(Throwable $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, '1062')
            && str_contains($message, 'user_points')
            && str_contains($message, 'uk_user_id');
    }

    private function changeAvailable(
        int $userId,
        string $direction,
        int $points,
        string $bizType,
        string $bizId,
        int $operatorType,
        ?int $operatorId,
        string $remark,
        bool $allowPartialExpense = false
    ): int {
        if ($userId <= 0) {
            throw new BusinessException('用户无效');
        }
        if ($bizType === '' || $bizId === '') {
            throw new BusinessException('积分业务标识缺失');
        }
        if ($points <= 0) {
            throw new BusinessException('积分变动值不合法');
        }

        $after = 0;
        $this->transaction(function () use (
            $userId,
            $direction,
            $points,
            $bizType,
            $bizId,
            $operatorType,
            $operatorId,
            $remark,
            $allowPartialExpense,
            &$after
        ): void {
            if ($this->logExists($bizType, $bizId, $direction, UserPointsLog::ACCOUNT_BALANCE)) {
                $after = (int) $this->ensurePoints($userId)->balance_points;
                return;
            }

            $account = $this->lockedPoints($userId);
            if ($direction === UserPointsLog::DIRECTION_INCOME) {
                [$before, $after, $debtOffset] = $this->applyBalanceIncome($account, $points);
                $account->save();
                $finalRemark = $debtOffset > 0 ? $remark . sprintf('（优先抵扣欠账 %d 积分）', $debtOffset) : $remark;
            } elseif ($direction === UserPointsLog::DIRECTION_EXPENSE) {
                [$before, $after, $changePoints] = $this->applyBalanceExpense($account, $points, $allowPartialExpense);
                if ($changePoints <= 0) {
                    return;
                }
                $points = $changePoints;
                $account->save();
                $finalRemark = $remark;
            } else {
                throw new BusinessException('积分变动方向不合法');
            }

            $this->createLog(
                account: $account,
                bizType: $bizType,
                bizId: $bizId,
                direction: $direction,
                accountType: UserPointsLog::ACCOUNT_BALANCE,
                changePoints: $points,
                beforePoints: $before,
                afterPoints: $after,
                operatorType: $operatorType,
                operatorId: $operatorId,
                remark: $finalRemark
            );
        });

        return $after;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildOrderRewardItems(Order $order, PointsRule $rule): array
    {
        $orderId = (int) $order->id;
        $items = $this->model(OrderItem::class)
            ->where('order_id', $orderId)
            ->field('id, order_id, goods_id, sku_id, pay_amount, quantity')
            ->select()
            ->toArray();
        if ($items === []) {
            return [];
        }

        return $this->buildRewardItems($items, $rule);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function buildRewardItems(array $items, PointsRule $rule): array
    {
        if ($items === []) {
            return [];
        }

        $goodsIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['goods_id'], $items)));
        $skuIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['sku_id'], $items)));
        $goodsMap = $goodsIds === [] ? [] : $this->model(Goods::class)
            ->whereIn('id', $goodsIds)
            ->column('id, points_reward_mode, points_reward_ratio, points_reward_fixed', 'id');
        $skuMap = $skuIds === [] ? [] : $this->model(GoodsSku::class)
            ->whereIn('id', $skuIds)
            ->column('id, points_reward_mode, points_reward_ratio, points_reward_fixed', 'id');

        $rows = [];
        foreach ($items as $item) {
            $config = $this->resolveRewardConfig($item, $goodsMap, $skuMap, $rule);
            $rewardPoints = $this->calculateItemRewardPoints((string) $item['pay_amount'], (int) $item['quantity'], $config);
            if ($rewardPoints <= 0) {
                continue;
            }
            $rows[] = [
                'order_id' => (int) ($item['order_id'] ?? 0),
                'order_item_id' => (int) ($item['order_item_id'] ?? $item['id'] ?? 0),
                'goods_id' => (int) $item['goods_id'],
                'sku_id' => (int) $item['sku_id'],
                'pay_amount' => (string) $item['pay_amount'],
                'quantity' => (int) $item['quantity'],
                'reward_mode' => $config['mode'],
                'reward_ratio' => $config['ratio'],
                'reward_fixed' => $config['fixed'],
                'reward_points' => $rewardPoints,
                'recovered_points' => 0,
            ];
            if (array_key_exists('source_index', $item)) {
                $rows[array_key_last($rows)]['source_index'] = (int) $item['source_index'];
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<int,array<string,mixed>> $goodsMap
     * @param array<int,array<string,mixed>> $skuMap
     * @return array{mode:string,ratio:int,fixed:int}
     */
    private function resolveRewardConfig(array $item, array $goodsMap, array $skuMap, PointsRule $rule): array
    {
        $goods = $goodsMap[(int) $item['goods_id']] ?? [];
        $goodsMode = $this->normalizeGoodsRewardMode((string) ($goods['points_reward_mode'] ?? self::REWARD_MODE_GLOBAL));
        if (in_array($goodsMode, [self::REWARD_MODE_DISABLED, self::REWARD_MODE_RATIO, self::REWARD_MODE_FIXED], true)) {
            return [
                'mode' => $goodsMode,
                'ratio' => max(0, (int) ($goods['points_reward_ratio'] ?? 0)),
                'fixed' => max(0, (int) ($goods['points_reward_fixed'] ?? 0)),
            ];
        }

        if ($goodsMode === self::REWARD_MODE_SKU) {
            $sku = $skuMap[(int) $item['sku_id']] ?? [];
            $skuMode = $this->normalizeSkuRewardMode((string) ($sku['points_reward_mode'] ?? self::REWARD_MODE_INHERIT));
            if ($skuMode !== self::REWARD_MODE_INHERIT) {
                return [
                    'mode' => $skuMode,
                    'ratio' => max(0, (int) ($sku['points_reward_ratio'] ?? 0)),
                    'fixed' => max(0, (int) ($sku['points_reward_fixed'] ?? 0)),
                ];
            }
        }

        return [
            'mode' => self::REWARD_MODE_GLOBAL,
            'ratio' => max(0, (int) ($rule->points_per_yuan ?? 0)),
            'fixed' => 0,
        ];
    }

    private function normalizeGoodsRewardMode(string $mode): string
    {
        $mode = trim($mode);
        if ($mode === '' || $mode === self::REWARD_MODE_INHERIT) {
            return self::REWARD_MODE_GLOBAL;
        }

        return in_array($mode, [
            self::REWARD_MODE_GLOBAL,
            self::REWARD_MODE_DISABLED,
            self::REWARD_MODE_RATIO,
            self::REWARD_MODE_FIXED,
            self::REWARD_MODE_SKU,
        ], true) ? $mode : self::REWARD_MODE_GLOBAL;
    }

    private function normalizeSkuRewardMode(string $mode): string
    {
        return in_array($mode, [
            self::REWARD_MODE_INHERIT,
            self::REWARD_MODE_DISABLED,
            self::REWARD_MODE_RATIO,
            self::REWARD_MODE_FIXED,
        ], true) ? $mode : self::REWARD_MODE_INHERIT;
    }

    /**
     * @param array{mode:string,ratio:int,fixed:int} $config
     */
    private function calculateItemRewardPoints(string $payAmount, int $quantity, array $config): int
    {
        if ($config['mode'] === self::REWARD_MODE_DISABLED) {
            return 0;
        }
        if ($config['mode'] === self::REWARD_MODE_FIXED) {
            return max(0, $config['fixed'] * max(0, $quantity));
        }

        $ratio = (int) $config['ratio'];
        if ($ratio <= 0) {
            return 0;
        }

        return intdiv($this->decimalToCents($payAmount) * $ratio, 100);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function capRewardItems(array $items, int $maxPoints): array
    {
        $total = array_sum(array_map(static fn(array $row): int => (int) $row['reward_points'], $items));
        if ($total <= 0 || $maxPoints <= 0 || $total <= $maxPoints) {
            return $items;
        }

        $allocated = 0;
        $lastIndex = array_key_last($items);
        foreach ($items as $index => &$item) {
            if ($index === $lastIndex) {
                $item['reward_points'] = max(0, $maxPoints - $allocated);
                break;
            }
            $points = intdiv((int) $item['reward_points'] * $maxPoints, $total);
            $item['reward_points'] = $points;
            $allocated += $points;
        }
        unset($item);

        return array_values(array_filter($items, static fn(array $row): bool => (int) $row['reward_points'] > 0));
    }

    private function releaseReward(int $rewardId): int
    {
        if ($rewardId <= 0) {
            return 0;
        }

        $released = 0;
        $this->transaction(function () use ($rewardId, &$released): void {
            /** @var OrderPointsReward|null $reward */
            $reward = $this->model(OrderPointsReward::class)->where('id', $rewardId)->lock(true)->find();
            if ($reward === null || (string) $reward->status !== OrderPointsReward::STATUS_FROZEN) {
                return;
            }
            $releasePoints = (int) $reward->frozen_points;
            if ($releasePoints <= 0) {
                $reward->status = ((int) $reward->recovered_points >= (int) $reward->reward_points)
                    ? OrderPointsReward::STATUS_RECOVERED
                    : OrderPointsReward::STATUS_RELEASED;
                $reward->save();
                return;
            }

            $account = $this->lockedPoints((int) $reward->user_id);
            $beforeFrozen = (int) ($account->frozen_points ?? 0);
            $releasePoints = min($releasePoints, $beforeFrozen);
            if ($releasePoints <= 0) {
                return;
            }

            $afterFrozen = $beforeFrozen - $releasePoints;
            $account->frozen_points = $afterFrozen;
            [$beforeBalance, $afterBalance, $debtOffset] = $this->applyBalanceIncome($account, $releasePoints);
            $account->save();

            $reward->frozen_points = max(0, (int) $reward->frozen_points - $releasePoints);
            $reward->released_points = (int) $reward->released_points + $releasePoints;
            $reward->released_at = date('Y-m-d H:i:s');
            $reward->status = (int) $reward->frozen_points > 0
                ? OrderPointsReward::STATUS_FROZEN
                : OrderPointsReward::STATUS_RELEASED;
            $reward->save();

            $this->createLog(
                account: $account,
                bizType: UserPointsLog::BIZ_ORDER_REWARD_RELEASE_FROZEN,
                bizId: (string) $reward->order_sn,
                direction: UserPointsLog::DIRECTION_EXPENSE,
                accountType: UserPointsLog::ACCOUNT_FROZEN,
                changePoints: $releasePoints,
                beforePoints: $beforeFrozen,
                afterPoints: $afterFrozen,
                operatorType: OperatorType::SYSTEM,
                operatorId: null,
                remark: '订单奖励冻结积分释放'
            );

            $remark = $debtOffset > 0
                ? sprintf('订单奖励积分释放（优先抵扣欠账 %d 积分）', $debtOffset)
                : '订单奖励积分释放';
            $this->createLog(
                account: $account,
                bizType: UserPointsLog::BIZ_ORDER_REWARD_RELEASE,
                bizId: (string) $reward->order_sn,
                direction: UserPointsLog::DIRECTION_INCOME,
                accountType: UserPointsLog::ACCOUNT_BALANCE,
                changePoints: $releasePoints,
                beforePoints: $beforeBalance,
                afterPoints: $afterBalance,
                operatorType: OperatorType::SYSTEM,
                operatorId: null,
                remark: $remark
            );

            $released = $releasePoints;
        });

        return $released;
    }

    private function recoverRewardByRefund(RefundOrder $refund): void
    {
        $refundSn = (string) ($refund->sn ?? '');
        $orderId = (int) ($refund->order_id ?? 0);
        $userId = (int) ($refund->user_id ?? 0);
        if ($refundSn === '' || $orderId <= 0 || $userId <= 0 || $this->refundRewardRecovered($refundSn)) {
            return;
        }

        $this->transaction(function () use ($refund, $refundSn, $orderId, $userId): void {
            /** @var OrderPointsReward|null $reward */
            $reward = $this->model(OrderPointsReward::class)
                ->where('order_id', $orderId)
                ->lock(true)
                ->find();
            if ($reward === null) {
                return;
            }

            [$recoverPoints, $rewardItem] = $this->refundRecoverPoints($refund, $reward);
            if ($recoverPoints <= 0) {
                return;
            }

            $account = $this->lockedPoints($userId);
            $remaining = $recoverPoints;

            $recoverFrozen = min($remaining, (int) $reward->frozen_points, (int) ($account->frozen_points ?? 0));
            if ($recoverFrozen > 0) {
                $beforeFrozen = (int) ($account->frozen_points ?? 0);
                $afterFrozen = $beforeFrozen - $recoverFrozen;
                $account->frozen_points = $afterFrozen;
                $reward->frozen_points = (int) $reward->frozen_points - $recoverFrozen;
                $remaining -= $recoverFrozen;
                $this->createLog(
                    account: $account,
                    bizType: UserPointsLog::BIZ_REFUND_FROZEN,
                    bizId: $refundSn,
                    direction: UserPointsLog::DIRECTION_EXPENSE,
                    accountType: UserPointsLog::ACCOUNT_FROZEN,
                    changePoints: $recoverFrozen,
                    beforePoints: $beforeFrozen,
                    afterPoints: $afterFrozen,
                    operatorType: OperatorType::SYSTEM,
                    operatorId: null,
                    remark: '售后退款回收冻结积分'
                );
            }

            $recoverBalance = 0;
            if ($remaining > 0) {
                [$beforeBalance, $afterBalance, $recoverBalance] = $this->applyBalanceExpense($account, $remaining, true);
                if ($recoverBalance > 0) {
                    $remaining -= $recoverBalance;
                    $this->createLog(
                        account: $account,
                        bizType: UserPointsLog::BIZ_REFUND,
                        bizId: $refundSn,
                        direction: UserPointsLog::DIRECTION_EXPENSE,
                        accountType: UserPointsLog::ACCOUNT_BALANCE,
                        changePoints: $recoverBalance,
                        beforePoints: $beforeBalance,
                        afterPoints: $afterBalance,
                        operatorType: OperatorType::SYSTEM,
                        operatorId: null,
                        remark: '售后退款回收已释放积分'
                    );
                }
            }

            $debtPoints = 0;
            if ($remaining > 0) {
                $beforeDebt = (int) ($account->debt_points ?? 0);
                $afterDebt = $beforeDebt + $remaining;
                if ($afterDebt > self::UINT_MAX_POINTS) {
                    throw new BusinessException('用户欠账积分超出系统限制');
                }
                $this->assertPointsCapacity((int) $account->total_expense_points, $remaining, '累计扣减积分超出系统限制');
                $account->debt_points = $afterDebt;
                $account->total_expense_points = (int) $account->total_expense_points + $remaining;
                $reward->debt_points = (int) $reward->debt_points + $remaining;
                $debtPoints = $remaining;
                $remaining = 0;
                $this->createLog(
                    account: $account,
                    bizType: UserPointsLog::BIZ_REFUND_DEBT,
                    bizId: $refundSn,
                    direction: UserPointsLog::DIRECTION_EXPENSE,
                    accountType: UserPointsLog::ACCOUNT_DEBT,
                    changePoints: $debtPoints,
                    beforePoints: $beforeDebt,
                    afterPoints: $afterDebt,
                    operatorType: OperatorType::SYSTEM,
                    operatorId: null,
                    remark: '售后退款回收形成欠账积分'
                );
            }

            $account->save();
            $recovered = $recoverFrozen + $recoverBalance + $debtPoints;
            $reward->recovered_points = (int) $reward->recovered_points + $recovered;
            $reward->status = $this->rewardStatus($reward);
            $reward->save();

            if ($rewardItem !== null && $recovered > 0) {
                $rewardItem->recovered_points = (int) $rewardItem->recovered_points + $recovered;
                $rewardItem->save();
            }
        });
    }

    /**
     * @return array{0:int,1:?OrderPointsRewardItem}
     */
    private function refundRecoverPoints(RefundOrder $refund, OrderPointsReward $reward): array
    {
        $remainingReward = max(0, (int) $reward->reward_points - (int) $reward->recovered_points);
        if ($remainingReward <= 0) {
            return [0, null];
        }

        $refundCents = $this->decimalToCents((string) ($refund->refund_amount ?? '0'));
        $orderItemId = (int) ($refund->order_item_id ?? 0);
        if ($orderItemId > 0) {
            /** @var OrderPointsRewardItem|null $rewardItem */
            $rewardItem = $this->model(OrderPointsRewardItem::class)
                ->where('reward_id', (int) $reward->id)
                ->where('order_item_id', $orderItemId)
                ->lock(true)
                ->find();
            if ($rewardItem === null) {
                return [0, null];
            }

            $itemRemaining = max(0, (int) $rewardItem->reward_points - (int) $rewardItem->recovered_points);
            if ($itemRemaining <= 0) {
                return [0, $rewardItem];
            }

            $itemPayCents = $this->decimalToCents((string) $rewardItem->pay_amount);
            $points = $itemPayCents > 0
                ? intdiv((int) $rewardItem->reward_points * min($refundCents, $itemPayCents), $itemPayCents)
                : 0;
            if ($points <= 0 && $refundCents > 0) {
                $points = 1;
            }
            return [min($points, $itemRemaining, $remainingReward), $rewardItem];
        }

        $orderPaidCents = $this->orderRewardPaidCents((int) $reward->order_id);
        $points = $orderPaidCents > 0
            ? intdiv((int) $reward->reward_points * min($refundCents, $orderPaidCents), $orderPaidCents)
            : 0;
        if ($points <= 0 && $refundCents > 0) {
            $points = 1;
        }

        return [min($points, $remainingReward), null];
    }

    private function returnDeductionByRefund(RefundOrder $refund): void
    {
        $refundSn = (string) ($refund->sn ?? '');
        $orderId = (int) ($refund->order_id ?? 0);
        if ($refundSn === '' || $orderId <= 0 || $this->logExists(UserPointsLog::BIZ_REFUND_DEDUCTION_RETURN, $refundSn, UserPointsLog::DIRECTION_INCOME, UserPointsLog::ACCOUNT_BALANCE)) {
            return;
        }

        $this->transaction(function () use ($refund, $refundSn, $orderId): void {
            /** @var OrderPointsDeduction|null $deduction */
            $deduction = $this->model(OrderPointsDeduction::class)
                ->where('order_id', $orderId)
                ->lock(true)
                ->find();
            if ($deduction === null) {
                return;
            }

            $remaining = (int) $deduction->used_points - (int) $deduction->returned_points;
            if ($remaining <= 0) {
                return;
            }

            $basisCents = $this->orderDeductionPaidCents($orderId);
            $refundCents = $this->decimalToCents((string) ($refund->refund_amount ?? '0'));
            $returnPoints = $basisCents > 0
                ? intdiv((int) $deduction->used_points * min($refundCents, $basisCents), $basisCents)
                : 0;
            if ($returnPoints <= 0 && $refundCents > 0) {
                $returnPoints = 1;
            }
            $returnPoints = min($returnPoints, $remaining);
            if ($returnPoints <= 0) {
                return;
            }

            $this->changeAvailable(
                userId: (int) $deduction->user_id,
                direction: UserPointsLog::DIRECTION_INCOME,
                points: $returnPoints,
                bizType: UserPointsLog::BIZ_REFUND_DEDUCTION_RETURN,
                bizId: $refundSn,
                operatorType: OperatorType::SYSTEM,
                operatorId: null,
                remark: '售后退款返还抵扣积分'
            );

            $deduction->returned_points = (int) $deduction->returned_points + $returnPoints;
            $deduction->status = ((int) $deduction->returned_points >= (int) $deduction->used_points)
                ? OrderPointsDeduction::STATUS_RETURNED
                : OrderPointsDeduction::STATUS_PARTIAL;
            $deduction->save();
        });
    }

    private function rewardStatus(OrderPointsReward $reward): string
    {
        if ((int) $reward->recovered_points >= (int) $reward->reward_points) {
            return OrderPointsReward::STATUS_RECOVERED;
        }
        if ((int) $reward->frozen_points > 0) {
            return OrderPointsReward::STATUS_FROZEN;
        }
        return OrderPointsReward::STATUS_RELEASED;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function applyBalanceIncome(UserPoints $account, int $points): array
    {
        $before = (int) $account->balance_points;
        $debtBefore = (int) ($account->debt_points ?? 0);
        $debtOffset = min($debtBefore, $points);
        $balanceIncrease = $points - $debtOffset;
        $after = $before + $balanceIncrease;
        if ($after > self::UINT_MAX_POINTS) {
            throw new BusinessException('用户积分超出系统限制');
        }
        $this->assertPointsCapacity((int) $account->total_income_points, $points, '累计获得积分超出系统限制');
        $account->balance_points = $after;
        $account->debt_points = $debtBefore - $debtOffset;
        $account->total_income_points = (int) $account->total_income_points + $points;

        return [$before, $after, $debtOffset];
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function applyBalanceExpense(UserPoints $account, int $points, bool $allowPartialExpense): array
    {
        $before = (int) $account->balance_points;
        $changePoints = $points;
        if ($before < $changePoints) {
            if (!$allowPartialExpense) {
                throw new BusinessException('用户积分不足，无法扣减');
            }
            $changePoints = $before;
        }
        if ($changePoints <= 0) {
            return [$before, $before, 0];
        }
        $after = $before - $changePoints;
        $this->assertPointsCapacity((int) $account->total_expense_points, $changePoints, '累计扣减积分超出系统限制');
        $account->balance_points = $after;
        $account->total_expense_points = (int) $account->total_expense_points + $changePoints;

        return [$before, $after, $changePoints];
    }

    private function activeRule(string $scene): ?PointsRule
    {
        /** @var PointsRule|null $rule */
        $rule = $this->model(PointsRule::class)
            ->where('scene', $scene)
            ->where('status', 1)
            ->find();

        return $rule;
    }

    private function orderRewardPaidCents(int $orderId): int
    {
        return $this->decimalToCents((string) $this->model(OrderPointsRewardItem::class)
            ->where('order_id', $orderId)
            ->sum('pay_amount'));
    }

    private function orderDeductionPaidCents(int $orderId): int
    {
        /** @var Order|null $order */
        $order = $this->model(Order::class)->where('id', $orderId)->whereNull('delete_time')->find();
        if ($order === null) {
            return 0;
        }

        return max(
            0,
            $this->decimalToCents((string) ($order->total_amount ?? '0'))
            - $this->decimalToCents((string) ($order->discount_amount ?? '0'))
        );
    }

    private function refundRewardRecovered(string $refundSn): bool
    {
        return $this->model(UserPointsLog::class)
            ->where('biz_id', $refundSn)
            ->whereIn('biz_type', [
                UserPointsLog::BIZ_REFUND,
                UserPointsLog::BIZ_REFUND_FROZEN,
                UserPointsLog::BIZ_REFUND_DEBT,
            ])
            ->count() > 0;
    }

    private function logExists(string $bizType, string $bizId, string $direction, string $accountType): bool
    {
        return $this->model(UserPointsLog::class)
            ->where('biz_type', $bizType)
            ->where('biz_id', $bizId)
            ->where('direction', $direction)
            ->where('account_type', $accountType)
            ->count() > 0;
    }

    private function createLog(
        UserPoints $account,
        string $bizType,
        string $bizId,
        string $direction,
        string $accountType,
        int $changePoints,
        int $beforePoints,
        int $afterPoints,
        int $operatorType,
        ?int $operatorId,
        string $remark
    ): void {
        if ($changePoints <= 0) {
            return;
        }

        UserPointsLog::create([
            'user_id' => (int) $account->user_id,
            'points_id' => (int) $account->id,
            'biz_type' => $bizType,
            'biz_id' => $bizId,
            'direction' => $direction,
            'account_type' => $accountType,
            'change_points' => $changePoints,
            'before_points' => $beforePoints,
            'after_points' => $afterPoints,
            'operator_type' => $operatorType,
            'operator_id' => $operatorId,
            'remark' => mb_substr($remark, 0, 255),
        ]);
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '') {
            return 0;
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            return 0;
        }

        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $yuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
    }

    private function centsToDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv(max(0, $cents), 100), max(0, $cents) % 100);
    }

    private function pointsRewardFreezeDays(): int
    {
        return max(0, $this->settingInt('points_reward_freeze_days', 7));
    }

    private function settingInt(string $code, int $default): int
    {
        if (!function_exists('getSystemSetting')) {
            return $default;
        }

        return (int) getSystemSetting($code, (string) $default);
    }

    private function settingBool(string $code, bool $default): bool
    {
        if (!function_exists('getSystemSetting')) {
            return $default;
        }

        $value = (string) getSystemSetting($code, $default ? '1' : '0');
        return in_array($value, ['1', 'true', 'on'], true);
    }

    private function assertPointsCapacity(int $currentPoints, int $changePoints, string $message): void
    {
        if ($currentPoints > self::UINT_MAX_POINTS - $changePoints) {
            throw new BusinessException($message);
        }
    }

    private function adjustNo(): string
    {
        return 'PNT-' . date('ymdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
