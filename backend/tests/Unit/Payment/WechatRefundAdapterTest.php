<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use app\service\client\payment\WechatPayClient;
use app\service\order\WechatRefundAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 微信退款适配器静态规约测试
 *
 * 不连微信、不构造 EasyWeChat Application；通过源码断言锁住真实退款请求的关键契约。
 */
final class WechatRefundAdapterTest extends TestCase
{
    private static function sourceOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($file, sprintf('%s 类文件应能定位', $class));

        return (string) file_get_contents((string) $file);
    }

    public function testWechatPayClientContainsRefundEndpoint(): void
    {
        $source = self::sourceOf(WechatPayClient::class);

        $this->assertStringContainsString('/v3/refund/domestic/refunds', $source);
        $this->assertStringContainsString("public function refund", $source);
    }

    public function testRefundAdapterBuildsWechatPayloadWithStableOutRefundNo(): void
    {
        $source = self::sourceOf(WechatRefundAdapter::class);

        $this->assertStringContainsString("'transaction_id' => \$context->transactionId", $source);
        $this->assertStringContainsString("'out_refund_no'  => \$context->outRefundNo", $source);
        $this->assertStringContainsString("'refund'   => \$context->refundAmountCents", $source);
        $this->assertStringContainsString("'total'    => \$context->totalAmountCents", $source);
        $this->assertStringContainsString("'currency' => 'CNY'", $source);
    }

    public function testRefundAdapterRequiresPositiveAmountsAndTransactionId(): void
    {
        $source = self::sourceOf(WechatRefundAdapter::class);

        $this->assertStringContainsString('transactionId ===', $source);
        $this->assertStringContainsString('refundAmountCents <= 0', $source);
        $this->assertStringContainsString('totalAmountCents <= 0', $source);
        $this->assertStringContainsString('refundAmountCents > $context->totalAmountCents', $source);
    }

    public function testRefundAdapterOnlyAcceptsSuccessOrProcessingStatus(): void
    {
        $source = self::sourceOf(WechatRefundAdapter::class);

        $this->assertStringContainsString("['SUCCESS', 'PROCESSING']", $source);
        $this->assertStringContainsString('return $status', $source);
        $this->assertStringContainsString('微信退款状态异常', $source);
    }
}
