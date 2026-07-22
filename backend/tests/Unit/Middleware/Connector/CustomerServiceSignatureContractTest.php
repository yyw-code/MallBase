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
        $this->assertStringContainsString('x-cs-idempotency-key', $source);
        $this->assertStringContainsString('x-cs-signature-version', $source);
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

    public function testSignatureHeadersAcceptCanonicalValues(): void
    {
        $this->invokeSignatureHeaderValidation(
            '1783526400',
            'nonce-v2-0001',
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64)
        );
        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidSignatureHeaderProvider')]
    public function testSignatureHeadersRejectMalformedValues(
        string $timestamp,
        string $nonce,
        string $signature,
        string $bodyHash,
        string $headersHash
    ): void {
        $this->expectException(\mall_base\exception\BusinessException::class);
        $this->expectExceptionCode(401);

        $this->invokeSignatureHeaderValidation($timestamp, $nonce, $signature, $bodyHash, $headersHash);
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function invalidSignatureHeaderProvider(): array
    {
        $signature = str_repeat('a', 64);
        $bodyHash = str_repeat('b', 64);

        return [
            'timestamp suffix' => ['1783526400x', 'nonce-v2-0001', $signature, $bodyHash, ''],
            'short nonce' => ['1783526400', 'short', $signature, $bodyHash, ''],
            'newline nonce' => ['1783526400', "nonce-v2\n0001", $signature, $bodyHash, ''],
            'non-hex signature' => ['1783526400', 'nonce-v2-0001', str_repeat('z', 64), $bodyHash, ''],
            'short body digest' => ['1783526400', 'nonce-v2-0001', $signature, 'abc', ''],
            'short headers digest' => ['1783526400', 'nonce-v2-0001', $signature, $bodyHash, 'abc'],
        ];
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

    public function testVersionTwoSignedHeadersIncludeVersionIdempotencyAndIdentity(): void
    {
        $headers = [
            'X-CS-Signature-Version' => '2',
            'X-CS-Idempotency-Key' => 'execution-123',
            'X-CS-External-User-Authenticated' => 'true',
            'X-CS-External-User-Id' => 'user-42',
            'X-CS-Resource-Owner-Id' => 'user-42',
        ];

        $this->assertSame(
            $this->expectedSignedHeadersHash($headers),
            $this->invokeSignedHeadersHash($headers)
        );
    }

    public function testVersionTwoSignedHeadersMatchGoldenVector(): void
    {
        $this->assertSame(
            '674e91b4d36c8805d76fa39c6d51e240f903fb7fdea5a1c8dc88b42e6c2ee7a1',
            $this->invokeSignedHeadersHash([
                'X-CS-External-User-Authenticated' => 'true',
                'X-CS-External-User-Id' => '10001',
                'X-CS-Idempotency-Key' => 'order-remark-42-001',
                'X-CS-Resource-Owner-Id' => '10001',
                'X-CS-Signature-Version' => '2',
            ])
        );
    }

    public function testVersionTwoSignedHeadersChangeWhenIdempotencyOrVersionIsTampered(): void
    {
        $headers = [
            'X-CS-Signature-Version' => '2',
            'X-CS-Idempotency-Key' => 'execution-123',
            'X-CS-External-User-Authenticated' => 'true',
            'X-CS-External-User-Id' => 'user-42',
            'X-CS-Resource-Owner-Id' => 'user-42',
        ];
        $original = $this->invokeSignedHeadersHash($headers);

        $tamperedIdempotency = $headers;
        $tamperedIdempotency['X-CS-Idempotency-Key'] = 'execution-456';
        $this->assertNotSame($original, $this->invokeSignedHeadersHash($tamperedIdempotency));

        $tamperedVersion = $headers;
        $tamperedVersion['X-CS-Signature-Version'] = '3';
        $this->expectException(\mall_base\exception\BusinessException::class);
        $this->expectExceptionCode(401);
        $this->invokeSignedHeadersHash($tamperedVersion);
    }

    public function testVersionTwoSignedHeadersRejectNonTokenIdempotencyKey(): void
    {
        $this->expectException(\mall_base\exception\BusinessException::class);
        $this->expectExceptionCode(401);

        $this->invokeSignedHeadersHash([
            'X-CS-Signature-Version' => '2',
            'X-CS-Idempotency-Key' => 'invalid key',
        ]);
    }

    public function testLegacySignedHeadersWithoutVersionRemainCompatible(): void
    {
        $headers = [
            'X-CS-External-User-Authenticated' => 'true',
            'X-CS-External-User-Id' => 'user-42',
            'X-CS-Resource-Owner-Id' => 'user-42',
        ];

        $this->assertSame(
            $this->expectedSignedHeadersHash($headers),
            $this->invokeSignedHeadersHash($headers)
        );
    }

    public function testUnknownSignatureVersionIsRejected(): void
    {
        $this->expectException(\mall_base\exception\BusinessException::class);
        $this->expectExceptionCode(401);

        $this->invokeSignedHeadersHash([
            'X-CS-Signature-Version' => '99',
            'X-CS-External-User-Authenticated' => 'false',
        ]);
    }

    private function invokeSignatureHeaderValidation(
        string $timestamp,
        string $nonce,
        string $signature,
        string $bodyHash,
        string $headersHash
    ): void {
        $method = new ReflectionMethod(CustomerServiceSignature::class, 'assertSignatureHeaders');
        $method->setAccessible(true);
        $method->invoke(new CustomerServiceSignature(), $timestamp, $nonce, $signature, $bodyHash, $headersHash);
    }

    /**
     * @param array<string, string> $headers
     */
    private function invokeSignedHeadersHash(array $headers): string
    {
        $request = (new Request())->withHeader($headers);
        $method = new ReflectionMethod(CustomerServiceSignature::class, 'signedHeadersHash');
        $method->setAccessible(true);

        return (string) $method->invoke(new CustomerServiceSignature(), $request);
    }

    /**
     * @param array<string, string> $headers
     */
    private function expectedSignedHeadersHash(array $headers): string
    {
        $canonical = [];
        foreach ($headers as $name => $value) {
            $canonical[strtolower($name)] = trim($value);
        }
        ksort($canonical);

        $lines = [];
        foreach ($canonical as $name => $value) {
            if ($value !== '') {
                $lines[] = $name . ':' . $value;
            }
        }

        return hash('sha256', implode("\n", $lines));
    }
}
