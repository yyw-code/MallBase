<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Connector {
    use app\service\connector\CustomerServiceSettingService;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class CustomerServiceSettingServiceTest extends TestCase
    {
        public function testReadsTrimmedContextKeyId(): void
        {
            $service = $this->service([
                'customer_service_context_key_id' => '  ctx_abcdefghijklmnopqrstuvwx  ',
            ]);

            $this->assertTrue(method_exists($service, 'contextKeyId'));
            $this->assertSame('ctx_abcdefghijklmnopqrstuvwx', $service->contextKeyId());
        }

        public function testNormalizesPlatformCodeToLowercase(): void
        {
            $service = $this->service([
                'customer_service_platform_code' => '  MallBase  ',
            ]);

            $this->assertSame('mallbase', $service->platformCode());
        }

        #[DataProvider('ttlProvider')]
        public function testClampsContextTtlToCurrentProtocolRange(mixed $configured, int $expected): void
        {
            $service = $this->service([
                'customer_service_context_ttl' => $configured,
            ]);

            $this->assertSame($expected, $service->contextTtl());
        }

        /**
         * @return array<string, array{mixed, int}>
         */
        public static function ttlProvider(): array
        {
            return [
                'below minimum' => [30, 60],
                'minimum' => [60, 60],
                'normal' => [180, 180],
                'maximum' => [300, 300],
                'above maximum' => [3600, 300],
                'empty uses default' => ['', 300],
            ];
        }

        /**
         * @param array<string, mixed> $values
         */
        private function service(array $values): CustomerServiceSettingService
        {
            return new class($values) extends CustomerServiceSettingService {
                /**
                 * @param array<string, mixed> $values
                 */
                public function __construct(private readonly array $values)
                {
                }

                protected function setting(string $code, mixed $default = null): mixed
                {
                    return array_key_exists($code, $this->values)
                        ? $this->values[$code]
                        : $default;
                }
            };
        }
    }
}
