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

        public function testClientConfigurationDoesNotRequireWidgetUrl(): void
        {
            $this->bindSettings(
                'ctx_abcdefghijklmnopqrstuvwx',
                'https://customer.example.com/api',
                'https://customer.example.com',
                ''
            );

            $this->assertTrue($this->isClientConfigured());
        }

        public function testClientConfigurationRequiresApiBase(): void
        {
            $this->bindSettings(
                'ctx_abcdefghijklmnopqrstuvwx',
                '',
                'https://customer.example.com'
            );

            $this->assertFalse($this->isClientConfigured());
        }

        public function testClientConfigurationRequiresSocketBase(): void
        {
            $this->bindSettings(
                'ctx_abcdefghijklmnopqrstuvwx',
                'https://customer.example.com/api',
                ''
            );

            $this->assertFalse($this->isClientConfigured());
        }

        public function testConversationSourceComesFromVerifiedPrimaryResource(): void
        {
            $service = new CustomerServiceContextService();
            $method = new ReflectionMethod(CustomerServiceContextService::class, 'sourceForPrimary');
            $method->setAccessible(true);

            $this->assertSame('goods', $method->invoke($service, ['type' => 'product', 'id' => '12']));
            $this->assertSame('order', $method->invoke($service, ['type' => 'order', 'id' => '34']));
            $this->assertSame('mallbase', $method->invoke($service, ['type' => 'user', 'id' => '56']));
        }

        public function testConversationKeyIsBuiltFromVerifiedUserAndPrimaryResource(): void
        {
            $service = new CustomerServiceContextService();
            $method = new ReflectionMethod(CustomerServiceContextService::class, 'conversationKey');
            $method->setAccessible(true);

            $this->assertSame(
                'mallbase:8:order:order:330',
                $method->invoke($service, 8, 'order', ['type' => 'order', 'id' => '330'])
            );
        }

        public function testResourceIdAcceptsOnlyStrictPositiveIntegers(): void
        {
            $service = new CustomerServiceContextService();
            $method = new ReflectionMethod(CustomerServiceContextService::class, 'resourceId');
            $method->setAccessible(true);

            $this->assertSame(12, $method->invoke($service, ['id' => 12]));
            $this->assertSame(12, $method->invoke($service, ['id' => '12']));
            $this->assertSame(34, $method->invoke($service, ['externalId' => '34']));
            $this->assertSame(0, $method->invoke($service, ['id' => '12abc']));
            $this->assertSame(0, $method->invoke($service, ['id' => ['unexpected']]));
            $this->assertSame(0, $method->invoke($service, ['id' => 12.5]));
            $this->assertSame(0, $method->invoke($service, ['id' => '0']));
            $this->assertSame(0, $method->invoke($service, ['id' => '-12']));
        }

        public function testConversationIdentityAndResourcePresentationComeFromBackendTruth(): void
        {
            $source = file_get_contents(dirname(__DIR__, 4) . '/app/service/client/CustomerServiceContextService.php');
            $this->assertIsString($source);

            $this->assertStringNotContainsString("\$input['conversation_key']", $source);
            $this->assertStringContainsString('sourceForPrimary($primary)', $source);
            $this->assertStringContainsString('conversationKey($userId, $source, $primary)', $source);
            $this->assertStringContainsString("->where('status', 1)", $source);
            $this->assertStringContainsString("->where('is_on_sale', 1)", $source);
            $this->assertStringNotContainsString("\$row['title']", $source);
            $this->assertStringNotContainsString("\$row['summary']", $source);
            $this->assertStringNotContainsString("\$row['url']", $source);
        }

        private function bindSettings(
            string $keyId,
            string $apiBase = 'https://customer.example.com/api',
            string $socketBase = 'https://customer.example.com',
            string $widgetUrl = 'https://customer.example.com/widget'
        ): void
        {
            $settings = new class($keyId, $apiBase, $socketBase, $widgetUrl) extends CustomerServiceSettingService {
                public function __construct(
                    private readonly string $keyId,
                    private readonly string $apiBase,
                    private readonly string $socketBase,
                    private readonly string $widgetUrl
                ) {
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
                    return $this->widgetUrl;
                }

                public function apiBase(): string
                {
                    return $this->apiBase;
                }

                public function socketBase(): string
                {
                    return $this->socketBase;
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
