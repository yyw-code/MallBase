<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use app\middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;

final class CorsMiddlewareTest extends TestCase
{
    public function testJwtCorsMayReflectOriginButNeverEnablesCredentialedCrossOriginCookies(): void
    {
        $request = (new Request())
            ->setMethod('GET')
            ->withHeader(['Origin' => 'https://attacker.example']);

        $response = (new CorsMiddleware())->handle(
            $request,
            static fn(): Response => Response::create('ok', 'html', 200),
        );
        $headers = array_change_key_case($response->getHeader(), CASE_LOWER);

        $this->assertSame('https://attacker.example', $headers['access-control-allow-origin'] ?? null);
        $this->assertArrayNotHasKey('access-control-allow-credentials', $headers);
        $this->assertSame('Origin', $headers['vary'] ?? null);
    }

    public function testSameOriginRequestWithoutOriginDoesNotEmitCorsHeaders(): void
    {
        $response = (new CorsMiddleware())->handle(
            (new Request())->setMethod('GET'),
            static fn(): Response => Response::create('ok', 'html', 200),
        );
        $headers = array_change_key_case($response->getHeader(), CASE_LOWER);

        $this->assertArrayNotHasKey('access-control-allow-origin', $headers);
        $this->assertArrayNotHasKey('access-control-allow-credentials', $headers);
    }
}
