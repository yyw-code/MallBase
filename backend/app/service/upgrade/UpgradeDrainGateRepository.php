<?php

declare(strict_types=1);

namespace app\service\upgrade;

/**
 * 只有排空协调器完成 paused 二次检查后才能调用的专用状态边界。
 */
interface UpgradeDrainGateRepository
{
    public function enterBackingUpAfterDrain(int $expectedRevision, string $jobId): UpgradeGateSnapshot;
}
