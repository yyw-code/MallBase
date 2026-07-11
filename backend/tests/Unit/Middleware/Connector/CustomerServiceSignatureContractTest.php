<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Connector;

use app\middleware\connector\CustomerServiceSignature;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
