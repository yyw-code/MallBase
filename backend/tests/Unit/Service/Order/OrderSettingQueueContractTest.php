<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Order;

use app\job\AutoReceiveOrdersJob;
use app\job\CloseExpiredOrdersJob;
use app\model\setting\Setting;
use app\service\admin\order\OrderAdminService;
use app\service\order\OrderSettingService;
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

    public function testOrderMaintenanceJobsAndCronAreWired(): void
    {
        $this->assertTrue(class_exists(CloseExpiredOrdersJob::class));
        $this->assertTrue(class_exists(AutoReceiveOrdersJob::class));
        $this->assertTrue(method_exists(OrderAdminService::class, 'closeExpired'));
        $this->assertTrue(method_exists(OrderAdminService::class, 'autoReceiveExpired'));

        $cronConfig = file_get_contents(__DIR__ . '/../../../../config/cron.php');
        $cronTask = file_get_contents(__DIR__ . '/../../../../app/cron/tasks/OrderMaintenanceCron.php');

        $this->assertIsString($cronConfig);
        $this->assertIsString($cronTask);
        $this->assertStringContainsString('OrderMaintenanceCron::class', $cronConfig);
        $this->assertStringContainsString('Queue::push(CloseExpiredOrdersJob::class', $cronTask);
        $this->assertStringContainsString('Queue::push(AutoReceiveOrdersJob::class', $cronTask);
        $this->assertStringContainsString('setnx', $cronTask);
    }

    public function testInstallSeedContainsOrderAndRefundDefaults(): void
    {
        $schema = file_get_contents(__DIR__ . '/../../../../../backend/install/data/schema/03_mb_setting.sql');
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
