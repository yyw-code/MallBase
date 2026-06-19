<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use app\service\client\payment\NotifyService;
use EasyWeChat\Pay\Message;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class NotifyServiceDecryptedPayloadContractTest extends TestCase
{
    public function testNotifyServiceReadsDecryptedMessageAttributes(): void
    {
        $message = new Message(
            [
                'out_trade_no' => '2606120000000006-27A4F0',
                'transaction_id' => '4200003203202606121287382724',
                'trade_state' => 'SUCCESS',
                'amount' => ['total' => 1],
            ],
            json_encode([
                'id' => 'callback-id',
                'event_type' => 'TRANSACTION.SUCCESS',
                'resource' => ['ciphertext' => 'encrypted-payload'],
            ], JSON_THROW_ON_ERROR)
        );

        $reflection = new ReflectionClass(NotifyService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('decryptedAttributes');

        $attributes = $method->invoke($service, $message);

        $this->assertSame('2606120000000006-27A4F0', $attributes['out_trade_no']);
        $this->assertSame('4200003203202606121287382724', $attributes['transaction_id']);
        $this->assertSame('SUCCESS', $attributes['trade_state']);
        $this->assertSame(1, $attributes['amount']['total']);
        $this->assertArrayNotHasKey('resource', $attributes);
    }

    public function testNotifyServiceDoesNotReadWechatOuterEventAttributesForBusinessFields(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/service/client/payment/NotifyService.php');
        $this->assertIsString($source);

        $this->assertStringContainsString('decryptedAttributes($message)', $source);
        $this->assertStringContainsString('return $message->toArray();', $source);
        $this->assertStringNotContainsString('$message->getOriginalAttributes()', $source);
    }
}
