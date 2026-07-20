<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use app\middleware\admin\RequestLockMiddleware;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;
use think\route\Rule;

final class RequestLockMiddlewareTest extends TestCase
{
    public function testRouteMayDelegateIdempotencyToItsOwnDurableProtocol(): void
    {
        $rule = new class extends Rule {
            public function check(Request $request, string $url, bool $completeMatch = false)
            {
                return false;
            }
        };
        $rule->option(['_request_lock' => false]);
        $request = (new Request())->setMethod('POST')->setPathinfo('admin/api/system/upgrade/jobs');
        $request->setRule($rule);
        $called = 0;

        $response = (new class extends RequestLockMiddleware {
            protected function acquireLock(string $lockKey): bool
            {
                throw new \LogicException('generic request lock must not run');
            }
        })->handle($request, static function () use (&$called): Response {
            $called++;

            return Response::create('ok', 'html', 200);
        });

        $this->assertSame(1, $called);
        $this->assertSame(200, $response->getCode());
    }
}
