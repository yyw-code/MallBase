<?php
declare(strict_types=1);

namespace app\service\distribution;

use mall_base\exception\BusinessException;

/**
 * 分销配置服务
 */
class DistributionConfigService
{
    public const GROUP_CODE = 'DistributionConfig';

    private const DEFAULTS = [
        'distribution_enabled' => '1',
        'distribution_level_depth' => '2',
        'self_purchase_enabled' => '0',
        'settlement_days' => '7',
        'min_withdraw_cents' => '10000',
        'global_first_rate' => '5.00',
        'global_second_rate' => '2.00',
    ];

    /**
     * @return array<string,mixed>
     */
    public function settings(): array
    {
        $map = self::DEFAULTS;
        foreach (array_keys(self::DEFAULTS) as $code) {
            $map[$code] = (string) getSystemSetting($code, self::DEFAULTS[$code]);
        }

        return [
            'distribution_enabled' => $this->boolValue($map['distribution_enabled']),
            'distribution_level_depth' => 2,
            'self_purchase_enabled' => $this->boolValue($map['self_purchase_enabled']),
            'settlement_days' => max(0, min(365, (int) $map['settlement_days'])),
            'min_withdraw_cents' => max(0, (int) $map['min_withdraw_cents']),
            'global_first_rate' => $this->normalizeRate($map['global_first_rate']),
            'global_second_rate' => $this->normalizeRate($map['global_second_rate']),
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings()['distribution_enabled'];
    }

    public function assertRatePair(string $firstRate, string $secondRate): void
    {
        if (((float) $firstRate + (float) $secondRate) > 100) {
            throw new BusinessException('一级和二级佣金比例合计不能超过100%');
        }
    }

    public function normalizeRate(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new BusinessException('佣金比例格式不合法');
        }
        $rate = (float) $value;
        if ($rate < 0 || $rate > 100) {
            throw new BusinessException('佣金比例必须在0到100之间');
        }
        return number_format($rate, 2, '.', '');
    }

    private function boolValue(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

}
