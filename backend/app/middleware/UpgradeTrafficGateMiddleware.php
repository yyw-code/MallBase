<?php

declare(strict_types=1);

namespace app\middleware;

use app\service\upgrade\ExternalCallbackMaintenancePolicy;
use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeMaintenanceResponse;
use app\service\upgrade\UpgradeRuntimeContext;
use app\service\upgrade\UpgradeRuntimeFailureLatch;
use app\service\upgrade\UpgradeState;
use Closure;
use think\Request;
use think\Response;

final readonly class UpgradeTrafficGateMiddleware
{
    public function __construct(
        private ?UpgradeGateRepository $gate = null,
        private ?UpgradeActivityTracker $activity = null,
        private ?UpgradeRuntimeContext $runtime = null,
        private ?UpgradeMaintenanceResponse $maintenance = null,
        private ?ExternalCallbackMaintenancePolicy $callbacks = null,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('upgrade.enabled', false)) {
            return $next($request);
        }

        $path = trim($request->pathinfo(), '/');
        if ($request->isOptions() || $this->isHealthPath($path)) {
            return $next($request);
        }
        $this->ensureRegisteredRuntimeIsSafe();
        if ($this->isUpgradePath($path)) {
            return $next($request);
        }

        $gateRepository = $this->gate ?? app()->make(UpgradeGateRepository::class);
        $activity = $this->activity ?? app()->make(UpgradeActivityTracker::class);
        $runtime = $this->runtime ?? app()->make(UpgradeRuntimeContext::class);
        $maintenance = $this->maintenance ?? app()->make(UpgradeMaintenanceResponse::class);
        $callbacks = $this->callbacks ?? app()->make(ExternalCallbackMaintenancePolicy::class);
        $snapshot = $gateRepository->snapshot();
        if ($callbacks->matches($request)) {
            return $callbacks->handle($request, $next, $snapshot);
        }
        if ($path === 'admin/api' || str_starts_with($path, 'admin/api/')) {
            return $next($request);
        }

        $owner = $runtime->owner('http');
        if (!$snapshot->acceptsRuntime($owner->identity)
            || $owner->observedDeploymentEpoch !== $snapshot->deploymentEpoch) {
            return $maintenance->make($snapshot, 'maintenance', 'RUNTIME_IDENTITY_FENCED');
        }
        if (!in_array($snapshot->state, [
            UpgradeState::Normal,
            UpgradeState::Preparing,
            UpgradeState::ReadyToDrain,
        ], true)) {
            return $maintenance->make($snapshot);
        }

        $lease = $activity->tryBeginHttp($this->requestId($request), $owner);
        if ($lease === null) {
            return $maintenance->make($gateRepository->snapshot());
        }
        try {
            return $next($request);
        } finally {
            $lease->release();
        }
    }

    private function isUpgradePath(string $path): bool
    {
        return $path === 'upgrade' || str_starts_with($path, 'upgrade/');
    }

    private function isHealthPath(string $path): bool
    {
        return $path === 'api/health' || $path === 'health';
    }

    private function ensureRegisteredRuntimeIsSafe(): void
    {
        if (defined('MALLBASE_UPGRADE_WORKER_REGISTERED')
            || ($this->gate !== null && $this->activity !== null && $this->runtime !== null)) {
            return;
        }
        /** @var UpgradeRuntimeFailureLatch $latch */
        $latch = app()->make(UpgradeRuntimeFailureLatch::class);
        $latch->taintRoles(['http']);
        defined('MALLBASE_AUTOMATIC_UPGRADE_DISABLED')
            || define('MALLBASE_AUTOMATIC_UPGRADE_DISABLED', true);
    }

    private function requestId(Request $request): string
    {
        $requestId = trim((string) $request->header('X-Request-Id', ''));

        return $requestId !== '' && preg_match('/^[0-9A-Za-z_.:\/-]{1,255}$/D', $requestId) === 1
            ? $requestId
            : 'http-' . bin2hex(random_bytes(16));
    }
}
