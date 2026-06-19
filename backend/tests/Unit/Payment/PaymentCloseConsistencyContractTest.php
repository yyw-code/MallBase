<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use app\service\client\payment\WechatPayClient;
use app\service\order\WechatPrepayCloseService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PaymentCloseConsistencyContractTest extends TestCase
{
    public function testWechatCloseOrderClientIsAvailable(): void
    {
        $ref = new ReflectionClass(WechatPayClient::class);
        $this->assertTrue($ref->hasMethod('closeByOutTradeNo'));

        $source = file_get_contents(__DIR__ . '/../../../app/service/client/payment/WechatPayClient.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('/v3/pay/transactions/out-trade-no/', $source);
        $this->assertStringContainsString('/close', $source);
    }

    public function testPrepayCloseServiceAndCallSitesExist(): void
    {
        $this->assertTrue(class_exists(WechatPrepayCloseService::class));

        $paymentLogSource = file_get_contents(__DIR__ . '/../../../app/model/order/PaymentLog.php');
        $adminSource = file_get_contents(__DIR__ . '/../../../app/service/admin/order/OrderAdminService.php');
        $prepaySource = file_get_contents(__DIR__ . '/../../../app/service/client/payment/PrepayService.php');
        $notifySource = file_get_contents(__DIR__ . '/../../../app/service/client/payment/NotifyService.php');

        $this->assertIsString($paymentLogSource);
        $this->assertIsString($adminSource);
        $this->assertIsString($prepaySource);
        $this->assertIsString($notifySource);
        $this->assertStringContainsString("protected \$json = ['raw_notify'];", $paymentLogSource);
        $this->assertStringContainsString('protected $jsonAssoc = true;', $paymentLogSource);
        $this->assertStringContainsString('closeLogs($prepayLogs)', $adminSource);
        $this->assertStringContainsString('PaymentLog::EVENT_CLOSED', $adminSource);
        $this->assertStringContainsString('WechatPrepayCloseService', $prepaySource);
        $this->assertStringContainsString('amount_cents', $prepaySource);
        $this->assertStringContainsString('PaymentLog::EVENT_SUPERSEDED', $prepaySource);
        $this->assertStringContainsString('微信支付回调命中非活跃预支付流水', $notifySource);
        $this->assertStringContainsString("respond(500, 'FAIL'", $notifySource);
        $this->assertStringContainsString('仍继续幂等确认订单状态', $notifySource);
        $this->assertStringContainsString("'exception'     => get_class(\$e)", $notifySource);
        $this->assertStringContainsString("'refund_amount' => \$refundAmount", $notifySource);
    }

    public function testRecoverPaidCommandWritesPaidLogAuditRecord(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/command/OrderRecoverPaid.php');
        $this->assertIsString($source);

        $this->assertStringContainsString('persistPaidLog($prepay, $result, $transactionId, $tradeState)', $source);
        $this->assertStringContainsString('PaymentLog::EVENT_PAID', $source);
        $this->assertStringContainsString('$paidLog->raw_notify', $source);
        $this->assertStringContainsString('derivedPaidOutTradeNo', $source);
        $this->assertStringContainsString('isDuplicateKey', $source);
    }
}
