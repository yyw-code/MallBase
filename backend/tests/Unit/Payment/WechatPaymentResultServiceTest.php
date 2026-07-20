<?php

declare(strict_types=1);

namespace tests\Unit\Payment;

use app\service\client\order\OrderService;
use app\service\client\payment\WechatPaymentResultService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;

final class WechatPaymentResultServiceTest extends TestCase
{
    public function testCallbackAndActiveQueryApplyOnePaidTransition(): void
    {
        $service = $this->service();
        $payload = $this->successPayload();

        $first = $service->applyVerifiedSuccess($payload, 'merchant-1', 'MB100-ABC');
        $second = $service->applyVerifiedSuccess($payload, 'merchant-1', 'MB100-ABC');

        self::assertTrue($first['applied']);
        self::assertFalse($first['duplicate']);
        self::assertFalse($second['applied']);
        self::assertTrue($second['duplicate']);
        self::assertSame(1, $service->appendCount);
        self::assertSame(1, $service->confirmCount);
    }

    public function testDuplicateKeyRaceDoesNotRepeatOrderTransition(): void
    {
        $service = $this->service();
        $service->appendSucceeds = false;

        $result = $service->applyVerifiedSuccess($this->successPayload(), 'merchant-1', 'MB100-ABC');

        self::assertFalse($result['applied']);
        self::assertTrue($result['duplicate']);
        self::assertSame(0, $service->confirmCount);
    }

    public function testDuplicateTransactionStillValidatesExpectedAmount(): void
    {
        $service = $this->service();
        $service->applyVerifiedSuccess($this->successPayload(), 'merchant-1', 'MB100-ABC');
        $mismatched = array_replace_recursive($this->successPayload(), ['amount' => ['total' => 1199]]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('WECHAT_PAYMENT_AMOUNT_MISMATCH');
        $service->applyVerifiedSuccess($mismatched, 'merchant-1', 'MB100-ABC');
    }

    /** @dataProvider mismatchProvider */
    public function testMerchantOrderAndAmountMismatchFailClosed(array $payload, string $merchantId, string $outTradeNo, string $code): void
    {
        $service = $this->service();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage($code);
        $service->applyVerifiedSuccess($payload, $merchantId, $outTradeNo);
    }

    public static function mismatchProvider(): iterable
    {
        $base = [
            'mchid' => 'merchant-1',
            'out_trade_no' => 'MB100-ABC',
            'transaction_id' => 'wx-transaction-1',
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => 1200],
        ];

        yield 'merchant' => [$base, 'merchant-2', 'MB100-ABC', 'WECHAT_PAYMENT_MERCHANT_MISMATCH'];
        yield 'order' => [$base, 'merchant-1', 'MB999-OTHER', 'WECHAT_PAYMENT_ORDER_MISMATCH'];
        yield 'amount' => [array_replace_recursive($base, ['amount' => ['total' => 1199]]), 'merchant-1', 'MB100-ABC', 'WECHAT_PAYMENT_AMOUNT_MISMATCH'];
    }

    private function service(): TestWechatPaymentResultService
    {
        /** @var OrderService $orders */
        $orders = $this->createStub(OrderService::class);

        return new TestWechatPaymentResultService($orders);
    }

    /** @return array<string,mixed> */
    private function successPayload(): array
    {
        return [
            'mchid' => 'merchant-1',
            'out_trade_no' => 'MB100-ABC',
            'transaction_id' => 'wx-transaction-1',
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => 1200],
            'payer' => ['openid' => 'openid-1'],
            'success_time' => '2026-07-13T10:00:00+08:00',
        ];
    }
}

final class TestWechatPaymentResultService extends WechatPaymentResultService
{
    public int $appendCount = 0;
    public int $confirmCount = 0;
    public bool $appendSucceeds = true;
    private bool $paid = false;

    /** @return array<string,mixed>|null */
    protected function findActivePrepay(string $outTradeNo): ?array
    {
        return $outTradeNo === 'MB100-ABC' ? [
            'id' => 10,
            'order_id' => 20,
            'order_sn' => 'MB100',
            'out_trade_no' => 'MB100-ABC',
            'pay_method' => 1,
            'scene' => 1,
            'amount_cents' => 1200,
            'payer_openid' => 'openid-1',
        ] : null;
    }

    protected function paidResultExists(string $transactionId): bool
    {
        return $this->paid;
    }

    /** @param array<string,mixed> $prepay @param array<string,mixed> $payload */
    protected function appendPaidResult(array $prepay, array $payload): bool
    {
        $this->appendCount++;
        if (!$this->appendSucceeds) {
            return false;
        }
        $this->paid = true;

        return true;
    }

    /** @param array<string,mixed> $prepay */
    protected function confirmOrderPaid(array $prepay, string $transactionId): void
    {
        $this->confirmCount++;
    }

    protected function inTransaction(callable $callback): mixed
    {
        return $callback();
    }
}
