<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use think\Request;
use think\Response;

final readonly class ExternalCallbackMaintenancePolicy
{
    private const PATHS = [
        'api/notify/wechat/pay',
        'api/notify/wechat/refund',
    ];

    public function __construct(
        private UpgradeActivityTracker $activity,
        private UpgradeRuntimeContext $runtime,
        private UpgradeMaintenanceResponse $maintenance,
    ) {
    }

    public function matches(Request $request): bool
    {
        return $request->isPost() && in_array(trim($request->pathinfo(), '/'), self::PATHS, true);
    }

    public function handle(Request $request, Closure $next, UpgradeGateSnapshot $gate): Response
    {
        $owner = $this->runtime->owner('http');
        if (!$gate->acceptsRuntime($owner->identity)
            || $owner->observedDeploymentEpoch !== $gate->deploymentEpoch) {
            return $this->maintenance->make($gate, 'maintenance', 'RUNTIME_IDENTITY_FENCED');
        }
        if (!in_array($gate->state, [
            UpgradeState::Normal,
            UpgradeState::Preparing,
            UpgradeState::ReadyToDrain,
            UpgradeState::Draining,
            UpgradeState::Reconciling,
        ], true)) {
            return json([
                'code' => 'FAIL',
                'message' => '系统维护中，请稍后重试',
            ], 500);
        }

        $requestId = trim((string) $request->header('Wechatpay-Request-Id', ''));
        if ($requestId === '' || preg_match('/^[0-9A-Za-z_.:\/-]{1,255}$/D', $requestId) !== 1) {
            $requestId = 'wechat-' . bin2hex(random_bytes(16));
        }
        $lease = $this->activity->tryBeginExternalCallback($requestId, $owner);
        if ($lease === null) {
            return json([
                'code' => 'FAIL',
                'message' => '系统维护中，请稍后重试',
            ], 500);
        }
        try {
            return $next($request);
        } finally {
            $lease->release();
        }
    }
}
