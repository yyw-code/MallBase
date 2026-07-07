<?php

declare(strict_types=1);

namespace Tests\Feature\Points;

use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use app\model\order\Order;
use app\model\order\RefundOrder;
use app\model\user\UserPointsLog;
use app\service\client\user\UserPointsService as ClientUserPointsService;
use app\service\SystemSettingService;
use app\service\user\UserPointsAccountService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;
use Throwable;

final class PointsAccountServiceContractTest extends TestCase
{
    private ?App $app = null;
    private bool $dbReady = false;

    public function testEnsurePointsCreatesAccountOnlyOnce(): void
    {
        $this->requireDbTables([
            'user_points',
        ]);

        Db::startTrans();
        try {
            $service = $this->pointsService();
            $userId = $this->testUserId();

            $first = $service->ensurePoints($userId);
            $second = $service->ensurePoints($userId);

            $this->assertSame((int) $first->id, (int) $second->id);
            $this->assertSame(1, (int) Db::name('user_points')->where('user_id', $userId)->count());
            $this->assertSame(0, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'));
        } finally {
            Db::rollback();
        }
    }

    public function testDeductForOrderAndReturnWhenOrderClosed(): void
    {
        $this->requireDbTables([
            'user_points',
            'user_points_log',
            'order',
            'order_points_deduction',
        ]);

        Db::startTrans();
        try {
            $service = $this->pointsService();
            $userId = $this->testUserId();
            $orderSn = $this->sn('POD');
            $orderId = $this->insertOrder($userId, $orderSn, '100.00', '50.00', OrderStatus::PENDING_PAY);
            Db::name('user_points')->insert([
                'user_id' => $userId,
                'balance_points' => 12000,
                'frozen_points' => 0,
                'debt_points' => 0,
                'total_income_points' => 12000,
                'total_expense_points' => 0,
            ]);

            $quote = $service->deductionQuote($userId, '100.00', true);
            $this->assertSame(5000, $quote['used_points']);
            $this->assertSame('50.00', $quote['discount_amount']);

            $service->deductForOrder($userId, $orderId, $orderSn, 5000, '50.00');
            $this->assertSame(7000, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'));
            $this->assertSame(5000, (int) Db::name('order_points_deduction')->where('order_sn', $orderSn)->value('used_points'));

            $order = $this->findOrder($orderId);
            $service->returnOrderDeduction($order);

            $this->assertSame(12000, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'));
            $this->assertSame(5000, (int) Db::name('order_points_deduction')->where('order_sn', $orderSn)->value('returned_points'));
            $this->assertSame(1, (int) Db::name('user_points_log')
                ->where('biz_type', UserPointsLog::BIZ_ORDER_DEDUCTION_RETURN)
                ->where('biz_id', $orderSn)
                ->count());
        } finally {
            Db::rollback();
        }
    }

    public function testOrderRewardUsesSkuRuleThenReleasesFrozenPoints(): void
    {
        $this->requireDbTables([
            'user_points',
            'user_points_log',
            'points_rule',
            'goods',
            'goods_sku',
            'order',
            'order_item',
            'order_points_reward',
            'order_points_reward_item',
        ]);

        Db::startTrans();
        try {
            $service = $this->pointsService();
            $this->resetOrderCompleteRule(1);

            $userId = $this->testUserId();
            $orderSn = $this->sn('POR');
            $goodsId = $this->insertGoods('sku', 0, 0);
            $skuId = $this->insertSku($goodsId, 'fixed', 0, 8);
            $orderId = $this->insertOrder($userId, $orderSn, '60.00', '60.00', OrderStatus::COMPLETED);
            $this->insertOrderItem($orderId, $goodsId, $skuId, '20.00', 3, '60.00');

            $service->rewardOrderCompleted($this->findOrder($orderId));

            $this->assertSame(24, (int) Db::name('user_points')->where('user_id', $userId)->value('frozen_points'));
            $this->assertSame(0, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'));
            $this->assertSame('fixed', (string) Db::name('order_points_reward_item')->where('order_id', $orderId)->value('reward_mode'));
            $this->assertSame(24, (int) Db::name('order_points_reward')->where('order_sn', $orderSn)->value('reward_points'));
            $releaseTime = (string) Db::name('order_points_reward')->where('order_sn', $orderSn)->value('release_time');

            $clientLogs = ($this->app?->make(ClientUserPointsService::class) ?? app()->make(ClientUserPointsService::class))
                ->logs($userId, ['biz_type' => UserPointsLog::BIZ_ORDER_COMPLETE, 'range' => 'custom'], 1, 20);
            $this->assertSame($orderSn, (string) ($clientLogs['list'][0]['biz_id'] ?? ''));
            $this->assertSame($releaseTime, (string) ($clientLogs['list'][0]['release_time'] ?? ''));

            Db::name('order_points_reward')
                ->where('order_sn', $orderSn)
                ->update(['release_time' => date('Y-m-d H:i:s', time() - 60)]);

            $result = $service->releaseDueRewards(10);
            $this->assertSame(24, $result['released']);
            $this->assertSame(1, $result['scanned']);
            $this->assertSame(0, (int) Db::name('user_points')->where('user_id', $userId)->value('frozen_points'));
            $this->assertSame(24, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'));
            $this->assertSame('released', (string) Db::name('order_points_reward')->where('order_sn', $orderSn)->value('status'));
        } finally {
            Db::rollback();
        }
    }

    public function testOrderRewardUsesGoodsRuleUnlessSkuModeEnabled(): void
    {
        $this->requireDbTables([
            'user_points',
            'user_points_log',
            'points_rule',
            'goods',
            'goods_sku',
            'order',
            'order_item',
            'order_points_reward',
            'order_points_reward_item',
        ]);

        Db::startTrans();
        try {
            $service = $this->pointsService();
            $this->resetOrderCompleteRule(1);

            $userId = $this->testUserId();
            $orderSn = $this->sn('PGP');
            $goodsId = $this->insertGoods('ratio', 2, 0);
            $skuId = $this->insertSku($goodsId, 'fixed', 0, 8);
            $orderId = $this->insertOrder($userId, $orderSn, '60.00', '60.00', OrderStatus::COMPLETED);
            $this->insertOrderItem($orderId, $goodsId, $skuId, '20.00', 3, '60.00');

            $service->rewardOrderCompleted($this->findOrder($orderId));

            $this->assertSame('ratio', (string) Db::name('order_points_reward_item')->where('order_id', $orderId)->value('reward_mode'));
            $this->assertSame(120, (int) Db::name('order_points_reward')->where('order_sn', $orderSn)->value('reward_points'));
        } finally {
            Db::rollback();
        }
    }

    public function testRefundRecoversFrozenThenBalanceThenDebt(): void
    {
        $this->requireDbTables([
            'user_points',
            'user_points_log',
            'order',
            'order_item',
            'refund_order',
            'order_points_reward',
            'order_points_reward_item',
        ]);

        Db::startTrans();
        try {
            $service = $this->pointsService();
            $userId = $this->testUserId();
            $orderSn = $this->sn('PRR');
            $refundSn = $this->sn('RFD');
            $orderId = $this->insertOrder($userId, $orderSn, '30.00', '30.00', OrderStatus::COMPLETED);
            $orderItemId = $this->insertOrderItem($orderId, 0, 0, '30.00', 1, '30.00');
            Db::name('user_points')->insert([
                'user_id' => $userId,
                'balance_points' => 5,
                'frozen_points' => 10,
                'debt_points' => 0,
                'total_income_points' => 30,
                'total_expense_points' => 0,
            ]);
            $rewardId = (int) Db::name('order_points_reward')->insertGetId([
                'order_id' => $orderId,
                'order_sn' => $orderSn,
                'user_id' => $userId,
                'reward_points' => 30,
                'frozen_points' => 10,
                'released_points' => 20,
                'recovered_points' => 0,
                'debt_points' => 0,
                'release_time' => date('Y-m-d H:i:s', time() - 3600),
                'status' => 'frozen',
            ]);
            Db::name('order_points_reward_item')->insert([
                'reward_id' => $rewardId,
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'goods_id' => 0,
                'sku_id' => 0,
                'pay_amount' => '30.00',
                'quantity' => 1,
                'reward_mode' => 'global',
                'reward_ratio' => 1,
                'reward_fixed' => 0,
                'reward_points' => 30,
                'recovered_points' => 0,
            ]);
            $refundId = $this->insertRefund($userId, $orderId, $orderItemId, $refundSn, '30.00');

            $service->rollbackRefundCompleted($this->findRefund($refundId));

            $account = Db::name('user_points')->where('user_id', $userId)->find();
            $this->assertSame(0, (int) $account['frozen_points']);
            $this->assertSame(0, (int) $account['balance_points']);
            $this->assertSame(15, (int) $account['debt_points']);
            $this->assertSame(30, (int) Db::name('order_points_reward')->where('id', $rewardId)->value('recovered_points'));
            $this->assertSame('recovered', (string) Db::name('order_points_reward')->where('id', $rewardId)->value('status'));
            $this->assertSame(1, (int) Db::name('user_points_log')->where('biz_type', UserPointsLog::BIZ_REFUND_DEBT)->where('biz_id', $refundSn)->count());
        } finally {
            Db::rollback();
        }
    }

    public function testRefundReturnsDeductedPointsProportionally(): void
    {
        $this->requireDbTables([
            'user_points',
            'user_points_log',
            'order',
            'refund_order',
            'order_points_deduction',
        ]);

        Db::startTrans();
        try {
            $service = $this->pointsService();
            $userId = $this->testUserId();
            $orderSn = $this->sn('PDR');
            $refundSn = $this->sn('RPD');
            $orderId = $this->insertOrder($userId, $orderSn, '100.00', '80.00', OrderStatus::COMPLETED, '20.00');
            Db::name('user_points')->insert([
                'user_id' => $userId,
                'balance_points' => 0,
                'frozen_points' => 0,
                'debt_points' => 0,
                'total_income_points' => 0,
                'total_expense_points' => 100,
            ]);
            Db::name('order_points_deduction')->insert([
                'order_id' => $orderId,
                'order_sn' => $orderSn,
                'user_id' => $userId,
                'used_points' => 100,
                'discount_amount' => '20.00',
                'returned_points' => 0,
                'status' => 'used',
            ]);
            $refundId = $this->insertRefund($userId, $orderId, null, $refundSn, '40.00');

            $service->rollbackRefundCompleted($this->findRefund($refundId));

            $this->assertSame(50, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'));
            $this->assertSame(50, (int) Db::name('order_points_deduction')->where('order_id', $orderId)->value('returned_points'));
            $this->assertSame('partial', (string) Db::name('order_points_deduction')->where('order_id', $orderId)->value('status'));
        } finally {
            Db::rollback();
        }
    }

    public function testPointsDisabledStopsNewRewardAndDeduction(): void
    {
        $this->requireDbTables([
            'setting',
            'user_points',
            'user_points_log',
            'points_rule',
            'goods',
            'goods_sku',
            'order',
            'order_item',
            'order_points_deduction',
            'order_points_reward',
            'order_points_reward_item',
        ]);

        Db::startTrans();
        try {
            $this->setPointsEnabled(false);
            $service = $this->pointsService();
            $this->resetOrderCompleteRule(2);

            $userId = $this->testUserId();
            Db::name('user_points')->insert([
                'user_id' => $userId,
                'balance_points' => 1000,
                'frozen_points' => 0,
                'debt_points' => 0,
                'total_income_points' => 1000,
                'total_expense_points' => 0,
            ]);

            $orderSn = $this->sn('PODIS');
            $goodsId = $this->insertGoods('global', 0, 0);
            $skuId = $this->insertSku($goodsId, 'inherit', 0, 0);
            $orderId = $this->insertOrder($userId, $orderSn, '60.00', '60.00', OrderStatus::COMPLETED);
            $this->insertOrderItem($orderId, $goodsId, $skuId, '20.00', 3, '60.00');

            $service->rewardOrderCompleted($this->findOrder($orderId));
            $this->assertSame(0, (int) Db::name('order_points_reward')->where('order_sn', $orderSn)->count());

            $quote = $service->deductionQuote($userId, '60.00', true);
            $this->assertFalse($quote['enabled']);
            $this->assertSame(0, $quote['usable_points']);
            $this->assertSame(0, $quote['used_points']);

            try {
                $service->deductForOrder($userId, $orderId, $orderSn, 100, '1.00');
                $this->fail('积分关闭后不应允许订单积分抵扣');
            } catch (\mall_base\exception\BusinessException $e) {
                $this->assertSame('积分抵扣未开启', $e->getMessage());
            }
        } finally {
            Db::rollback();
            $this->setPointsEnabled(true);
        }
    }

    public function testRewardRuleMatrixCoversCompletionReleaseAndRefundRecovery(): void
    {
        $this->requireDbTables([
            'setting',
            'user_points',
            'user_points_log',
            'points_rule',
            'goods',
            'goods_sku',
            'order',
            'order_item',
            'refund_order',
            'order_points_reward',
            'order_points_reward_item',
        ]);

        Db::startTrans();
        try {
            $this->setPointsEnabled(true);
            $service = $this->pointsService();
            $this->resetOrderCompleteRule(2);

            $scenarios = [
                ['global', 'global', 0, 0, 'inherit', 0, 0, 120],
                ['goods_ratio', 'ratio', 3, 0, 'fixed', 0, 9, 180],
                ['goods_fixed', 'fixed', 0, 7, 'fixed', 0, 9, 21],
                ['sku_fixed', 'sku', 0, 0, 'fixed', 0, 8, 24],
                ['disabled', 'disabled', 0, 0, 'fixed', 0, 8, 0],
            ];

            foreach ($scenarios as [$label, $goodsMode, $goodsRatio, $goodsFixed, $skuMode, $skuRatio, $skuFixed, $expected]) {
                $userId = $this->testUserId();
                $orderSn = $this->sn('PMX');
                $goodsId = $this->insertGoods($goodsMode, $goodsRatio, $goodsFixed);
                $skuId = $this->insertSku($goodsId, $skuMode, $skuRatio, $skuFixed);
                $orderId = $this->insertOrder($userId, $orderSn, '60.00', '60.00', OrderStatus::COMPLETED);
                $orderItemId = $this->insertOrderItem($orderId, $goodsId, $skuId, '20.00', 3, '60.00');

                $service->rewardOrderCompleted($this->findOrder($orderId));

                if ($expected <= 0) {
                    $this->assertSame(0, (int) Db::name('order_points_reward')->where('order_sn', $orderSn)->count(), $label);
                    continue;
                }

                $this->assertSame($expected, (int) Db::name('user_points')->where('user_id', $userId)->value('frozen_points'), $label);
                $this->assertSame($expected, (int) Db::name('order_points_reward')->where('order_sn', $orderSn)->value('reward_points'), $label);

                Db::name('order_points_reward')
                    ->where('order_sn', $orderSn)
                    ->update(['release_time' => date('Y-m-d H:i:s', time() - 60)]);
                $released = $service->releaseDueRewards(10);
                $this->assertGreaterThanOrEqual($expected, $released['released'], $label);
                $this->assertSame(0, (int) Db::name('user_points')->where('user_id', $userId)->value('frozen_points'), $label);
                $this->assertSame($expected, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'), $label);

                $refundSn = $this->sn('RMR');
                $refundId = $this->insertRefund($userId, $orderId, $orderItemId, $refundSn, '60.00');
                $service->rollbackRefundCompleted($this->findRefund($refundId));

                $this->assertSame(0, (int) Db::name('user_points')->where('user_id', $userId)->value('balance_points'), $label);
                $this->assertSame($expected, (int) Db::name('order_points_reward')->where('order_sn', $orderSn)->value('recovered_points'), $label);
                $this->assertSame('recovered', (string) Db::name('order_points_reward')->where('order_sn', $orderSn)->value('status'), $label);
            }
        } finally {
            Db::rollback();
            $this->setPointsEnabled(true);
        }
    }

    private function requireDbTables(array $tables): void
    {
        $this->bootApp();
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                $this->markTestSkipped("测试数据库未创建 {$this->tableName($table)}，跳过积分账本契约测试。");
            }
        }
    }

    private function bootApp(): void
    {
        if ($this->dbReady) {
            return;
        }

        try {
            $this->app = new App(dirname(__DIR__, 3));
            $this->app->initialize();
            Db::query('SELECT 1');
            $this->dbReady = true;
        } catch (Throwable $e) {
            $this->markTestSkipped('测试数据库不可用，跳过积分账本契约测试：' . $e->getMessage());
        }
    }

    private function tableExists(string $logicalName): bool
    {
        $table = $this->tableName($logicalName);
        $rows = Db::query(sprintf(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' LIMIT 1",
            str_replace("'", "''", $table)
        ));
        return $rows !== [];
    }

    private function tableName(string $logicalName): string
    {
        return (string) Db::name($logicalName)->getTable();
    }

    private function pointsService(): UserPointsAccountService
    {
        return $this->app?->make(UserPointsAccountService::class) ?? app()->make(UserPointsAccountService::class);
    }

    private function findOrder(int $orderId): Order
    {
        /** @var Order|null $order */
        $order = ($this->app?->make(Order::class) ?? app()->make(Order::class))
            ->where('id', $orderId)
            ->find();
        $this->assertInstanceOf(Order::class, $order);
        return $order;
    }

    private function findRefund(int $refundId): RefundOrder
    {
        /** @var RefundOrder|null $refund */
        $refund = ($this->app?->make(RefundOrder::class) ?? app()->make(RefundOrder::class))
            ->where('id', $refundId)
            ->find();
        $this->assertInstanceOf(RefundOrder::class, $refund);
        return $refund;
    }

    private function testUserId(): int
    {
        return random_int(100000000, 900000000);
    }

    private function sn(string $prefix): string
    {
        return $prefix . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function resetOrderCompleteRule(int $pointsPerYuan): void
    {
        Db::name('points_rule')->where('scene', 'order_complete')->delete();
        Db::name('points_rule')->insert([
            'scene' => 'order_complete',
            'name' => 'Test order reward',
            'description' => 'Test rule',
            'points_per_yuan' => $pointsPerYuan,
            'fixed_points' => 0,
            'max_points' => 0,
            'sort' => 1,
            'status' => 1,
            'remark' => null,
        ]);
    }

    private function setPointsEnabled(bool $enabled): void
    {
        Db::name('setting')->where('code', 'points_enabled')->update(['value' => $enabled ? '1' : '0']);
        $this->flushSettings();
    }

    private function flushSettings(): void
    {
        try {
            ($this->app?->make(SystemSettingService::class) ?? app()->make(SystemSettingService::class))->flush();
        } catch (Throwable) {
            // ignore cache cleanup failures in contract tests
        }
    }

    private function insertGoods(string $mode, int $ratio, int $fixed): int
    {
        return (int) Db::name('goods')->insertGetId([
            'category_id' => 1,
            'name' => 'CodexTest Points Goods',
            'spec_type' => 1,
            'price' => '20.00',
            'stock' => 10,
            'is_on_sale' => 1,
            'status' => 1,
            'points_reward_mode' => $mode,
            'points_reward_ratio' => $ratio,
            'points_reward_fixed' => $fixed,
        ]);
    }

    private function insertSku(int $goodsId, string $mode, int $ratio, int $fixed): int
    {
        return (int) Db::name('goods_sku')->insertGetId([
            'goods_id' => $goodsId,
            'spec_values' => '',
            'price' => '20.00',
            'stock' => 10,
            'points_reward_mode' => $mode,
            'points_reward_ratio' => $ratio,
            'points_reward_fixed' => $fixed,
            'status' => 1,
        ]);
    }

    private function insertOrder(
        int $userId,
        string $sn,
        string $totalAmount,
        string $payAmount,
        int $status,
        string $discountAmount = '0.00'
    ): int {
        return (int) Db::name('order')->insertGetId([
            'sn' => $sn,
            'user_id' => $userId,
            'status' => $status,
            'total_amount' => $totalAmount,
            'freight_amount' => '0.00',
            'discount_amount' => $discountAmount,
            'pay_amount' => $payAmount,
            'receiver_name' => 'Codex Test',
            'receiver_phone' => '13800000000',
            'receiver_province' => '',
            'receiver_city' => '',
            'receiver_district' => '',
            'receiver_address' => 'Test address',
            'completed_at' => $status === OrderStatus::COMPLETED ? date('Y-m-d H:i:s') : null,
        ]);
    }

    private function insertOrderItem(int $orderId, int $goodsId, int $skuId, string $unitPrice, int $quantity, string $payAmount): int
    {
        return (int) Db::name('order_item')->insertGetId([
            'order_id' => $orderId,
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'goods_name' => 'CodexTest Points Goods',
            'goods_image' => null,
            'sku_spec' => '',
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'subtotal' => bcmul($unitPrice, (string) $quantity, 2),
            'discount_amount' => '0.00',
            'pay_amount' => $payAmount,
        ]);
    }

    private function insertRefund(int $userId, int $orderId, ?int $orderItemId, string $sn, string $refundAmount): int
    {
        return (int) Db::name('refund_order')->insertGetId([
            'sn' => $sn,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'user_id' => $userId,
            'type' => RefundOrderStatus::TYPE_REFUND_ONLY,
            'receive_status' => RefundOrderStatus::RECEIVE_RECEIVED,
            'status' => RefundOrderStatus::COMPLETED,
            'quantity' => 1,
            'refund_amount' => $refundAmount,
            'reason' => 'TEST',
            'remark' => 'test',
            'refunded_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
