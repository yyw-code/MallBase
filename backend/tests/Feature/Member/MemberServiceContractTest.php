<?php

declare(strict_types=1);

namespace Tests\Feature\Member;

use app\common\enum\OrderStatus;
use app\model\order\Order;
use app\service\SystemSettingService;
use app\service\user\UserMemberService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;
use Throwable;

final class MemberServiceContractTest extends TestCase
{
    private ?App $app = null;
    private bool $dbReady = false;

    public function testPricingQuoteUsesLevelDiscountAndSkuMemberPrice(): void
    {
        $this->requireDbTables([
            'setting',
            'member_level',
            'user_member',
        ]);

        Db::startTrans();
        try {
            $this->enableMemberSettings();
            $userId = $this->testUserId();
            $levelId = $this->insertLevel('黄金会员', 1000, '90.00');
            Db::name('user_member')->insert([
                'user_id' => $userId,
                'growth_value' => 1000,
                'total_growth_value' => 1000,
                'level_id' => $levelId,
                'level_name' => '黄金会员',
            ]);

            $quote = $this->memberService()->pricingQuote($userId, [
                [
                    'unit_price' => '100.00',
                    'quantity' => 1,
                    'member_benefit_mode' => 'level_discount',
                    'member_price' => null,
                ],
                [
                    'unit_price' => '80.00',
                    'quantity' => 2,
                    'member_benefit_mode' => 'sku_price',
                    'member_price' => '50.00',
                ],
            ]);

            $this->assertTrue($quote['enabled']);
            $this->assertSame('70.00', $quote['discount_amount']);
            $this->assertSame(['10.00', '60.00'], $quote['item_discounts']);
            $this->assertSame('黄金会员', $quote['level']['name'] ?? '');
        } finally {
            Db::rollback();
        }
    }

    public function testOrderCompletedAddsGrowthOnceAndKeepsBestLevel(): void
    {
        $this->requireDbTables([
            'setting',
            'member_level',
            'user_member',
            'user_member_growth_log',
            'order',
        ]);

        Db::startTrans();
        try {
            $this->enableMemberSettings();
            $userId = $this->testUserId();
            $orderSn = $this->sn('MGR');
            $this->insertLevel('黄金会员', 100, '90.00');
            $orderId = $this->insertOrder($userId, $orderSn, '120.00', '100.00', '20.00');

            $service = $this->memberService();
            $service->rewardOrderCompleted($this->findOrder($orderId));
            $service->rewardOrderCompleted($this->findOrder($orderId));

            $member = Db::name('user_member')->where('user_id', $userId)->find();
            $this->assertSame(100, (int) $member['growth_value']);
            $this->assertSame(100, (int) $member['total_growth_value']);
            $this->assertSame('黄金会员', (string) $member['level_name']);
            $this->assertSame(1, (int) Db::name('user_member_growth_log')
                ->where('biz_type', 'order_complete')
                ->where('biz_id', $orderSn)
                ->count());
        } finally {
            Db::rollback();
        }
    }

    public function testExistingLevelIsNotDowngradedWhenRuleThresholdChanges(): void
    {
        $this->requireDbTables([
            'setting',
            'member_level',
            'user_member',
        ]);

        Db::startTrans();
        try {
            $this->enableMemberSettings();
            $userId = $this->testUserId();
            $silverId = $this->insertLevel('白银会员', 0, '99.00');
            $goldId = $this->insertLevel('黄金会员', 100, '90.00');
            Db::name('user_member')->insert([
                'user_id' => $userId,
                'growth_value' => 100,
                'total_growth_value' => 100,
                'level_id' => $goldId,
                'level_name' => '黄金会员',
                'level_source' => 'auto',
            ]);

            Db::name('member_level')->where('id', $goldId)->update(['growth_min' => 10000]);

            $quote = $this->memberService()->pricingQuote($userId, [[
                'unit_price' => '100.00',
                'quantity' => 1,
                'member_benefit_mode' => 'level_discount',
                'member_price' => null,
            ]]);

            $this->assertSame($goldId, (int) ($quote['level']['id'] ?? 0));
            $this->assertSame('10.00', $quote['discount_amount']);
            $this->assertNotSame($silverId, (int) ($quote['level']['id'] ?? 0));
        } finally {
            Db::rollback();
        }
    }

