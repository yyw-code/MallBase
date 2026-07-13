<?php

declare(strict_types=1);

namespace app\service\upgrade;

use think\Response;

final readonly class UpgradeMaintenanceResponse
{
    public function make(
        UpgradeGateSnapshot $snapshot,
        string $view = 'maintenance',
        ?string $cause = null,
    ): Response {
        $data = [
            'reason' => 'SYSTEM_MAINTENANCE',
            'state' => $snapshot->state->value,
            'view' => $view,
            'retry_after' => 5,
        ];
        if ($cause !== null) {
            $data['cause'] = $cause;
        }

        return json([
            'code' => 503,
            'message' => '系统正在维护',
            'data' => $data,
            'timestamp' => time(),
        ], 503);
    }
}
