<?php

declare(strict_types=1);

namespace app\service\client {
    use think\Container;

    if (!function_exists(__NAMESPACE__ . '\\app')) {
        function app(): Container
        {
            return Container::getInstance();
        }
    }
}

namespace Tests\Unit\Service\Client {
    use app\service\client\CustomerServiceContextService;
    use app\service\connector\CustomerServiceSettingService;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use think\Container;

    final class CustomerServiceContextServiceTest extends TestCase
    {
        protected function tearDown(): void
        {
            Container::getInstance()->delete(CustomerServiceSettingService::class);
        }

        public function testClientConfigurationRequiresCurrentContextKeyId(): void
        {
            $this->bindSettings('');

            $this->assertFalse($this->isClientConfigured());
        }

        public function testClientConfigurationAcceptsCompleteCurrentCredential(): void
        {
            $this->bindSettings('ctx_abcdefghijklmnopqrstuvwx');

            $this->assertTrue($this->isClientConfigured());
        }

        private function bindSettings(string $keyId): void
        {
            $settings = new class($keyId) extends CustomerServiceSettingService {
                public function __construct(private readonly string $keyId)
                {
                }

                public function clientMode(): string
                {
                    return 'system';
                }

                public function contextKeyId(): string
                {
                    return $this->keyId;
                }

                public function contextSecret(): string
                {
                    return 'customer-service-context-secret-at-least-32-bytes';
                }

                public function widgetUrl(): string
                {
                    return 'https://customer.example.com/widget';
                }
            };

            Container::getInstance()->instance(CustomerServiceSettingService::class, $settings);
        }

        private function isClientConfigured(): bool
        {
            $method = new ReflectionMethod(CustomerServiceContextService::class, 'isClientConfigured');
            $method->setAccessible(true);

            return (bool) $method->invoke(new CustomerServiceContextService());
        }
    }
}
