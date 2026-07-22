<?php

declare(strict_types=1);

namespace app\middleware\connector {
    use think\Container;

    if (!function_exists(__NAMESPACE__ . '\\app')) {
        function app(): Container
        {
            return Container::getInstance();
        }
    }
}

namespace Tests\Unit\Middleware\Connector {

use app\middleware\connector\CustomerServiceSignature;
use app\service\connector\CustomerServiceIdempotencyStore;
use app\service\connector\CustomerServiceSettingService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use think\Container;
use think\Request;
use think\Response;

final class CustomerServiceSignatureHandleTest extends TestCase
{
    private bool $hadNonceStore = false;

    private ?object $previousNonceStore = null;

    private bool $hadSettings = false;

    private ?object $previousSettings = null;

    protected function setUp(): void
    {
        $container = Container::getInstance();
        $this->hadNonceStore = $container->exists(CustomerServiceIdempotencyStore::class);
        $this->previousNonceStore = $this->hadNonceStore
            ? $container->make(CustomerServiceIdempotencyStore::class)
            : null;
        $this->hadSettings = $container->exists(CustomerServiceSettingService::class);
        $this->previousSettings = $this->hadSettings
            ? $container->make(CustomerServiceSettingService::class)
            : null;
    }

    protected function tearDown(): void
    {
        $container = Container::getInstance();
        $container->delete(CustomerServiceIdempotencyStore::class);
        $container->delete(CustomerServiceSettingService::class);
        if ($this->hadNonceStore && $this->previousNonceStore !== null) {
            $container->instance(CustomerServiceIdempotencyStore::class, $this->previousNonceStore);
        }
        if ($this->hadSettings && $this->previousSettings !== null) {
            $container->instance(CustomerServiceSettingService::class, $this->previousSettings);
        }
    }

    public function testHandleAcceptsTheCrossServiceHmacV2GoldenVector(): void
    {
        $nonceStore = new SignatureNonceStore();
        $this->installDependencies($nonceStore);
        $nextCalls = 0;

        $response = (new CustomerServiceSignature())->handle(
            $this->goldenVectorRequest(),
            static function (Request $request) use (&$nextCalls): Response {
                $nextCalls++;
                return Response::create('accepted', 'html', 200);
            }
        );

        $this->assertSame(200, $response->getCode());
        $this->assertSame('accepted', $response->getContent());
        $this->assertSame(1, $nextCalls);
        $this->assertSame(1, $nonceStore->claimCalls);
        $this->assertSame([[
            'customer_service_connector_nonce:' . sha1('nonce-v2-0001'),
            '1',
            PHP_INT_MAX,
        ]], $nonceStore->claims);
    }

    public function testRepeatedNonceReturnsConflictWithoutCallingNext(): void
    {
        $nonceStore = new SignatureNonceStore();
        $nonceStore->claimResult = false;
        $this->installDependencies($nonceStore);
        $nextCalls = 0;

        try {
            (new CustomerServiceSignature())->handle(
                $this->goldenVectorRequest(),
                static function (Request $request) use (&$nextCalls): Response {
                    $nextCalls++;
                    return Response::create('unexpected', 'html', 200);
                }
            );
            self::fail('A repeated nonce was accepted.');
        } catch (BusinessException $error) {
            $this->assertSame(409, $error->getCode());
            $this->assertSame('客服连接器请求已重复', $error->getMessage());
        }

        $this->assertSame(0, $nextCalls);
        $this->assertSame(1, $nonceStore->claimCalls);
    }

