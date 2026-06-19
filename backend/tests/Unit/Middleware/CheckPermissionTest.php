<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use app\middleware\admin\CheckPermission;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\route\Rule;

final class CheckPermissionTest extends TestCase
{
    public function testAuthFalseRouteDoesNotRequirePermissionCode(): void
    {
        $middleware = new class extends CheckPermission {
            public function exposePermissionCode(Request $request): ?string
            {
                return $this->getPermissionCode($request);
            }
        };

        $rule = new class extends Rule {
            public function check(Request $request, string $url, bool $completeMatch = false)
            {
                return false;
            }
        };
        $rule->name('SystemLoginAdminInfo')->option(['_auth' => false]);

        $request = new Request();
        $request->setRule($rule);

        $this->assertNull($middleware->exposePermissionCode($request));
    }

    public function testAuthTrueRouteReturnsPermissionCode(): void
    {
        $middleware = new class extends CheckPermission {
            public function exposePermissionCode(Request $request): ?string
            {
                return $this->getPermissionCode($request);
            }
        };

        $rule = new class extends Rule {
            public function check(Request $request, string $url, bool $completeMatch = false)
            {
                return false;
            }
        };
        $rule->name('SystemRoleList')->option(['_auth' => true]);

        $request = new Request();
        $request->setRule($rule);

        $this->assertSame('SystemRoleList', $middleware->exposePermissionCode($request));
    }
}
