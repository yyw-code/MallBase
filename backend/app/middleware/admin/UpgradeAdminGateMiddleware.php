<?php

declare(strict_types=1);

namespace app\middleware\admin;

use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeMaintenanceResponse;
use app\service\upgrade\UpgradeRuntimeContext;
use app\service\upgrade\UpgradeState;
use Closure;
use think\Request;
use think\Response;

final readonly class UpgradeAdminGateMiddleware
{
    public function __construct(
        private ?UpgradeGateRepository $gate = null,
        private ?UpgradeActivityTracker $activity = null,
        private ?UpgradeRuntimeContext $runtime = null,
        private ?UpgradeMaintenanceResponse $maintenance = null,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('upgrade.enabled', false)) {
            return $next($request);
        }

        $gateRepository = $this->gate ?? app()->make(UpgradeGateRepository::class);
        $activity = $this->activity ?? app()->make(UpgradeActivityTracker::class);
        $runtime = $this->runtime ?? app()->make(UpgradeRuntimeContext::class);
        $maintenance = $this->maintenance ?? app()->make(UpgradeMaintenanceResponse::class);
        $snapshot = $gateRepository->snapshot();
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

        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId === '' || preg_match('/^[0-9A-Za-z_.:\/-]{1,255}$/D', $requestId) !== 1) {
            $requestId = 'admin-' . bin2hex(random_bytes(16));
        }
        $lease = $activity->tryBeginHttp($requestId, $owner);
        if ($lease === null) {
            return $maintenance->make($gateRepository->snapshot());
        }
        try {
            return $next($request);
        } finally {
            $lease->release();
        }
    }
}
