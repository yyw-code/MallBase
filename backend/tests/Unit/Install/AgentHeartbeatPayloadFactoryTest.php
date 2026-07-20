<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\AgentHeartbeatPayloadFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentHeartbeatPayloadFactoryTest extends TestCase
{
    private string $versionPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versionPath = sys_get_temp_dir() . '/mallbase-version-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($this->versionPath, json_encode([
            'version' => '1.2.3',
            'released_at' => '2026-07-13 12:00:00',
            'notes' => ['升级运行时'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->versionPath);
        parent::tearDown();
    }

    public function testFactoryBuildsExactAgentContractFromSharedInstance(): void
    {
        $factory = new AgentHeartbeatPayloadFactory(
            $this->versionPath,
            static fn(): array => ['php_version' => '8.2.30', 'os' => 'Linux'],
        );
        $instance = $this->instance();
        $instance['session_derivation_key'] = 'SESSION_DERIVATION_KEY_CANARY_DO_NOT_EXPORT';
        $instance['components'] = ['backend_php' => 2_000_000, 'cron' => 1, 'unknown' => 2_000_000];

        $payload = $factory->create($instance, 'backend_php', 2_000_000);

        self::assertSame([
            'instance_id', 'token', 'activation_secret',
            'app_version', 'environment', 'components',
        ], array_keys($payload));
        self::assertSame('1.2.3', $payload['app_version']['version']);
        self::assertSame('mbt_token', $payload['token']);
        self::assertSame('', $payload['activation_secret']);
        self::assertSame([['type' => 'backend_php', 'version' => '1.2.3']], $payload['components']);
        self::assertStringNotContainsString(
            'SESSION_DERIVATION_KEY_CANARY_DO_NOT_EXPORT',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    public function testActivationPayloadCarriesPersistedProofButNeverInventsIdentity(): void
    {
        $factory = new AgentHeartbeatPayloadFactory($this->versionPath, static fn(): array => []);
        $instance = $this->instance();
        $instance['token'] = '';
        $instance['activation_secret'] = 'activation-proof';

        $payload = $factory->create($instance, 'backend_php', 1000);

        self::assertSame($instance['instance_id'], $payload['instance_id']);
        self::assertSame('', $payload['token']);
        self::assertSame('activation-proof', $payload['activation_secret']);
    }

    public function testMalformedVersionAndInstanceContractsFailClosed(): void
    {
        $factory = new AgentHeartbeatPayloadFactory($this->versionPath, static fn(): array => []);
        $invalidDocuments = [
            '{',
            '{"version":"01.2.3"}',
            '{"version":"1.2.3","\\u0076ersion":"9.9.9"}',
            '{"version":"1.2.3","attacker":true}',
            '{"version":"1.2.3","notes":[1]}',
        ];
        foreach ($invalidDocuments as $raw) {
            file_put_contents($this->versionPath, $raw);
            try {
                $factory->create($this->instance(), 'backend_php', 1000);
                self::fail('invalid version was accepted: ' . $raw);
            } catch (RuntimeException $exception) {
                self::assertSame('AGENT_PAYLOAD_INVALID', $exception->getMessage());
            }
        }

        file_put_contents($this->versionPath, '{"version":"1.2.3"}');
        $instance = $this->instance();
        $instance['platform_base_url'] = 'https://legacy-platform.invalid';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AGENT_PAYLOAD_INVALID');
        $factory->create($instance, 'backend_php', 1000);
    }

    /** @return array<string, mixed> */
    private function instance(): array
    {
        return [
            'instance_id' => 'c6f83b5e-aadc-4a65-9c71-79a64aa22e58',
            'token' => 'mbt_token',
            'activation_secret' => '',
            'components' => [],
        ];
    }
}
