<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\admin\upgrade\PlatformReleaseCatalogService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlatformReleaseCatalogServiceTest extends TestCase
{
    public function testListsPublishedDirectTargetsWithoutAgentSession(): void
    {
        $requestedUrl = '';
        $responseBody = json_encode([
            'data' => [
                'app_code' => 'mallbase',
                'delivery' => 'agent_authenticated',
                'releases' => [
                    [
                        'version' => '1.2.0',
                        'channel' => 'stable',
                        'min_agent_version' => '9.0.0',
                        'release_notes' => ['稳定性优化', '备份流程增强'],
                        'released_at' => '2026-07-12T03:04:05Z',
                        'packages' => [
                            $this->package('1.0.0', 'full'),
                            $this->package('1.0.0', 'patch'),
                        ],
                    ],
                    [
                        'version' => '1.1.0',
                        'channel' => 'stable',
                        'min_agent_version' => '1.0.0',
                        'release_notes' => ['平台发布接入'],
                        'released_at' => '2026-06-28T03:04:05Z',
                        'packages' => [$this->package('1.0.0', 'full')],
                    ],
                    [
                        'version' => '1.3.0',
                        'channel' => 'stable',
                        'min_agent_version' => '1.0.0',
                        'release_notes' => ['不是当前版本的直达包'],
                        'released_at' => '2026-07-13T03:04:05Z',
                        'packages' => [$this->package('1.2.0', 'patch')],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $service = new PlatformReleaseCatalogService(
            platformOrigin: 'https://platform.example.test',
            requester: static function (string $url) use (&$requestedUrl, $responseBody): array {
                $requestedUrl = $url;

                return ['status' => 200, 'body' => $responseBody];
            },
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
                    'package_kind' => 'patch',
                    'released_at' => '2026-07-12T03:04:05Z',
                ],
                [
                    'version' => '1.1.0',
                    'from_version' => '1.0.0',
                    'channel' => 'stable',
                    'summary' => '平台发布接入',
                    'package_kind' => 'full',
                    'released_at' => '2026-06-28T03:04:05Z',
                ],
            ],
        ], $service->getCatalog('1.0.0'));
        self::assertSame(
            'https://platform.example.test/api/v1/releases?app_code=mallbase',
            $requestedUrl,
        );
    }

    public function testReturnsAnEmptyCatalogWhenPlatformHasNoDirectTarget(): void
    {
        $service = new PlatformReleaseCatalogService(
            platformOrigin: 'https://platform.example.test',
            requester: static fn(): array => ['status' => 200, 'body' => json_encode([
                'data' => [
                    'app_code' => 'mallbase',
                    'delivery' => 'agent_authenticated',
                    'releases' => [],
                ],
            ], JSON_THROW_ON_ERROR)],
            clock: static fn(): int => 1000,
        );

        self::assertSame([], $service->getCatalog('1.0.0')['releases']);
    }

    public function testFailsClosedForPlatformErrorsAndInvalidDocuments(): void
    {
        foreach ([
            ['status' => 503, 'body' => '{}'],
            ['status' => 200, 'body' => '{}'],
            ['status' => 200, 'body' => '{"data":{"app_code":"mallbase","delivery":"agent_authenticated","releases":null}}'],
        ] as $response) {
            $service = new PlatformReleaseCatalogService(
                platformOrigin: 'https://platform.example.test',
                requester: static fn(): array => $response,
            );
            try {
                $service->getCatalog('1.0.0');
                self::fail('invalid platform release catalog was accepted');
            } catch (RuntimeException $exception) {
                self::assertSame('UPGRADE_CATALOG_UNAVAILABLE', $exception->getMessage());
            }
        }
    }

    /** @return array<string, int|string|null> */
    private function package(string $fromVersion, string $kind): array
    {
        return [
            'from_version' => $fromVersion,
            'package_kind' => $kind,
            'from_storage_layout_version' => 1,
            'to_storage_layout_version' => 1,
            'required_bootstrap_version' => null,
            'signing_key_id' => 'release-key-v1',
            'package_sha256' => str_repeat('a', 64),
            'package_size_bytes' => 4096,
        ];
    }
}
