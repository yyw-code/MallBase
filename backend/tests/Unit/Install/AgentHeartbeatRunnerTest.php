<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\AgentBinaryTrustValidator;
use app\service\install\AgentHeartbeatRunner;
use PHPUnit\Framework\TestCase;

final class AgentHeartbeatRunnerTest extends TestCase
{
    public function testRunnerReturnsDecodedHeartbeatResult(): void
    {
        $executor = static function (array $command, string $stdin, int $timeoutMilliseconds): array {
            self::assertSame(['/app/upgrade/bin/mallbase-agent-linux-amd64', 'heartbeat'], $command);
            self::assertSame(5000, $timeoutMilliseconds);
            self::assertSame('https://platform.gosowong.cn', json_decode($stdin, true, 512, JSON_THROW_ON_ERROR)['platform_base_url']);

            return [
                'exit_code' => 0,
                'stdout' => '{"ok":true,"instance_id":"c6f83b5e-aadc-4a65-9c71-79a64aa22e58","token":"secret","next_report_after_seconds":86400}',
                'stderr' => '',
            ];
        };

        $result = (new AgentHeartbeatRunner(
            $executor,
            '/app/upgrade/bin/mallbase-agent-linux-amd64',
            5000,
        ))->run($this->payload());

        self::assertTrue($result->ok);
        self::assertSame('c6f83b5e-aadc-4a65-9c71-79a64aa22e58', $result->instanceId);
        self::assertSame('secret', $result->token);
        self::assertSame(86400, $result->nextReportAfterSeconds);
        self::assertSame('', $result->error);
    }

    public function testRunnerMapsServeDelegationToSuccessfulResult(): void
    {
        $runner = new AgentHeartbeatRunner(
            static fn(): array => [
                'exit_code' => 0,
                'stdout' => '{"ok":true,"skipped":"serve_active"}',
                'stderr' => '',
            ],
            '/app/upgrade/bin/mallbase-agent-linux-amd64',
            5000,
        );

        $result = $runner->run($this->payload());

        self::assertTrue($result->ok);
        self::assertSame('serve_active', $result->skipped);
    }

    public function testRunnerFailsClosedForProcessAndOutputFailuresWithoutLeakingSecrets(): void
    {
        $secret = 'instance-secret-token';
        $cases = [
            'timeout' => ['timed_out' => true, 'exit_code' => -1, 'stdout' => '', 'stderr' => $secret],
            'non zero' => ['exit_code' => 1, 'stdout' => '', 'stderr' => 'heartbeat failed: HEARTBEAT_FAILED ' . $secret],
            'invalid json' => ['exit_code' => 0, 'stdout' => '{', 'stderr' => ''],
            'unknown json field' => ['exit_code' => 0, 'stdout' => '{"ok":true,"unknown":1}', 'stderr' => ''],
            'multiple json values' => ['exit_code' => 0, 'stdout' => '{"ok":true}{}', 'stderr' => ''],
            'oversized stdout' => ['exit_code' => 0, 'stdout' => str_repeat('x', (1 << 20) + 1), 'stderr' => ''],
            'oversized stderr' => ['exit_code' => 1, 'stdout' => '', 'stderr' => str_repeat('x', (64 << 10) + 1) . $secret],
        ];

        foreach ($cases as $name => $response) {
            $runner = new AgentHeartbeatRunner(
                static fn(): array => $response,
                '/app/upgrade/bin/mallbase-agent-linux-amd64',
                5000,
            );
            $result = $runner->run($this->payload($secret));

            self::assertFalse($result->ok, $name);
            self::assertNotSame('', $result->error, $name);
            self::assertStringNotContainsString($secret, $result->error, $name);
        }
    }