    public function testNonceStoreExceptionReturnsServiceUnavailableWithoutCallingNext(): void
    {
        $nonceStore = new SignatureNonceStore();
        $nonceStore->claimError = new \RuntimeException('redis unavailable');
        $this->installDependencies($nonceStore);
        $nextCalls = 0;

        try {
            (new CustomerServiceSignature())->handle(
                $this->goldenVectorRequest(),
                static function (Request $request) use (&$nextCalls): Response {
                    $nextCalls++;
                    return Response::create('unexpected', 'html', 200);
                }
            );
            self::fail('A request ran while the nonce store was unavailable.');
        } catch (BusinessException $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertSame('客服连接器防重存储不可用，请稍后重试', $error->getMessage());
        }

        $this->assertSame(0, $nextCalls);
        $this->assertSame(1, $nonceStore->claimCalls);
    }

    public function testTamperedSignedIdempotencyKeyIsRejectedBeforeNonceAcquisition(): void
    {
        $nonceStore = new SignatureNonceStore();
        $this->installDependencies($nonceStore);
        $request = $this->goldenVectorRequest('tampered-execution-key');
        $nextCalls = 0;

        try {
            (new CustomerServiceSignature())->handle(
                $request,
                static function (Request $request) use (&$nextCalls): Response {
                    $nextCalls++;
                    return Response::create('unexpected', 'html', 200);
                }
            );
            self::fail('A tampered signed idempotency key was accepted.');
        } catch (BusinessException $error) {
            $this->assertSame(401, $error->getCode());
            $this->assertSame('客服连接器身份头摘要不匹配', $error->getMessage());
        }

        $this->assertSame(0, $nextCalls);
        $this->assertSame(0, $nonceStore->claimCalls);
        $this->assertSame([], $nonceStore->claims);
    }

    private function installDependencies(SignatureNonceStore $nonceStore): void
    {
        $settings = new class extends CustomerServiceSettingService {
            public function connectorEnabled(): bool
            {
                return true;
            }

            public function connectorSecret(): string
            {
                return 'connector-signing-secret-at-least-32-bytes';
            }

            public function timestampWindow(): int
            {
                return PHP_INT_MAX;
            }

            public function allowedIps(): string
            {
                return '';
            }
        };
        $container = Container::getInstance();
        $container->instance(CustomerServiceSettingService::class, $settings);
        $container->instance(CustomerServiceIdempotencyStore::class, $nonceStore);
    }

    private function goldenVectorRequest(string $idempotencyKey = 'order-remark-42-001'): Request
    {
        $body = '{"remark":"已联系用户","actor_name":"Alice"}';

        return (new Request())
            ->setMethod('POST')
            ->setUrl('/customer-service/api/v1/orders/42/remarks?notify=false')
            ->withInput($body)
            ->withHeader([
                'X-CS-Timestamp' => '1783526400',
                'X-CS-Nonce' => 'nonce-v2-0001',
                'X-CS-Body-SHA256' => 'fa4c1dc44ea24e3a4f63e098776dd4d592c41b0903f745b911da1560c81d4d4e',
                'X-CS-Headers-SHA256' => '674e91b4d36c8805d76fa39c6d51e240f903fb7fdea5a1c8dc88b42e6c2ee7a1',
                'X-CS-Signature' => '3ecd9befee1a6222f064819216f6a2708951923af92a54df04fd8ad29b2cd010',
                'X-CS-External-User-Authenticated' => 'true',
                'X-CS-External-User-Id' => '10001',
                'X-CS-Idempotency-Key' => $idempotencyKey,
                'X-CS-Resource-Owner-Id' => '10001',
                'X-CS-Signature-Version' => '2',
            ]);
    }
}

final class SignatureNonceStore extends CustomerServiceIdempotencyStore
{
    public int $claimCalls = 0;

    /** @var array<int, array{string, string, int}> */
    public array $claims = [];

    public bool $claimResult = true;

    public ?\Throwable $claimError = null;

    public function claim(string $logicalKey, string $processingState, int $ttl): bool
    {
        $this->claimCalls++;
        $this->claims[] = [$logicalKey, $processingState, $ttl];
        if ($this->claimError !== null) {
            throw $this->claimError;
        }

        return $this->claimResult;
    }
}
}
