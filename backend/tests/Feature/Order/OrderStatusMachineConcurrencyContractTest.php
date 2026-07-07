<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use app\common\enum\OperatorType;
use app\common\enum\OrderStatus;
use app\model\order\Order;
use app\service\order\OrderStatusMachine;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;
use Throwable;

final class OrderStatusMachineConcurrencyContractTest extends TestCase
{
    private ?App $app = null;
    private bool $dbReady = false;

    public function testTransitRejectsStaleOrderWhenLatestStatusAlreadyChanged(): void
    {
        $this->requireDbTables(['order', 'order_log']);

        Db::startTrans();
        try {
            $orderId = $this->insertOrder(OrderStatus::SHIPPED);
            /** @var Order $staleOrder */
            $staleOrder = Order::where('id', $orderId)->find();

            Db::name('order')->where('id', $orderId)->update([
                'status' => OrderStatus::COMPLETED,
                'completed_at' => date('Y-m-d H:i:s'),
            ]);

            try {
                $this->machine()->transit(
                    $staleOrder,
                    OrderStatus::RECEIVED,
                    OperatorType::BUYER,
                    1,
                    'stale confirm receive',
                );
                $this->fail('预期旧订单对象不能覆盖数据库最新状态');
            } catch (BusinessException $e) {
                $this->assertStringContainsString('已完成', $e->getMessage());
                $this->assertStringContainsString('已收货', $e->getMessage());
            }

            $this->assertSame(OrderStatus::COMPLETED, (int) Db::name('order')->where('id', $orderId)->value('status'));
            $this->assertSame(0, (int) Db::name('order_log')->where('order_id', $orderId)->count());
        } finally {
            Db::rollback();
        }
    }

    public function testTransitNoopsWhenStaleOrderTargetsAlreadyReachedLatestStatus(): void
    {
        $this->requireDbTables(['order', 'order_log']);

        Db::startTrans();
        try {
            $orderId = $this->insertOrder(OrderStatus::PENDING_PAY);
            /** @var Order $staleOrder */
            $staleOrder = Order::where('id', $orderId)->find();

            Db::name('order')->where('id', $orderId)->update([
                'status' => OrderStatus::PAID,
                'paid_at' => date('Y-m-d H:i:s'),
            ]);

            $this->machine()->transit(
                $staleOrder,
                OrderStatus::PAID,
                OperatorType::SYSTEM,
                null,
                'duplicate pay notify',
            );

            $this->assertSame(OrderStatus::PAID, (int) Db::name('order')->where('id', $orderId)->value('status'));
            $this->assertSame(0, (int) Db::name('order_log')->where('order_id', $orderId)->count());
        } finally {
            Db::rollback();
        }
    }

    /**
     * @param array<int, string> $tables
     */
    private function requireDbTables(array $tables): void
    {
        $this->bootApp();
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                $this->markTestSkipped("测试数据库未创建 {$this->tableName($table)}，跳过订单状态机并发契约测试。");
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
            $this->markTestSkipped('测试数据库不可用，跳过订单状态机并发契约测试：' . $e->getMessage());
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

    private function machine(): OrderStatusMachine
    {
        return $this->app?->make(OrderStatusMachine::class) ?? app()->make(OrderStatusMachine::class);
    }

    private function insertOrder(int $status): int
    {
        return (int) Db::name('order')->insertGetId([
            'sn' => 'CC' . date('ymdHis') . random_int(100000, 999999),
            'user_id' => random_int(600000000, 900000000),
            'status' => $status,
            'total_amount' => '10.00',
            'freight_amount' => '0.00',
            'discount_amount' => '0.00',
            'pay_amount' => '10.00',
            'receiver_name' => 'Codex Concurrency',
            'receiver_phone' => '13800000000',
            'receiver_province' => '',
            'receiver_city' => '',
            'receiver_district' => '',
            'receiver_address' => 'OrderStatusMachineConcurrencyContractTest',
        ]);
    }
}