    public function testRunnerAcceptsOnlyExactBoundedResultSchema(): void
    {
        $invalid = [
            '{"ok":"true"}',
            '{"ok":false}',
            '{"ok":true,"skipped":"other"}',
            '{"ok":true,"instance_id":"not-a-uuid"}',
            '{"ok":true,"token":"bad token"}',
            '{"ok":true,"next_report_after_seconds":0}',
            '{"ok":true,"instance_id":"c6f83b5e-aadc-4a65-9c71-79a64aa22e58","token":"secret","next_report_after_seconds":4102444801}',
        ];

        foreach ($invalid as $stdout) {
            $runner = new AgentHeartbeatRunner(
                static fn(): array => ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => ''],
                '/app/upgrade/bin/mallbase-agent-linux-amd64',
                5000,
            );
            self::assertFalse($runner->run($this->payload())->ok, $stdout);
        }
    }

    public function testNativeProcessPathUsesArrayCommandAndReturnsRealExitCode(): void
    {
        $root = sys_get_temp_dir() . '/mallbase-agent-runner-' . bin2hex(random_bytes(8));
        mkdir($root, 0755);
        $bin = $root . '/bin';
        mkdir($bin, 0755);
        $binary = $bin . '/mallbase-agent-linux-amd64';
        $checksums = $bin . '/checksums.sha256';
        file_put_contents($binary, "#!/bin/sh\nprintf '%s\\n' '{\"ok\":true,\"instance_id\":\"c6f83b5e-aadc-4a65-9c71-79a64aa22e58\"}'\n");
        chmod($binary, 0555);
        file_put_contents($checksums, hash_file('sha256', $binary) . "  mallbase-agent-linux-amd64\n");
        chmod($checksums, 0444);
        chmod($bin, 0555);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $validator = new AgentBinaryTrustValidator(
            $uid,
            $uid + 1,
            static fn(): bool => true,
            static fn(): bool => true,
            static fn(): bool => false,
        );

        try {
            $result = (new AgentHeartbeatRunner(null, $binary, 5000, $validator))->run($this->payload());
            self::assertTrue($result->ok, $result->error);
            self::assertSame('c6f83b5e-aadc-4a65-9c71-79a64aa22e58', $result->instanceId);
        } finally {
            chmod($bin, 0755);
            chmod($binary, 0644);
            chmod($checksums, 0644);
            @unlink($binary);
            @unlink($checksums);
            @rmdir($bin);
            @rmdir($root);
        }
    }

    public function testNativeProcessDrainsOutputWhileWritingBoundedInput(): void
    {
        $root = sys_get_temp_dir() . '/mallbase-agent-duplex-' . bin2hex(random_bytes(8));
        mkdir($root, 0755);
        $bin = $root . '/bin';
        mkdir($bin, 0755);
        $binary = $bin . '/mallbase-agent-linux-amd64';
        $checksums = $bin . '/checksums.sha256';
        file_put_contents($binary, "#!/bin/sh\nhead -c 131072 /dev/zero | tr '\\000' x\ncat >/dev/null\nprintf '%s\\n' '{\"ok\":true}'\n");
        chmod($binary, 0555);
        file_put_contents($checksums, hash_file('sha256', $binary) . "  mallbase-agent-linux-amd64\n");
        chmod($checksums, 0444);
        chmod($bin, 0555);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $validator = new AgentBinaryTrustValidator(
            $uid,
            $uid + 1,
            static fn(): bool => true,
            static fn(): bool => true,
            static fn(): bool => false,
        );
        $payload = $this->payload();
        $payload['environment']['padding'] = str_repeat('p', 48 * 1024);

        try {
            $started = hrtime(true);
            $result = (new AgentHeartbeatRunner(null, $binary, 3000, $validator))->run($payload);
            $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

            self::assertFalse($result->ok);
            self::assertSame('AGENT_OUTPUT_INVALID', $result->error);
            self::assertLessThan(2500, $elapsedMilliseconds, 'duplex pipes must progress under one deadline');
        } finally {
            chmod($bin, 0755);
            chmod($binary, 0644);
            chmod($checksums, 0644);
            @unlink($binary);
            @unlink($checksums);
            @rmdir($bin);
            @rmdir($root);
        }
    }

    /** @return array<string, mixed> */
    private function payload(string $token = 'secret'): array
    {
        return [
            'platform_base_url' => 'https://platform.gosowong.cn',
            'instance_id' => 'c6f83b5e-aadc-4a65-9c71-79a64aa22e58',
            'token' => $token,
            'activation_secret' => '',
            'app_version' => ['version' => '1.0.0'],
            'environment' => ['os' => 'Linux'],
            'components' => [],
        ];
    }
}
