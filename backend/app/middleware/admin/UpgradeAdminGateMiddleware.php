<?php

declare(strict_types=1);

namespace app\middleware\admin;

use app\service\upgrade\SimpleUpgradeGate;
use Closure;
use think\Request;
use think\Response;
use Throwable;

final readonly class UpgradeAdminGateMiddleware
{
    public function __construct(private ?SimpleUpgradeGate $simpleGate = null)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->simpleGate === null) {
            return $next($request);
        }

        $path = trim($request->pathinfo(), '/');
        $method = strtoupper($request->method());
        if (($method === 'GET' && in_array($path, [
            'admin/api/system/upgrade/overview',
            'admin/api/system/upgrade/releases',
            'admin/api/system/upgrade/records',
        ], true))
            || ($method === 'POST' && $path === 'admin/api/system/upgrade/jobs')) {
            return $next($request);
        }

        try {
            $lease = $this->simpleGate->tryEnter();
        } catch (Throwable) {
            return $this->maintenance('unavailable');
        }
        if ($lease === null) {
            return $this->maintenance($this->state());
        }
        try {
            return $next($request);
        } finally {
            $lease->release();
        }
    }

    private function maintenance(string $state): Response
    {
        return Response::create([
            'code' => 503,
            'msg' => '系统升级维护中',
            'data' => [
                'reason' => 'SYSTEM_MAINTENANCE',
                'state' => $state,
            ],
        ], 'json', 503)->header([
            'Cache-Control' => 'no-store',
            'Retry-After' => '5',
        ]);
    }

    private function state(): string
    {
        try {
            return $this->simpleGate?->state() ?? 'unavailable';
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
