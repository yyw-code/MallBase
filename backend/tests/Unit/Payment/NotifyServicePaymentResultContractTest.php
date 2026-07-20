<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use app\service\SystemSettingService;
use app\service\admin\order\RefundOrderAdminService;
use app\service\client\payment\NotifyService;
use app\service\client\payment\WechatPaymentResultService;
use app\service\client\payment\WechatPayFactory;
use EasyWeChat\Pay\Application as PayApplication;
use EasyWeChat\Pay\Contracts\Validator as ValidatorInterface;
use EasyWeChat\Pay\Message as PayMessage;
use EasyWeChat\Pay\Server as PayServer;
use PHPUnit\Framework\TestCase;
use think\App;

final class NotifyServicePaymentResultContractTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new App(dirname(__DIR__, 3));
        $this->app->initialize();
        $this->app->instance(SystemSettingService::class, new NotifyContractSettingService());
    }

    public function testVerifiedPaymentDelegatesToSharedResultService(): void
    {
        $sequence = [];
        $payload = $this->paymentPayload();
        $paymentResults = $this->createMock(WechatPaymentResultService::class);
        $paymentResults->expects(self::once())
            ->method('applyVerifiedSuccess')
            ->with(
                self::callback(function (array $actual) use ($payload, &$sequence): bool {
                    $sequence[] = 'apply';

                    return $actual === $payload;
                }),
                'merchant-1',
                'MB100-ABC',
            )
            ->willReturn([
                'applied' => true,
                'duplicate' => false,
                'transaction_id' => 'wx-transaction',
                'out_trade_no' => 'MB100-ABC',
            ]);

        $response = (new NotifyService($this->factory($payload, $sequence), $paymentResults))
            ->handle($this->headers('payment-nonce-1'), '{"encrypted":"payment"}');

        self::assertSame(['validate', 'decrypt', 'apply'], $sequence);
        self::assertSame(200, $response['status']);
        self::assertSame(['code' => 'SUCCESS', 'message' => '成功'], $response['body']);
    }

    public function testSharedPaymentResultFailureRemainsRetryable(): void
    {
        $sequence = [];
        $paymentResults = $this->createMock(WechatPaymentResultService::class);
        $paymentResults->expects(self::once())
            ->method('applyVerifiedSuccess')
            ->willThrowException(new \RuntimeException('WECHAT_PAYMENT_AMOUNT_MISMATCH'));

        $response = (new NotifyService($this->factory($this->paymentPayload(), $sequence), $paymentResults))
            ->handle($this->headers('payment-nonce-2'), '{"encrypted":"payment"}');

        self::assertSame(500, $response['status']);
        self::assertSame('FAIL', $response['body']['code']);
    }

    public function testSuccessfulRefundDelegatesToRefundService(): void
    {
        $sequence = [];
        $refundResults = $this->createMock(RefundOrderAdminService::class);
        $refundResults->expects(self::once())
            ->method('completeWechatRefund')
            ->with('RF100', 500, '2026-07-14T10:00:00+08:00');
        $this->app->instance(RefundOrderAdminService::class, $refundResults);
        $paymentResults = $this->createMock(WechatPaymentResultService::class);
        $paymentResults->expects(self::never())->method('applyVerifiedSuccess');

        $response = (new NotifyService($this->factory([
            'out_refund_no' => 'RF100',
            'refund_status' => 'SUCCESS',
            'amount' => ['refund' => 500],
            'success_time' => '2026-07-14T10:00:00+08:00',
        ], $sequence), $paymentResults))
            ->handleRefund($this->headers('refund-nonce-1'), '{"encrypted":"refund"}');

        self::assertSame(['validate', 'decrypt'], $sequence);
        self::assertSame(200, $response['status']);
    }

    /** @param array<string,mixed> $payload @param list<string> $sequence */
    private function factory(array $payload, array &$sequence): WechatPayFactory
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(static function () use (&$sequence): void {
            $sequence[] = 'validate';
        });
        $server = $this->createStub(PayServer::class);
        $server->method('getRequestMessage')->willReturnCallback(static function () use ($payload, &$sequence): PayMessage {
            $sequence[] = 'decrypt';

            return new PayMessage($payload, '{"resource":{"ciphertext":"opaque"}}');
        });
        $application = $this->createStub(PayApplication::class);
        $application->method('getValidator')->willReturn($validator);
        $application->method('getServer')->willReturn($server);
        $factory = $this->createStub(WechatPayFactory::class);
        $factory->method('build')->willReturn($application);

        return $factory;
    }

    /** @return array<string,string> */
    private function headers(string $nonce): array
    {
        return [
            'Wechatpay-Signature' => 'signature',
            'Wechatpay-Serial' => 'serial',
            'Wechatpay-Timestamp' => '1000',
            'Wechatpay-Nonce' => $nonce,
            'Content-Type' => 'application/json',
        ];
    }

    /** @return array<string,mixed> */
    private function paymentPayload(): array
    {
        return [
            'mchid' => 'merchant-1',
            'out_trade_no' => 'MB100-ABC',
            'transaction_id' => 'wx-transaction',
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => 1200],
            'payer' => ['openid' => 'openid'],
            'success_time' => '2026-07-14T10:00:00+08:00',
        ];
    }
}

final class NotifyContractSettingService extends SystemSettingService
{
    public function __construct()
    {
    }

    public function getSystemSetting(string|array $codeOrCodes, mixed $default = null): mixed
    {
        return $codeOrCodes === 'pay_wechat_mchid' ? 'merchant-1' : $default;
    }
}
