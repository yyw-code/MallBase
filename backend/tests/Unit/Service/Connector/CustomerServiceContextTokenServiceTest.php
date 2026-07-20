<?php

declare(strict_types=1);

namespace app\service\connector {
    use think\Container;

    if (!function_exists(__NAMESPACE__ . '\\app')) {
        function app(): Container
        {
            return Container::getInstance();
        }
    }
}

namespace Tests\Unit\Service\Connector {

use app\service\connector\CustomerServiceContextTokenService;
use app\service\connector\CustomerServiceSettingService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\Container;

final class CustomerServiceContextTokenServiceTest extends TestCase
{
    private const KEY_ID = 'ctx_abcdefghijklmnopqrstuvwx';
    private const SECRET = 'customer-service-context-secret-at-least-32-bytes';

    protected function tearDown(): void
    {
        Container::getInstance()->delete(CustomerServiceSettingService::class);
    }

    public function testIssueCreatesStrictCurrentProfileToken(): void
    {
        $service = $this->service();
        $token = $service->issue($this->payload());
        [$encodedHeader, $encodedPayload, $encodedSignature] = explode('.', $token);

        $header = $this->decodePart($encodedHeader);
        $payload = $this->decodePart($encodedPayload);

        $this->assertSame([
            'alg' => 'HS256',
            'typ' => 'cs-context+jwt',
            'kid' => self::KEY_ID,
        ], $header);
        $this->assertSame('mallbase', $payload['iss']);
        $this->assertSame('customer-service', $payload['aud']);
        $this->assertSame('123', $payload['sub']);
        $this->assertSame('mallbase', $payload['platformCode']);
        $this->assertSame('123', $payload['visitor']['id']);
        $this->assertSame('product:1:user:123', $payload['conversationKey']);
        $this->assertSame('1', $payload['resources'][0]['id']);
        $this->assertIsInt($payload['iat']);
        $this->assertIsInt($payload['exp']);
        $this->assertSame(300, $payload['exp'] - $payload['iat']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $payload['jti']);

        $expectedSignature = $this->base64UrlEncode(hash_hmac(
            'sha256',
            $encodedHeader . '.' . $encodedPayload,
            self::SECRET,
            true
        ));
        $this->assertTrue(hash_equals($expectedSignature, $encodedSignature));
    }

    public function testServerClaimsOverrideCallerValues(): void
    {
        $payload = array_merge($this->payload(), [
            'iss' => 'attacker',
            'aud' => 'other-service',
            'sub' => 'other-user',
            'platformCode' => 'other-platform',
            'iat' => 1,
            'exp' => 2,
            'jti' => 'caller-controlled',
        ]);

        $decoded = $this->decodeTokenPayload($this->service()->issue($payload));

        $this->assertSame('mallbase', $decoded['iss']);
        $this->assertSame('customer-service', $decoded['aud']);
        $this->assertSame('123', $decoded['sub']);
        $this->assertSame('mallbase', $decoded['platformCode']);
        $this->assertNotSame(1, $decoded['iat']);
        $this->assertSame(300, $decoded['exp'] - $decoded['iat']);
        $this->assertNotSame('caller-controlled', $decoded['jti']);
    }

    public function testEachIssuedTokenUsesUniqueJti(): void
    {
        $service = $this->service();

        $first = $this->decodeTokenPayload($service->issue($this->payload()));
        $second = $this->decodeTokenPayload($service->issue($this->payload()));

        $this->assertNotSame($first['jti'], $second['jti']);
    }

    public function testIssueNormalizesNumericVisitorIdInPayload(): void
    {
        $payload = $this->payload();
        $payload['visitor']['id'] = 123;

        $decoded = $this->decodeTokenPayload($this->service()->issue($payload));

        $this->assertSame('123', $decoded['sub']);
        $this->assertSame('123', $decoded['visitor']['id']);
    }

    /**
     * @param array{key_id:string,secret:string,platform_code:string,ttl:int} $settings
     */
    #[DataProvider('invalidSettingsProvider')]
    public function testIssueFailsClosedForInvalidSettings(array $settings, string $message): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage($message);

        $this->service($settings)->issue($this->payload());
    }

    /**
     * @return array<string, array{array{key_id:string,secret:string,platform_code:string,ttl:int}, string}>
     */
    public static function invalidSettingsProvider(): array
    {
        return [
            'missing key id' => [[
                'key_id' => '',
                'secret' => self::SECRET,
                'platform_code' => 'mallbase',
                'ttl' => 300,
            ], '客服上下文 Key ID 未配置'],
            'invalid key id' => [[
                'key_id' => 'legacy_mallbase',
                'secret' => self::SECRET,
                'platform_code' => 'mallbase',
                'ttl' => 300,
            ], '客服上下文 Key ID 无效'],
            'missing secret' => [[
                'key_id' => self::KEY_ID,
                'secret' => '',
                'platform_code' => 'mallbase',
                'ttl' => 300,
            ], '客服上下文密钥未配置'],
            'short secret' => [[
                'key_id' => self::KEY_ID,
                'secret' => 'short-secret',
                'platform_code' => 'mallbase',
                'ttl' => 300,
            ], '客服上下文密钥长度不足'],
            'invalid platform code' => [[
                'key_id' => self::KEY_ID,
                'secret' => self::SECRET,
                'platform_code' => 'invalid platform',
                'ttl' => 300,
            ], '客服平台标识无效'],
        ];
    }

    public function testIssueRejectsPayloadWithoutVisitorId(): void
    {
        $payload = $this->payload();
        $payload['visitor'] = [];

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('客服访客标识不能为空');

        $this->service()->issue($payload);
    }

    /**
     * @param array{key_id:string,secret:string,platform_code:string,ttl:int}|null $values
     */
    private function service(?array $values = null): CustomerServiceContextTokenService
    {
        $values ??= [
            'key_id' => self::KEY_ID,
            'secret' => self::SECRET,
            'platform_code' => 'mallbase',
            'ttl' => 300,
        ];

        $settings = new class($values) extends CustomerServiceSettingService {
            /**
             * @param array{key_id:string,secret:string,platform_code:string,ttl:int} $values
             */
            public function __construct(private readonly array $values)
            {
            }

            public function contextKeyId(): string
            {
                return $this->values['key_id'];
            }

            public function contextSecret(): string
            {
                return $this->values['secret'];
            }

            public function platformCode(): string
            {
                return $this->values['platform_code'];
            }

            public function contextTtl(): int
            {
                return $this->values['ttl'];
            }
        };

        Container::getInstance()->instance(CustomerServiceSettingService::class, $settings);

        return new CustomerServiceContextTokenService();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'visitor' => [
                'id' => '123',
                'authenticated' => true,
                'name' => '测试用户',
            ],
            'conversationKey' => 'product:1:user:123',
            'resources' => [[
                'type' => 'product',
                'id' => '1',
                'title' => '测试商品',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeTokenPayload(string $token): array
    {
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        return $this->decodePart($parts[1]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePart(string $value): array
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        $this->assertIsString($decoded);

        $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return $data;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
}
