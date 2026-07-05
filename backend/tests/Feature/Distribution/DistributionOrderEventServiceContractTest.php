<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use app\model\distribution\DistributionOrderCommission;
use app\model\order\Order;
use app\model\order\RefundOrder;
use app\service\SystemSettingService;
use app\service\distribution\DistributionAccountService;
use app\service\distribution\DistributionOrderEventService;
use app\service\distribution\DistributionRelationService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;
use Throwable;

final class DistributionOrderEventServiceContractTest extends TestCase
{
    private ?App $app = null;
    private bool $dbReady = false;

    public function testOrderPaidFreezesTwoLevelCommissionAndIsIdempotent(): void
    {
        $this->requireDbTables($this->distributionTables());

        Db::startTrans();
        try {
            $this->resetDistributionConfig(7);
            [$grandparentId, $parentId, $buyerId] = $this->createUsers(3);
            $this->openDistributor($grandparentId);
            $this->openDistributor($parentId);
            $this->bindByInviteCode($parentId, $this->inviteCode($grandparentId));
            $this->bindByInviteCode($buyerId, $this->inviteCode($parentId));

            [$orderId, $orderSn] = $this->insertOrderWithItem($buyerId, '100.00');

            $service = $this->eventService();
            $service->handleOrderPaid($this->findOrder($orderId));
            $service->handleOrderPaid($this->findOrder($orderId));

            $this->assertSame(2, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->count());
            $this->assertSame(500, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->where('relation_level', 1)->value('amount_cents'));
            $this->assertSame(200, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->where('relation_level', 2)->value('amount_cents'));
            $this->assertSame(500, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('frozen_commission_cents'));
            $this->assertSame(200, (int) Db::name('distribution_distributor')->where('user_id', $grandparentId)->value('frozen_commission_cents'));
            $this->assertSame(1, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('order_count'));
            $this->assertSame(1, (int) Db::name('distribution_distributor')->where('user_id', $grandparentId)->value('order_count'));
        } finally {
            Db::rollback();
        }
    }

