<?php
declare(strict_types=1);

namespace app\service\marketing;

use mall_base\base\BaseModel;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 积分功能开关服务
 *
 * @extends BaseService<BaseModel>
 */
class PointsFeatureService extends BaseService
{
    protected string $modelClass = BaseModel::class;

    public function isEnabled(): bool
    {
        return $this->settingBool('points_enabled', true);
    }

    public function isRewardEnabled(): bool
    {
        return $this->isEnabled() && $this->settingBool('points_reward_enabled', true);
    }

    public function isDeductionEnabled(): bool
    {
        return $this->isEnabled() && $this->settingBool('points_deduction_enabled', true);
    }

    public function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new BusinessException('积分功能未开启');
        }
    }

    private function settingBool(string $code, bool $default): bool
    {
        if (!function_exists('getSystemSetting')) {
            return $default;
        }

        $value = (string) getSystemSetting($code, $default ? '1' : '0');
        return in_array($value, ['1', 'true', 'on'], true);
    }
}
