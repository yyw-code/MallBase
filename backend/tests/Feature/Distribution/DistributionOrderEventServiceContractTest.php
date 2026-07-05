<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use app\model\distribution\DistributionApply;
use app\model\distribution\DistributionCommissionLog;
use app\model\distribution\DistributionCommissionRule;
use app\model\distribution\DistributionOrderCommission;
use app\model\order\Order;
use app\model\order\RefundOrder;
use app\service\SystemSettingService;
use app\service\distribution\DistributionAccountService;
use app\service\distribution\DistributionConfigService;
use app\service\distribution\DistributionEnrollmentService;
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

    public function testOrderPaidFreezesFirstLevelCommissionByDefaultAndIsIdempotent(): void
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

            $this->assertSame(1, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->count());
            $this->assertSame(500, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->where('relation_level', 1)->value('amount_cents'));
            $this->assertSame(500, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('frozen_commission_cents'));
            $this->assertSame(0, (int) Db::name('distribution_distributor')->where('user_id', $grandparentId)->value('frozen_commission_cents'));
            $this->assertSame(1, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('order_count'));
            $this->assertSame(0, (int) Db::name('distribution_distributor')->where('user_id', $grandparentId)->value('order_count'));
        } finally {
            Db::rollback();
        }
    }

    public function testOrderPaidFreezesSecondLevelCommissionOnlyWhenConfigured(): void
    {
        $this->requireDbTables($this->distributionTables());

        Db::startTrans();
        try {
            $this->resetDistributionConfig(7, true, '2.00');
            [$grandparentId, $parentId, $buyerId] = $this->createUsers(3);
            $this->openDistributor($grandparentId);
            $this->openDistributor($parentId);
            $this->bindByInviteCode($parentId, $this->inviteCode($grandparentId));
            $this->bindByInviteCode($buyerId, $this->inviteCode($parentId));

            [$orderId, $orderSn] = $this->insertOrderWithItem($buyerId, '100.00');

            $service = $this->eventService();
            $service->handleOrderPaid($this->findOrder($orderId));

            $this->assertSame(2, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->count());
            $this->assertSame(500, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->where('relation_level', 1)->value('amount_cents'));
            $this->assertSame(200, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->where('relation_level', 2)->value('amount_cents'));
            $this->assertSame(500, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('frozen_commission_cents'));
            $this->assertSame(200, (int) Db::name('distribution_distributor')->where('user_id', $grandparentId)->value('frozen_commission_cents'));
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

    public function testExpiredRelationDoesNotGenerateCommission(): void
    {
        $this->requireDbTables($this->distributionTables());

        Db::startTrans();
        try {
            $this->resetDistributionConfig(7, false, '0.00', ['relation_valid_days' => '1']);
            [$parentId, $buyerId] = $this->createUsers(2);
            $this->openDistributor($parentId);
            $this->bindByInviteCode($buyerId, $this->inviteCode($parentId));
            Db::name('distribution_relation')
                ->where('user_id', $buyerId)
                ->update(['expire_time' => date('Y-m-d H:i:s', time() - 60)]);

            [$orderId, $orderSn] = $this->insertOrderWithItem($buyerId, '100.00');
            $this->eventService()->handleOrderPaid($this->findOrder($orderId));

            $this->assertSame(0, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->count());
            $this->assertSame(0, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('frozen_commission_cents'));
        } finally {
            Db::rollback();
        }
    }

    public function testFixedCommissionRuleUsesConfiguredAmount(): void
    {
        $this->requireDbTables($this->distributionTables());

        Db::startTrans();
        try {
            $this->resetDistributionConfig(7);
            [$parentId, $buyerId] = $this->createUsers(2);
            $this->openDistributor($parentId);
            $this->bindByInviteCode($buyerId, $this->inviteCode($parentId));

            $orderData = $this->insertOrderWithItem($buyerId, '100.00');
            $orderId = $orderData[0];
            $orderSn = $orderData[1];
            $goodsId = $orderData[3];
            Db::name('distribution_commission_rule')->insert([
                'target_type' => DistributionCommissionRule::TARGET_GOODS,
                'target_id' => $goodsId,
                'name' => '固定金额测试',
                'commission_type' => DistributionCommissionRule::COMMISSION_TYPE_FIXED,
                'first_rate' => '0.00',
                'second_rate' => '0.00',
                'first_fixed_cents' => 1234,
                'second_fixed_cents' => 0,
                'status' => 1,
            ]);

            $this->eventService()->handleOrderPaid($this->findOrder($orderId));

            $this->assertSame(1234, (int) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->value('amount_cents'));
            $this->assertSame('0.00', (string) Db::name('distribution_order_commission')->where('order_sn', $orderSn)->value('rate'));
            $this->assertSame(1234, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('frozen_commission_cents'));
        } finally {
            Db::rollback();
        }
    }

    public function testInviteRewardIsGrantedOnFirstPaidOrderOnlyOnce(): void
    {
        $this->requireDbTables($this->distributionTables());

        Db::startTrans();
        try {
            $this->resetDistributionConfig(7, false, '0.00', [
                'invite_reward_enabled' => '1',
                'invite_reward_trigger' => DistributionConfigService::INVITE_REWARD_TRIGGER_FIRST_ORDER,
                'invite_reward_amount_cents' => '888',
            ]);
            [$parentId, $buyerId] = $this->createUsers(2);
            $this->openDistributor($parentId);
            $this->bindByInviteCode($buyerId, $this->inviteCode($parentId));
            $this->assertSame(0, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('available_commission_cents'));

            [$orderId] = $this->insertOrderWithItem($buyerId, '100.00');
            $service = $this->eventService();
            $service->handleOrderPaid($this->findOrder($orderId));
            $service->handleOrderPaid($this->findOrder($orderId));

            $this->assertSame(888, (int) Db::name('distribution_distributor')->where('user_id', $parentId)->value('available_commission_cents'));
            $this->assertSame(1, (int) Db::name('distribution_relation')->where('user_id', $buyerId)->value('invite_reward_status'));
            $this->assertSame(1, (int) Db::name('distribution_commission_log')->where('user_id', $parentId)->where('biz_type', DistributionCommissionLog::BIZ_INVITE_REWARD)->count());
        } finally {
            Db::rollback();
        }
    }

    public function testAmountModeOpensDistributorAfterPaidThreshold(): void
    {
        $this->requireDbTables($this->distributionTables());

        Db::startTrans();
        try {
            $this->resetDistributionConfig(7, false, '0.00', [
                'distributor_open_mode' => DistributionConfigService::OPEN_MODE_AMOUNT,
                'amount_open_threshold_cents' => '5000',
            ]);
            [$buyerId] = $this->createUsers(1);
            [$orderId] = $this->insertOrderWithItem($buyerId, '60.00');

            $this->eventService()->handleOrderPaid($this->findOrder($orderId));

            $this->assertSame(1, (int) Db::name('distribution_distributor')->where('user_id', $buyerId)->count());
            $this->assertSame('amount', (string) Db::name('distribution_distributor')->where('user_id', $buyerId)->value('open_source'));
        } finally {
            Db::rollback();
        }
    }

    public function testApplyModeCanApproveDistributorApplication(): void
    {
        $this->requireDbTables($this->distributionTables());

        Db::startTrans();
        try {
            $this->resetDistributionConfig(7, false, '0.00', [
                'distributor_open_mode' => DistributionConfigService::OPEN_MODE_APPLY,
            ]);
            [$userId] = $this->createUsers(1);

            $applyId = $this->enrollmentService()->apply($userId, '张三', '13800000000', '希望推广商品');
            $this->enrollmentService()->approveApply($applyId, 1, 1, '通过');

            $this->assertSame(DistributionApply::STATUS_APPROVED, (int) Db::name('distribution_apply')->where('id', $applyId)->value('status'));
            $this->assertSame(1, (int) Db::name('distribution_distributor')->where('user_id', $userId)->count());
            $this->assertSame('apply', (string) Db::name('distribution_distributor')->where('user_id', $userId)->value('open_source'));
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
            'distribution_apply',
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
        foreach ($this->requiredDistributionColumns() as $table => $columns) {
            foreach ($columns as $column) {
                if (!$this->columnExists($table, $column)) {
                    $this->markTestSkipped("测试数据库未创建 {$this->tableName($table)}.{$column}，跳过分销契约测试。");
                }
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

    private function columnExists(string $logicalName, string $column): bool
    {
        $table = $this->tableName($logicalName);
        $rows = Db::query(sprintf(
            "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = '%s' LIMIT 1",
            str_replace("'", "''", $table),
            str_replace("'", "''", $column)
        ));
        return $rows !== [];
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function requiredDistributionColumns(): array
    {
        return [
            'distribution_distributor' => ['open_source'],
            'distribution_relation' => ['expire_time', 'attribution_scene', 'attribution_target_type', 'attribution_target_id', 'invite_reward_status', 'invite_reward_cents', 'invite_reward_at'],
            'distribution_commission_rule' => ['commission_type', 'first_fixed_cents', 'second_fixed_cents'],
            'distribution_order_commission' => ['relation_id', 'attribution_scene', 'attribution_target_type', 'attribution_target_id'],
        ];
    }

    /**
     * @param array<string,string> $overrides
     */
    private function resetDistributionConfig(int $settlementDays, bool $secondLevelEnabled = false, string $secondRate = '0.00', array $overrides = []): void
    {
        $groupId = $this->ensureDistributionConfigGroup();
        $settings = [
            ['name' => '启用分销功能', 'code' => 'distribution_enabled', 'value' => '1', 'type' => 'switch', 'sort' => 10],
            ['name' => '分销员开通方式', 'code' => 'distributor_open_mode', 'value' => DistributionConfigService::OPEN_MODE_MANUAL, 'type' => 'select', 'sort' => 15],
            ['name' => '自动开通等级ID', 'code' => 'auto_open_level_id', 'value' => '1', 'type' => 'number', 'sort' => 18],
            ['name' => '启用二级分佣', 'code' => 'second_level_enabled', 'value' => $secondLevelEnabled ? '1' : '0', 'type' => 'switch', 'sort' => 20],
            ['name' => '自购返佣', 'code' => 'self_purchase_enabled', 'value' => '0', 'type' => 'switch', 'sort' => 30],
            ['name' => '绑定关系有效期天数', 'code' => 'relation_valid_days', 'value' => '0', 'type' => 'number', 'sort' => 35],
            ['name' => '结算等待天数', 'code' => 'settlement_days', 'value' => (string) $settlementDays, 'type' => 'number', 'sort' => 40],
            ['name' => '最低提现金额(分)', 'code' => 'min_withdraw_cents', 'value' => '10000', 'type' => 'number', 'sort' => 50],
            ['name' => '一级默认佣金比例(%)', 'code' => 'global_first_rate', 'value' => '5.00', 'type' => 'number', 'sort' => 60],
            ['name' => '二级默认佣金比例(%)', 'code' => 'global_second_rate', 'value' => $secondRate, 'type' => 'number', 'sort' => 70],
            ['name' => '满额开通门槛(分)', 'code' => 'amount_open_threshold_cents', 'value' => '0', 'type' => 'number', 'sort' => 80],
            ['name' => '启用固定邀请奖励', 'code' => 'invite_reward_enabled', 'value' => '0', 'type' => 'switch', 'sort' => 90],
            ['name' => '固定邀请奖励触发', 'code' => 'invite_reward_trigger', 'value' => DistributionConfigService::INVITE_REWARD_TRIGGER_FIRST_ORDER, 'type' => 'select', 'sort' => 100],
            ['name' => '固定邀请奖励金额(分)', 'code' => 'invite_reward_amount_cents', 'value' => '0', 'type' => 'number', 'sort' => 110],
            ['name' => '启用分享归因', 'code' => 'attribution_enabled', 'value' => '1', 'type' => 'switch', 'sort' => 120],
        ];
        foreach ($settings as $setting) {
            if (array_key_exists($setting['code'], $overrides)) {
                $setting['value'] = $overrides[$setting['code']];
            }
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
            'second_rate' => $secondRate,
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
     * @return array{0:int,1:string,2:int,3:int,4:int}
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

        return [$orderId, $orderSn, $orderItemId, $goodsId, $skuId];
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

    private function enrollmentService(): DistributionEnrollmentService
    {
        return $this->app?->make(DistributionEnrollmentService::class) ?? app()->make(DistributionEnrollmentService::class);
    }

    private function sn(string $prefix): string
    {
        return $prefix . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
