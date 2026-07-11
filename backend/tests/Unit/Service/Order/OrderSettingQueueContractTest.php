<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\cron\tasks\DistributionMaintenanceCron;
use app\cron\tasks\OrderMaintenanceCron;
use app\cron\tasks\PointsMaintenanceCron;
use app\job\AutoReceiveOrdersJob;
use app\job\CloseExpiredOrdersJob;
use app\job\ReleaseDistributionCommissionsJob;
use app\job\ReleaseFrozenPointsJob;
use app\model\setting\Setting;
use app\service\admin\order\OrderAdminService;
use app\service\distribution\DistributionOrderEventService;
use app\service\order\OrderSettingService;
use app\service\user\UserPointsAccountService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OrderSettingQueueContractTest extends TestCase
{
    public function testOrderSettingServiceKeepsDefaultContracts(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../app/service/order/OrderSettingService.php');
        $this->assertIsString($source);

        foreach ([
            'DEFAULT_PENDING_PAY_TIMEOUT_MINUTES = 30',
            'DEFAULT_AUTO_RECEIVE_DAYS = 7',
            'DEFAULT_AFTER_SALE_DAYS = 0',
            'order_pending_pay_timeout_minutes',
            'order_auto_receive_days',
            'refund_after_sale_days',
            'refund_return_receiver_name',
            'refund_return_receiver_phone',
            'refund_return_receiver_address',
            'refund_reason_options',
            'refund_reject_reason_options',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        $ref = new ReflectionClass(OrderSettingService::class);
        foreach ([
            'pendingPayTimeoutSeconds',
            'autoReceiveDays',
            'afterSaleDays',
            'refundReasonOptions',
            'refundRejectReasonOptions',
            'returnReceiver',
        ] as $method) {
            $this->assertTrue($ref->hasMethod($method), "缺少配置读取方法 {$method}");
        }
    }

    public function testMaintenanceJobsAndCronTasksAreWiredByDomain(): void
    {
        $this->assertTrue(class_exists(OrderMaintenanceCron::class));
        $this->assertTrue(class_exists(PointsMaintenanceCron::class));
        $this->assertTrue(class_exists(DistributionMaintenanceCron::class));
        $this->assertTrue(class_exists(CloseExpiredOrdersJob::class));
        $this->assertTrue(class_exists(AutoReceiveOrdersJob::class));
        $this->assertTrue(class_exists(ReleaseFrozenPointsJob::class));
        $this->assertTrue(class_exists(ReleaseDistributionCommissionsJob::class));
        $this->assertTrue(method_exists(OrderAdminService::class, 'closeExpired'));
        $this->assertTrue(method_exists(OrderAdminService::class, 'autoReceiveExpired'));
        $this->assertTrue(method_exists(UserPointsAccountService::class, 'releaseDueRewards'));
        $this->assertTrue(method_exists(DistributionOrderEventService::class, 'releaseDueCommissions'));

        $cronConfig = file_get_contents(__DIR__ . '/../../../../config/cron.php');
        $orderCronTask = file_get_contents(__DIR__ . '/../../../../app/cron/tasks/OrderMaintenanceCron.php');
        $pointsCronTask = file_get_contents(__DIR__ . '/../../../../app/cron/tasks/PointsMaintenanceCron.php');
        $distributionCronTask = file_get_contents(__DIR__ . '/../../../../app/cron/tasks/DistributionMaintenanceCron.php');

        $this->assertIsString($cronConfig);
        $this->assertIsString($orderCronTask);
        $this->assertIsString($pointsCronTask);
        $this->assertIsString($distributionCronTask);
        $this->assertStringContainsString('OrderMaintenanceCron::class', $cronConfig);
        $this->assertStringContainsString('PointsMaintenanceCron::class', $cronConfig);
        $this->assertStringContainsString('DistributionMaintenanceCron::class', $cronConfig);

        $this->assertStringContainsString('JobQueue::push(CloseExpiredOrdersJob::class', $orderCronTask);
        $this->assertStringContainsString('JobQueue::push(AutoReceiveOrdersJob::class', $orderCronTask);
        $this->assertStringNotContainsString('ReleaseFrozenPointsJob::class', $orderCronTask);
        $this->assertStringNotContainsString('ReleaseDistributionCommissionsJob::class', $orderCronTask);

        $this->assertStringContainsString('JobQueue::push(ReleaseFrozenPointsJob::class', $pointsCronTask);
        $this->assertStringNotContainsString('CloseExpiredOrdersJob::class', $pointsCronTask);
        $this->assertStringNotContainsString('ReleaseDistributionCommissionsJob::class', $pointsCronTask);

        $this->assertStringContainsString('JobQueue::push(ReleaseDistributionCommissionsJob::class', $distributionCronTask);
        $this->assertStringNotContainsString('CloseExpiredOrdersJob::class', $distributionCronTask);
        $this->assertStringNotContainsString('ReleaseFrozenPointsJob::class', $distributionCronTask);

        foreach ([$orderCronTask, $pointsCronTask, $distributionCronTask] as $cronTask) {
            $this->assertStringContainsString('runInSandbox', $cronTask);
            $this->assertStringContainsString('setnx', $cronTask);
        }
    }

    public function testReceiveFlowsCompleteOrdersForRewards(): void
    {
        $clientOrderService = (string) file_get_contents(__DIR__ . '/../../../../app/service/client/order/OrderService.php');
        $adminOrderService = (string) file_get_contents(__DIR__ . '/../../../../app/service/admin/order/OrderAdminService.php');

        $this->assertStringContainsString('toStatus: OrderStatus::RECEIVED', $clientOrderService);
        $this->assertStringContainsString('toStatus: OrderStatus::COMPLETED', $clientOrderService);
        $this->assertStringContainsString('确认收货后订单完成', $clientOrderService);

        $this->assertStringContainsString('toStatus: OrderStatus::RECEIVED', $adminOrderService);
        $this->assertStringContainsString('toStatus: OrderStatus::COMPLETED', $adminOrderService);
        $this->assertStringContainsString('自动确认收货后订单完成', $adminOrderService);
    }

    public function testInstallSeedContainsOrderAndRefundDefaults(): void
    {
        $schema = file_get_contents(__DIR__ . '/../../../../install/data/schema/03_mb_setting.sql');
        $this->assertIsString($schema);

        foreach ([
            'OrderConfig',
            'RefundConfig',
            "(106, 101, 0, '订单配置'",
            "(107, 101, 0, '售后配置'",
            "'order_pending_pay_timeout_minutes', '30'",
            "'order_auto_receive_days', '7'",
            "'refund_after_sale_days', '0'",
            'refund_return_receiver_name',
            'refund_return_receiver_phone',
            'refund_return_receiver_address',
            'MISTAKEN_ORDER',
            'QUALITY_ISSUE',
            'NO_LONGER_WANTED',
            'OTHER',
            "'refund_reason_options'",
            "'refund_reject_reason_options'",
            '常用驳回原因',
            "'option_list'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $schema);
        }
    }

    public function testOrderSettingServiceNormalizesConfiguredRefundReasons(): void
    {
        $service = new OrderSettingService();
        $method = (new ReflectionClass($service))->getMethod('normalizeRefundReasonOptions');
        $method->setAccessible(true);

        $options = $method->invoke($service, json_encode([
            ['value' => 'MISTAKEN_ORDER', 'label' => '订单拍错'],
            ['value' => '', 'label' => '尺码不合适'],
            ['value' => 'DUPLICATE', 'label' => '尺码不合适'],
            ['value' => 'EMPTY_LABEL', 'label' => ''],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame([
            ['value' => 'MISTAKEN_ORDER', 'label' => '订单拍错'],
            ['value' => '尺码不合适', 'label' => '尺码不合适'],
        ], $options);
    }

    public function testOptionListFormTypeIsAvailable(): void
    {
        $this->assertSame('option_list', Setting::TYPE_OPTION_LIST);
        $typeValues = array_column(Setting::getTypeOptions(), 'value');
        $this->assertContains('option_list', $typeValues);
    }
}
