<?php

declare(strict_types=1);

namespace app\middleware;

use app\service\upgrade\SimpleUpgradeGate;
use Closure;
use think\Request;
use think\Response;
use Throwable;

final readonly class UpgradeTrafficGateMiddleware
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
        if ($request->isOptions() || $this->isHealthPath($path)
            || $path === 'admin/api' || str_starts_with($path, 'admin/api/')) {
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

    private function isHealthPath(string $path): bool
    {
        return $path === 'api/health' || $path === 'health';
    }
}
