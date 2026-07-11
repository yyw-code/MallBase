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
    public const OPEN_MODE_MANUAL = 'manual';
    public const OPEN_MODE_APPLY = 'apply';
    public const OPEN_MODE_EVERYONE = 'everyone';
    public const OPEN_MODE_AMOUNT = 'amount';
    public const INVITE_REWARD_TRIGGER_BIND = 'bind';
    public const INVITE_REWARD_TRIGGER_FIRST_ORDER = 'first_order';

    private const DEFAULTS = [
        'distribution_enabled' => '1',
        'distributor_open_mode' => self::OPEN_MODE_MANUAL,
        'auto_open_level_id' => '1',
        'second_level_enabled' => '0',
        'self_purchase_enabled' => '0',
        'relation_valid_days' => '0',
        'settlement_days' => '7',
        'min_withdraw_cents' => '10000',
        'global_first_rate' => '5.00',
        'global_second_rate' => '0.00',
        'amount_open_threshold_cents' => '0',
        'invite_reward_enabled' => '0',
        'invite_reward_trigger' => self::INVITE_REWARD_TRIGGER_FIRST_ORDER,
        'invite_reward_amount_cents' => '0',
        'attribution_enabled' => '1',
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
            'distributor_open_mode' => $this->normalizeOpenMode($map['distributor_open_mode']),
            'auto_open_level_id' => max(1, (int) $map['auto_open_level_id']),
            'second_level_enabled' => $this->boolValue($map['second_level_enabled']),
            'self_purchase_enabled' => $this->boolValue($map['self_purchase_enabled']),
            'relation_valid_days' => max(0, min(3650, (int) $map['relation_valid_days'])),
            'settlement_days' => max(0, min(365, (int) $map['settlement_days'])),
            'min_withdraw_cents' => max(0, (int) $map['min_withdraw_cents']),
            'global_first_rate' => $this->normalizeRate($map['global_first_rate']),
            'global_second_rate' => $this->normalizeRate($map['global_second_rate']),
            'amount_open_threshold_cents' => max(0, (int) $map['amount_open_threshold_cents']),
            'invite_reward_enabled' => $this->boolValue($map['invite_reward_enabled']),
            'invite_reward_trigger' => $this->normalizeInviteRewardTrigger($map['invite_reward_trigger']),
            'invite_reward_amount_cents' => max(0, (int) $map['invite_reward_amount_cents']),
            'attribution_enabled' => $this->boolValue($map['attribution_enabled']),
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

    public function normalizeSwitch(string $value): string
    {
        return $this->boolValue($value) ? '1' : '0';
    }

    public function normalizeOpenMode(string $value): string
    {
        $value = trim($value);
        if (!in_array($value, [
            self::OPEN_MODE_MANUAL,
            self::OPEN_MODE_APPLY,
            self::OPEN_MODE_EVERYONE,
            self::OPEN_MODE_AMOUNT,
        ], true)) {
            throw new BusinessException('分销员开通方式不合法');
        }
        return $value;
    }

    public function normalizeInviteRewardTrigger(string $value): string
    {
        $value = trim($value);
        if (!in_array($value, [
            self::INVITE_REWARD_TRIGGER_BIND,
            self::INVITE_REWARD_TRIGGER_FIRST_ORDER,
        ], true)) {
            throw new BusinessException('固定邀请奖励触发方式不合法');
        }
        return $value;
    }

    public function normalizeCents(string $value, string $message = '金额格式不合法'): string
    {
        return $this->normalizeUnsignedInt($value, $message);
    }

    public function normalizeUnsignedInt(string $value, string $message = '整数格式不合法'): string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^\d+$/', $value)) {
            throw new BusinessException($message);
        }
        return (string) max(0, (int) $value);
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
