<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Connector;

use app\middleware\connector\CustomerServiceSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\Request;

final class CustomerServiceSignatureContractTest extends TestCase
{
    public function testConnectorSignatureSupportsSignedIdentityHeaders(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/app/middleware/connector/CustomerServiceSignature.php');
        $this->assertIsString($source);

        $this->assertStringContainsString("'X-CS-Headers-SHA256'", $source);
        $this->assertStringContainsString('signedHeadersHash', $source);
        $this->assertStringContainsString('x-cs-external-user-authenticated', $source);
        $this->assertStringContainsString('x-cs-external-user-id', $source);
        $this->assertStringContainsString('x-cs-resource-owner-id', $source);
        $this->assertStringContainsString('$canonicalParts[] = $headersHash', $source);
        $this->assertStringContainsString('$this->canonicalRequestPath($request)', $source);
        $this->assertStringContainsString('$this->assertConnectorSecret($secret)', $source);
    }

    #[DataProvider('canonicalRequestPathProvider')]
    public function testCanonicalRequestPathUsesFinalPathnameAndRawQuery(string $url, string $expected): void
    {
        $middleware = new CustomerServiceSignature();
        $this->assertTrue(method_exists($middleware, 'canonicalRequestPath'));

        $request = new Request();
        $request->setUrl($url);
        $method = new ReflectionMethod(CustomerServiceSignature::class, 'canonicalRequestPath');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($middleware, $request));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function canonicalRequestPathProvider(): array
    {
        return [
            'without query' => [
                '/customer-service/api/v1/health',
                '/customer-service/api/v1/health',
            ],
            'keeps query order' => [
                '/customer-service/api/v1/orders?status=paid&limit=10',
                '/customer-service/api/v1/orders?status=paid&limit=10',
            ],
            'keeps encoded and repeated values' => [
                '/customer-service/api/v1/products/search?keyword=%E6%89%8B%E6%9C%BA&tag=a&tag=b',
                '/customer-service/api/v1/products/search?keyword=%E6%89%8B%E6%9C%BA&tag=a&tag=b',
            ],
            'keeps deployment subpath' => [
                '/mall/customer-service/api/v1/orders/1/summary?include=items',
                '/mall/customer-service/api/v1/orders/1/summary?include=items',
            ],
        ];
    }

    public function testConnectorSecretRejectsValuesShorterThan32Utf8Bytes(): void
    {
        $middleware = new CustomerServiceSignature();
        $this->assertTrue(method_exists($middleware, 'assertConnectorSecret'));
        $method = new ReflectionMethod(CustomerServiceSignature::class, 'assertConnectorSecret');
        $method->setAccessible(true);

        $this->expectException(\mall_base\exception\BusinessException::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('客服连接器密钥长度不足');

        $method->invoke($middleware, 'short-secret');
    }

    #[DataProvider('validConnectorSecretProvider')]
    public function testConnectorSecretAcceptsAtLeast32Utf8Bytes(string $secret): void
    {
        $middleware = new CustomerServiceSignature();
        $this->assertTrue(method_exists($middleware, 'assertConnectorSecret'));
        $method = new ReflectionMethod(CustomerServiceSignature::class, 'assertConnectorSecret');
        $method->setAccessible(true);

        $method->invoke($middleware, $secret);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validConnectorSecretProvider(): array
    {
        return [
            '32 ascii bytes' => [str_repeat('a', 32)],
            'utf8 byte length' => [str_repeat('密钥', 6)],
        ];
    }

    public function testAtomicNonceAcquisitionAllowsTheFirstRequest(): void
    {
        $handler = new class {
            /** @var array<int, mixed> */
            public array $arguments = [];

            public function rawCommand(mixed ...$arguments): string
            {
                $this->arguments = $arguments;
                return 'OK';
            }
        };

        $this->assertTrue($this->invokeAtomicNonce($handler, 'nonce-key', 300));
        $this->assertSame(['SET', 'nonce-key', '1', 'EX', '300', 'NX'], $handler->arguments);
    }

    public function testAtomicNonceAcquisitionRejectsARepeatedRequest(): void
    {
        $handler = new class {
            public function rawCommand(mixed ...$arguments): bool
            {
                return false;
            }
        };

        $this->assertFalse($this->invokeAtomicNonce($handler, 'nonce-key', 300));
    }

    public function testAtomicNonceAcquisitionFailsClosedOnCacheException(): void
    {
        $handler = new class {
            public function rawCommand(mixed ...$arguments): bool
            {
                throw new \RuntimeException('cache unavailable');
            }
        };

        $this->assertFalse($this->invokeAtomicNonce($handler, 'nonce-key', 300));
    }

    public function testAtomicNonceAcquisitionFailsClosedWithoutAtomicCommandSupport(): void
    {
        $this->assertFalse($this->invokeAtomicNonce(new class {}, 'nonce-key', 300));
    }

    public function testAtomicNonceAcquisitionSupportsPredisExecuteRaw(): void
    {
        $handler = new class {
            /** @var array<int, mixed> */
            public array $arguments = [];

            /** @param array<int, mixed> $arguments */
            public function executeRaw(array $arguments): string
            {
                $this->arguments = $arguments;
                return 'OK';
            }
        };

        $this->assertTrue($this->invokeAtomicNonce($handler, 'nonce-key', 300));
        $this->assertSame(['SET', 'nonce-key', '1', 'EX', '300', 'NX'], $handler->arguments);
    }

    private function invokeAtomicNonce(object $handler, string $key, int $ttl): bool
    {
        $this->assertTrue(method_exists(CustomerServiceSignature::class, 'acquireAtomicNonce'));

        $method = new ReflectionMethod(CustomerServiceSignature::class, 'acquireAtomicNonce');
        return (bool) $method->invoke(new CustomerServiceSignature(), $handler, $key, $ttl);
    }
}
