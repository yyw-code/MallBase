<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use mall_base\service\JwtCacheService;
use PHPUnit\Framework\TestCase;

final class JwtCacheServiceKeyTest extends TestCase
{
    public function testRefreshTokenCacheKeySeparatesAdminAndClient(): void
    {
        $service = new JwtCacheService();

        $this->assertSame('jwt:refresh:admin:1', $service->buildCacheKey(1, JwtCacheService::GUARD_ADMIN));
        $this->assertSame('jwt:refresh:client:1', $service->buildCacheKey(1, JwtCacheService::GUARD_CLIENT));
    }

    public function testClientRefreshTokenCacheKeyIncludesSessionId(): void
    {
        $service = new JwtCacheService();

        $this->assertSame(
            'jwt:refresh:client:88:session-a',
            $service->buildCacheKey(88, JwtCacheService::GUARD_CLIENT, 'session-a')
        );
    }

    public function testUnknownGuardIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new JwtCacheService())->buildCacheKey(1, 'unknown');
    }
}