    public function testManualLockedLevelIsNotAutoUpgradedByGrowth(): void
    {
        $this->requireDbTables([
            'setting',
            'member_level',
            'user_member',
            'user_member_growth_log',
            'order',
        ]);

        Db::startTrans();
        try {
            $this->enableMemberSettings();
            $userId = $this->testUserId();
            $silverId = $this->insertLevel('白银会员', 0, '99.00');
            $goldId = $this->insertLevel('黄金会员', 100, '90.00');
            Db::name('user_member')->insert([
                'user_id' => $userId,
                'growth_value' => 0,
                'total_growth_value' => 0,
                'level_id' => $silverId,
                'level_name' => '白银会员',
                'level_source' => 'manual',
                'level_remark' => '客服保留等级',
            ]);
            $orderId = $this->insertOrder($userId, $this->sn('MLOCK'), '200.00', '200.00', '0.00');

            $this->memberService()->rewardOrderCompleted($this->findOrder($orderId));

            $member = Db::name('user_member')->where('user_id', $userId)->find();
            $this->assertSame($silverId, (int) $member['level_id']);
            $this->assertSame(200, (int) $member['growth_value']);
            $this->assertSame('manual', (string) $member['level_source']);
            $this->assertNotSame($goldId, (int) $member['level_id']);
        } finally {
            Db::rollback();
        }
    }

    public function testAdminSetLevelPersistsManualLockAndAuditLog(): void
    {
        $this->requireDbTables([
            'setting',
            'user',
            'member_level',
            'user_member',
            'user_member_growth_log',
        ]);

        Db::startTrans();
        try {
            $this->enableMemberSettings();
            $userId = $this->insertUser();
            $levelId = $this->insertLevel('黄金会员', 100, '90.00');

            $result = $this->memberService()->adminSetLevel(
                userId: $userId,
                levelId: $levelId,
                locked: true,
                lockUntil: null,
                remark: '客服补偿',
                adminId: 1,
            );

            $this->assertSame($levelId, $result['level_id']);
            $this->assertSame('manual', $result['level_source']);
            $this->assertNull($result['level_lock_until']);

            $log = Db::name('user_member_growth_log')
                ->where('user_id', $userId)
                ->where('biz_type', 'admin_adjust')
                ->find();
            $this->assertNotEmpty($log);
            $this->assertSame($levelId, (int) $log['after_level_id']);
            $this->assertStringContainsString('客服补偿', (string) $log['remark']);
        } finally {
            Db::rollback();
        }
    }

    private function requireDbTables(array $tables): void
    {
        $this->bootApp();
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                $this->markTestSkipped("测试数据库未创建 {$this->tableName($table)}，跳过会员契约测试。");
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
            $this->markTestSkipped('测试数据库不可用，跳过会员契约测试：' . $e->getMessage());
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

    private function enableMemberSettings(): void
    {
        foreach ([
            'member_enabled' => '1',
            'member_growth_points_per_yuan' => '1',
        ] as $code => $value) {
            Db::name('setting')->where('code', $code)->update(['value' => $value]);
        }

        ($this->app?->make(SystemSettingService::class) ?? app()->make(SystemSettingService::class))->flush();
    }

    private function memberService(): UserMemberService
    {
        return $this->app?->make(UserMemberService::class) ?? app()->make(UserMemberService::class);
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

    private function insertLevel(string $name, int $growthMin, string $discountPercent): int
    {
        return (int) Db::name('member_level')->insertGetId([
            'name' => $name,
            'growth_min' => $growthMin,
            'discount_percent' => $discountPercent,
            'sort' => 1,
            'status' => 1,
            'remark' => 'test',
        ]);
    }

    private function insertOrder(int $userId, string $sn, string $totalAmount, string $payAmount, string $discountAmount): int
    {
        return (int) Db::name('order')->insertGetId([
            'sn' => $sn,
            'user_id' => $userId,
            'status' => OrderStatus::COMPLETED,
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
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function insertUser(): int
    {
        return (int) Db::name('user')->insertGetId([
            'mobile' => '139' . random_int(10000000, 99999999),
            'nickname' => '会员测试用户',
            'password' => password_hash('123456', PASSWORD_DEFAULT),
            'status' => 1,
            'register_type' => 'mobile',
        ]);
    }

    private function testUserId(): int
    {
        return random_int(100000000, 900000000);
    }

    private function sn(string $prefix): string
    {
        return $prefix . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
