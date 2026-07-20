<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\admin\upgrade\PlatformReleaseCatalogService;
use app\service\install\AgentBinaryTrustValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlatformReleaseCatalogServiceTest extends TestCase
{
    private const BINARY = '/app/upgrade/bin/active/mallbase-agent';

    public function testRunsFixedAgentCatalogCommandAndPreservesFullPackageFilteringAndSorting(): void
    {
        $calls = [];
        $responseBody = $this->catalogJson([
            $this->release('1.1.0', [$this->package('1.0.0', 'full')], ['平台发布接入']),
            $this->release('1.4.0', [$this->package('1.0.0', 'patch')], ['仅供旧 Agent 的 patch']),
            $this->release('1.3.0', [$this->package('1.0.0', 'full', 2, 2)], ['布局不匹配']),
            $this->release('1.2.0', [
                $this->package('1.0.0', 'patch'),
                $this->package('1.0.0', 'full'),
            ], ['稳定性优化', '备份流程增强']),
        ]);
        $service = new PlatformReleaseCatalogService(
            executor: static function (array $command, string $stdin, int $timeoutMilliseconds) use (&$calls, $responseBody): array {
                $calls[] = [$command, $stdin, $timeoutMilliseconds];

                return [
                    'exit_code' => 0,
                    'stdout' => $responseBody . "\n",
                    'stderr' => '',
                    'timed_out' => false,
                ];
            },
            binaryPath: self::BINARY,
            timeoutMilliseconds: 10_000,
            clock: static fn(): int => 1000,
        );

        self::assertSame([
            'checked_at' => 1000,
            'current_version' => '1.0.0',
            'releases' => [
                [
                    'version' => '1.2.0',
                    'from_version' => '1.0.0',
                    'channel' => 'stable',
                    'summary' => '稳定性优化；备份流程增强',
                    'package_kind' => 'full',
                    'released_at' => '2026-07-12T03:04:05Z',
                ],
                [
                    'version' => '1.1.0',
                    'from_version' => '1.0.0',
                    'channel' => 'stable',
                    'summary' => '平台发布接入',
                    'package_kind' => 'full',
                    'released_at' => '2026-07-12T03:04:05Z',
                ],
            ],
        ], $service->getCatalog('1.0.0'));
        self::assertSame([[[self::BINARY, 'catalog'], '', 10_000]], $calls);
    }

    public function testReturnsAnEmptyCatalogWhenAgentReportsNoDirectFullTarget(): void
    {
        $requiresBootstrap = $this->package('1.0.0', 'full');
        $requiresBootstrap['required_bootstrap_version'] = '2.0.0';
        $service = $this->serviceReturning($this->catalogJson([
            $this->release('1.1.0', [$this->package('1.0.0', 'patch')], ['legacy']),
            $this->release('1.2.0', [$requiresBootstrap], ['需要 bootstrap']),
        ]));

        self::assertSame([], $service->getCatalog('1.0.0')['releases']);
    }

    public function testAcceptsAFullPackageWhenRequiredBootstrapVersionIsOmitted(): void
    {
        $package = $this->package('1.0.0', 'full');
        unset($package['required_bootstrap_version']);
        $service = $this->serviceReturning($this->catalogJson([
            $this->release('1.1.0', [$package], ['省略 bootstrap 要求']),
        ]));

        self::assertSame('1.1.0', $service->getCatalog('1.0.0')['releases'][0]['version']);
        self::assertSame('full', $service->getCatalog('1.0.0')['releases'][0]['package_kind']);
    }

    public function testFailsClosedForProcessAndOutputContractViolations(): void
    {
        $valid = $this->catalogJson([]) . "\n";
        $cases = [
            'timeout' => ['exit_code' => -1, 'stdout' => '', 'stderr' => '', 'timed_out' => true],
            'non-zero' => ['exit_code' => 1, 'stdout' => '', 'stderr' => "catalog failed: CATALOG_FAILED\n", 'timed_out' => false],
            'success stderr' => ['exit_code' => 0, 'stdout' => $valid, 'stderr' => 'secret-warning', 'timed_out' => false],
            'missing newline' => ['exit_code' => 0, 'stdout' => rtrim($valid, "\n"), 'stderr' => '', 'timed_out' => false],
            'extra newline' => ['exit_code' => 0, 'stdout' => $valid . "\n", 'stderr' => '', 'timed_out' => false],
            'multiple json' => ['exit_code' => 0, 'stdout' => "{}{}\n", 'stderr' => '', 'timed_out' => false],
            'oversized stdout' => ['exit_code' => 0, 'stdout' => str_repeat('x', 262_144) . "\n", 'stderr' => '', 'timed_out' => false],
            'invalid process' => ['exit_code' => '0', 'stdout' => $valid, 'stderr' => '', 'timed_out' => false],
        ];

        foreach ($cases as $name => $process) {
            try {
                (new PlatformReleaseCatalogService(
                    executor: static fn(): array => $process,
                    binaryPath: self::BINARY,
                ))->getCatalog('1.0.0');
                self::fail($name . ' was accepted');
            } catch (RuntimeException $exception) {
                self::assertSame('UPGRADE_CATALOG_UNAVAILABLE', $exception->getMessage(), $name);
                self::assertStringNotContainsString('secret-warning', $exception->getMessage(), $name);
            }
        }
    }

    public function testFailsClosedForInvalidPlatformDocuments(): void
    {
        $emptyData = substr($this->catalogJson([]), strlen('{"data":'), -1);
        foreach ([
            '{}',
            '{"data":{"app_code":"mallbase","delivery":"agent_authenticated","releases":null}}',
            '{"data":{"app_code":"mallbase","delivery":"agent_authenticated","releases":[]},"data":{}}',
            '{"data":' . $emptyData . ',"data":' . $emptyData . '}',
        ] as $body) {
            try {
                $this->serviceReturning($body)->getCatalog('1.0.0');
                self::fail('invalid platform release catalog was accepted');
            } catch (RuntimeException $exception) {
                self::assertSame('UPGRADE_CATALOG_UNAVAILABLE', $exception->getMessage());
            }
        }
    }

    public function testNativeProcessUsesTrustedArrayCommandWithEmptyStdin(): void
    {
        $root = sys_get_temp_dir() . '/mallbase-agent-catalog-' . bin2hex(random_bytes(8));
        $active = $root . '/bin/active';
        mkdir($active, 0750, true);
        $binary = $active . '/mallbase-agent';
        file_put_contents($binary, <<<'SH'
#!/bin/sh
[ "$#" -eq 1 ] && [ "$1" = catalog ] || exit 1
if IFS= read -r unexpected; then exit 1; fi
printf '%s\n' '{"data":{"app_code":"mallbase","delivery":"agent_authenticated","releases":[]}}'
SH
        );
        chmod($binary, 0755);
        chmod($active, 0750);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $validator = new AgentBinaryTrustValidator(
            $uid,
            $uid + 1,
            static fn(): bool => true,
            static fn(): bool => true,
            static fn(): bool => false,
        );

        try {
            $catalog = (new PlatformReleaseCatalogService(
                binaryPath: $binary,
                timeoutMilliseconds: 3000,
                trustValidator: $validator,
                clock: static fn(): int => 1000,
            ))->getCatalog('1.0.0');
            self::assertSame([], $catalog['releases']);
        } finally {
            chmod($binary, 0644);
            @unlink($binary);
            @rmdir($active);
            @rmdir(dirname($active));
            @rmdir($root);
        }
    }

    private function serviceReturning(string $body): PlatformReleaseCatalogService
    {
        return new PlatformReleaseCatalogService(
            executor: static fn(): array => [
                'exit_code' => 0,
                'stdout' => $body . "\n",
                'stderr' => '',
                'timed_out' => false,
            ],
            binaryPath: self::BINARY,
            clock: static fn(): int => 1000,
        );
    }

    /** @param list<array<string,mixed>> $releases */
    private function catalogJson(array $releases): string
    {
        return json_encode(['data' => [
            'app_code' => 'mallbase',
            'delivery' => 'agent_authenticated',
            'releases' => $releases,
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param list<array<string,mixed>> $packages @param list<string> $notes */
    private function release(string $version, array $packages, array $notes): array
    {
        return [
            'version' => $version,
            'channel' => 'stable',
            'min_agent_version' => '1.0.0',
            'release_notes' => $notes,
            'released_at' => '2026-07-12T03:04:05Z',
            'packages' => $packages,
        ];
    }

    /** @return array<string, int|string|null> */
    private function package(string $fromVersion, string $kind, int $fromLayout = 1, int $toLayout = 1): array
    {
        return [
            'from_version' => $fromVersion,
            'package_kind' => $kind,
            'from_storage_layout_version' => $fromLayout,
            'to_storage_layout_version' => $toLayout,
            'required_bootstrap_version' => null,
            'signing_key_id' => 'release-key-v1',
            'package_sha256' => str_repeat('a', 64),
            'package_size_bytes' => 4096,
        ];
    }
}
