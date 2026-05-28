<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;

/**
 * 烟雾测试：所有支付相关类应可被自动加载，无未声明依赖
 *
 * 这一测试比看上去更有价值：
 *  - 抓住 use 子句拼错 / namespace 不一致
 *  - 抓住缺失的 interface 引用
 *  - 抓住 EasyWeChat 6.x 类路径变动（SDK 升级时该测试会先红）
 *
 * 不需要 TP 框架 bootstrap，纯 composer autoload。
 */
final class PaymentClassesAutoloadTest extends TestCase
{
    /**
     * @dataProvider provideClassesUnderTest
     */
    public function testClassExists(string $className): void
    {
        $this->assertTrue(
            class_exists($className) || interface_exists($className),
            sprintf('Class or interface %s should be autoloadable', $className)
        );
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideClassesUnderTest(): iterable
    {
        $payment = [
            \app\common\enum\PayScene::class,
            \app\model\order\PaymentLog::class,
            \app\service\order\PaymentAdapter::class,
            \app\service\order\PrepayAdapter::class,
            \app\service\order\dto\RefundPaymentContext::class,
            \app\service\client\payment\dto\PrepayContext::class,
            \app\service\client\payment\dto\PrepayResult::class,
            \app\service\client\payment\WechatPayFactory::class,
            \app\service\client\payment\WechatPayClient::class,
            \app\service\client\payment\PrepayService::class,
            \app\service\client\payment\NotifyService::class,
            \app\service\client\payment\adapter\WechatJsapiAdapter::class,
            \app\service\client\payment\adapter\WechatH5Adapter::class,
            \app\service\order\WechatRefundAdapter::class,
            \app\listener\payment\PaymentAlertListener::class,
            \app\controller\client\order\PayNotifyController::class,
        ];

        foreach ($payment as $class) {
            yield $class => [$class];
        }
    }

    public function testPrepayAdapterIsInterface(): void
    {
        $this->assertTrue(interface_exists(\app\service\order\PrepayAdapter::class));
    }

    public function testJsapiAdapterImplementsPrepayAdapter(): void
    {
        $this->assertContains(
            \app\service\order\PrepayAdapter::class,
            class_implements(\app\service\client\payment\adapter\WechatJsapiAdapter::class) ?: []
        );
    }

    public function testH5AdapterImplementsPrepayAdapter(): void
    {
        $this->assertContains(
            \app\service\order\PrepayAdapter::class,
            class_implements(\app\service\client\payment\adapter\WechatH5Adapter::class) ?: []
        );
    }

    public function testPaymentLogEventConstantsAreUnique(): void
    {
        $constants = [
            \app\model\order\PaymentLog::EVENT_PREPAY,
            \app\model\order\PaymentLog::EVENT_PAID,
            \app\model\order\PaymentLog::EVENT_SUPERSEDED,
            \app\model\order\PaymentLog::EVENT_CLOSED,
        ];
        $this->assertCount(count($constants), array_unique($constants));
    }
}