    public function testCompletedOrderSettlesImmediatelyAndRefundRecoversAvailableCommission(): void
    {
        $this->requireDbTables($this->distributionTables());

        Db::startTrans();
        try {
            $this->resetDistributionConfig(0);
            [$parentId, $buyerId] = $this->createUsers(2);
            $this->openDistributor($parentId);
            $this->bindByInviteCode($buyerId, $this->inviteCode($parentId));

            [$orderId, $orderSn, $orderItemId] = $this->insertOrderWithItem($buyerId, '100.00');
            $service = $this->eventService();
            $service->handleOrderPaid($this->findOrder($orderId));
            Db::name('order')->where('id', $orderId)->update([
                'status' => OrderStatus::COMPLETED,
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            $service->handleOrderCompleted($this->findOrder($orderId));

            $this->assertSame(0, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('frozen_commission_cents'));
            $this->assertSame(500, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('available_commission_cents'));
            $this->assertSame(DistributionOrderCommission::STATUS_SETTLED, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->value('status'));

            $refundId = $this->insertRefund($buyerId, $orderId, $orderItemId, '40.00');
            $service->handleRefundCompleted($this->findRefund($refundId));

            $this->assertSame(300, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('available_commission_cents'));
            $this->assertSame(200, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->value('recovered_cents'));
            $this->assertSame(DistributionOrderCommission::STATUS_SETTLED, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->value('status'));
        } finally {
            Db::rollback();
        }
    }

    public function testCompletedOrderCanWaitAndReleaseDueCommission(): void
    {
        $this->requireDbTables($this->distributionTables());

        Db::startTrans();
        try {
            $this->resetDistributionConfig(3);
            [$parentId, $buyerId] = $this->createUsers(2);
            $this->openDistributor($parentId);
            $this->bindByInviteCode($buyerId, $this->inviteCode($parentId));

            [$orderId, $orderSn] = $this->insertOrderWithItem($buyerId, '100.00');
            $service = $this->eventService();
            $service->handleOrderPaid($this->findOrder($orderId));
            Db::name('order')->where('id', $orderId)->update([
                'status' => OrderStatus::COMPLETED,
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            $service->handleOrderCompleted($this->findOrder($orderId));

            $commissionId = (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->value('id');
            $this->assertSame(DistributionOrderCommission::STATUS_PENDING_SETTLE, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->value('status'));
            $this->assertSame(500, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('frozen_commission_cents'));

            $earlyResult = $service->releaseDueCommissions(10);
            $this->assertSame(0, $earlyResult['released']);
            $this->assertSame(DistributionOrderCommission::STATUS_PENDING_SETTLE, (int) Db::name('distribution_order_commission')->where('id', $commissionId)->value('status'));
            $this->assertSame(500, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('frozen_commission_cents'));
            $this->assertSame(0, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('available_commission_cents'));

            Db::name('distribution_order_commission')
                ->where('id', $commissionId)
                ->update(['release_time' => date('Y-m-d H:i:s', time() - 60)]);

            $result = $service->releaseDueCommissions(10);
            $this->assertSame(1, $result['released']);
            $this->assertSame(0, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('frozen_commission_cents'));
            $this->assertSame(500, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('available_commission_cents'));
            $this->assertSame(DistributionOrderCommission::STATUS_SETTLED, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->value('status'));
        } finally {
            Db::rollback();
        }
    }

    public function testClosedPaidOrderCancelsFrozenCommission(): void
    {
        $this->requireDbTables($this->distributionTables());

        Db::startTrans();
        try {
            $this->resetDistributionConfig(7);
            [$parentId, $buyerId] = $this->createUsers(2);
            $this->openDistributor($parentId);
            $this->bindByInviteCode($buyerId, $this->inviteCode($parentId));

            [$orderId, $orderSn] = $this->insertOrderWithItem($buyerId, '100.00');
            $service = $this->eventService();
            $service->handleOrderPaid($this->findOrder($orderId));
            Db::name('order')->where('id', $orderId)->update([
                'status' => OrderStatus::CLOSED,
                'closed_at' => date('Y-m-d H:i:s'),
            ]);
            $service->handleOrderClosed($this->findOrder($orderId));

            $this->assertSame(0, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('frozen_commission_cents'));
            $this->assertSame(500, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->value('recovered_cents'));
            $this->assertSame(DistributionOrderCommission::STATUS_CANCELED, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->value('status'));
        } finally {
            Db::rollback();
        }
    }

    /**
     * @return array<int,string>
     */
    private function distributionTables(): array
    {
        return [
            'user',
            'goods',
            'goods_sku',
            'order',
            'order_item',
            'refund_order',
            'setting_group',
            'setting',
            'distribution_level',
            'distribution_distributor',
            'distribution_relation',
            'distribution_commission_rule',
            'distribution_order_commission',
            'distribution_commission_log',
        ];
    }

    /**
     * @param array<int,string> $tables
     */
    private function requireDbTables(array $tables): void
    {
        $this->bootApp();
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                $this->markTestSkipped("测试数据库未创建 {$this->tableName($table)}，跳过分销契约测试。");
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
            $this->markTestSkipped('测试数据库不可用，跳过分销契约测试：' . $e->getMessage());
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

    private function resetDistributionConfig(int $settlementDays): void
    {
        $groupId = $this->ensureDistributionConfigGroup();
        foreach ([
            ['name' => '启用分销功能', 'code' => 'distribution_enabled', 'value' => '1', 'type' => 'switch', 'sort' => 10],
            ['name' => '分销层级', 'code' => 'distribution_level_depth', 'value' => '2', 'type' => 'number', 'sort' => 20],
            ['name' => '自购返佣', 'code' => 'self_purchase_enabled', 'value' => '0', 'type' => 'switch', 'sort' => 30],
            ['name' => '结算等待天数', 'code' => 'settlement_days', 'value' => (string) $settlementDays, 'type' => 'number', 'sort' => 40],
            ['name' => '最低提现金额(分)', 'code' => 'min_withdraw_cents', 'value' => '10000', 'type' => 'number', 'sort' => 50],
            ['name' => '一级默认佣金比例(%)', 'code' => 'global_first_rate', 'value' => '5.00', 'type' => 'number', 'sort' => 60],
            ['name' => '二级默认佣金比例(%)', 'code' => 'global_second_rate', 'value' => '2.00', 'type' => 'number', 'sort' => 70],
        ] as $setting) {
            $existingId = Db::name('setting')
                ->where('group_id', $groupId)
                ->where('code', $setting['code'])
                ->value('id');
            if ($existingId) {
                Db::name('setting')->where('id', (int) $existingId)->update($setting);
                continue;
            }
            Db::name('setting')->insert(array_merge($setting, ['group_id' => $groupId]));
        }
        $this->flushSettings();

        Db::name('distribution_level')->where('id', 1)->delete();
        Db::name('distribution_level')->insert([
            'id' => 1,
            'name' => '默认分销员',
            'first_rate' => '5.00',
            'second_rate' => '2.00',
            'sort' => 10,
            'status' => 1,
            'remark' => '测试默认等级',
        ]);
        Db::name('distribution_commission_rule')->where('id', '>', 0)->delete();
    }

    private function ensureDistributionConfigGroup(): int
    {
        $groupId = (int) Db::name('setting_group')
            ->where('code', 'DistributionConfig')
            ->value('id');
        if ($groupId > 0) {
            return $groupId;
        }

        return (int) Db::name('setting_group')->insertGetId([
            'parent_id' => 0,
            'permission_id' => 0,
            'name' => '分销基础设置',
            'code' => 'DistributionConfig',
            'icon' => 'lucide:network',
            'description' => '分销总开关、结算、提现与默认佣金比例配置',
            'sort' => 30,
            'display_type' => 'page',
            'status' => 1,
            'permission_status' => 0,
        ]);
    }

    private function flushSettings(): void
    {
        try {
            ($this->app?->make(SystemSettingService::class) ?? app()->make(SystemSettingService::class))->flush();
        } catch (Throwable) {
            // ignore cache cleanup failures in contract tests
        }
    }

    /**
     * @return array<int,int>
     */
    private function createUsers(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $suffix = substr((string) random_int(100000000, 999999999), 0, 9);
            $ids[] = (int) Db::name('user')->insertGetId([
                'mobile' => '13' . $suffix,
                'nickname' => '分销测试用户' . $suffix,
                'status' => 1,
                'register_type' => 'h5',
            ]);
        }
        return $ids;
    }

    private function openDistributor(int $userId): void
    {
        $this->accountService()->openDistributor($userId, 1, 1, 'contract test');
    }

    private function inviteCode(int $userId): string
    {
        return (string) Db::name('distribution_distributor')->where('user_id', $userId)->value('invite_code');
    }

    private function bindByInviteCode(int $userId, string $inviteCode): void
    {
        $this->relationService()->bindByInviteCode($userId, $inviteCode, 'contract_test');
    }

    /**
     * @return array{0:int,1:string,2:int}
     */
    private function insertOrderWithItem(int $buyerId, string $payAmount): array
    {
        $goodsId = (int) Db::name('goods')->insertGetId([
            'category_id' => 1,
            'name' => 'CodexTest Distribution Goods',
            'spec_type' => 1,
            'price' => $payAmount,
            'stock' => 10,
            'is_on_sale' => 1,
            'status' => 1,
        ]);
        $skuId = (int) Db::name('goods_sku')->insertGetId([
            'goods_id' => $goodsId,
            'spec_values' => '',
            'price' => $payAmount,
            'stock' => 10,
            'status' => 1,
        ]);
        $orderSn = $this->sn('DOD');
        $orderId = (int) Db::name('order')->insertGetId([
            'sn' => $orderSn,
            'user_id' => $buyerId,
            'status' => OrderStatus::PAID,
            'total_amount' => $payAmount,
            'freight_amount' => '0.00',
            'discount_amount' => '0.00',
            'pay_amount' => $payAmount,
            'receiver_name' => 'Codex Test',
            'receiver_phone' => '13800000000',
            'receiver_province' => '',
            'receiver_city' => '',
            'receiver_district' => '',
            'receiver_address' => 'Test address',
            'paid_at' => date('Y-m-d H:i:s'),
        ]);
        $orderItemId = (int) Db::name('order_item')->insertGetId([
            'order_id' => $orderId,
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'goods_name' => 'CodexTest Distribution Goods',
            'goods_image' => null,
            'sku_spec' => '',
            'unit_price' => $payAmount,
            'quantity' => 1,
            'subtotal' => $payAmount,
            'discount_amount' => '0.00',
            'pay_amount' => $payAmount,
        ]);

        return [$orderId, $orderSn, $orderItemId];
    }

    private function insertRefund(int $userId, int $orderId, int $orderItemId, string $refundAmount): int
    {
        return (int) Db::name('refund_order')->insertGetId([
            'sn' => $this->sn('DRF'),
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

    private function accountService(): DistributionAccountService
    {
        return $this->app?->make(DistributionAccountService::class) ?? app()->make(DistributionAccountService::class);
    }

    private function relationService(): DistributionRelationService
    {
        return $this->app?->make(DistributionRelationService::class) ?? app()->make(DistributionRelationService::class);
    }

    private function eventService(): DistributionOrderEventService
    {
        return $this->app?->make(DistributionOrderEventService::class) ?? app()->make(DistributionOrderEventService::class);
    }

    private function sn(string $prefix): string
    {
        return $prefix . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
