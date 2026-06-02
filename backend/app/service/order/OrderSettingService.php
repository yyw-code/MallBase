<?php

declare(strict_types=1);

namespace app\service\order;

/**
 * 订单与售后配置读取服务
 *
 * 所有值均来自 mb_setting；这里仅做默认值、类型收敛和边界保护。
 */
class OrderSettingService
{
    private const DEFAULT_PENDING_PAY_TIMEOUT_MINUTES = 30;
    private const DEFAULT_AUTO_RECEIVE_DAYS = 7;
    private const DEFAULT_AFTER_SALE_DAYS = 0;

    /**
     * @return array<int, array{value:string, label:string}>
     */
    public function refundReasonOptions(): array
    {
        $value = getSystemSetting('refund_reason_options', '');
        if (is_string($value) && trim($value) !== '') {
            $options = $this->normalizeRefundReasonOptions($value);
            if ($options !== []) {
                return $options;
            }
        }

        return [
            ['value' => 'MISTAKEN_ORDER', 'label' => '订单拍错'],
            ['value' => 'QUALITY_ISSUE', 'label' => '商品质量问题'],
            ['value' => 'NO_LONGER_WANTED', 'label' => '不想要了'],
            ['value' => 'OTHER', 'label' => '其他'],
        ];
    }

    /**
     * @return array<int, array{value:string, label:string}>
     */
    public function refundRejectReasonOptions(): array
    {
        $value = getSystemSetting('refund_reject_reason_options', '');
        if (is_string($value) && trim($value) !== '') {
            $options = $this->normalizeRefundReasonOptions($value);
            if ($options !== []) {
                return $options;
            }
        }

        return [
            ['value' => '商品已签收，不符合退款条件', 'label' => '商品已签收，不符合退款条件'],
            ['value' => '买家申请理由不成立', 'label' => '买家申请理由不成立'],
            ['value' => '已超过售后期限', 'label' => '已超过售后期限'],
            ['value' => '需提供相关凭证后重新申请', 'label' => '需提供相关凭证后重新申请'],
        ];
    }

    public function refundReasonText(string $value): string
    {
        foreach ($this->refundReasonOptions() as $option) {
            if ($option['value'] === $value) {
                return $option['label'];
            }
        }
        return $value !== '' ? $value : '未知';
    }

    public function isValidRefundReason(string $value): bool
    {
        foreach ($this->refundReasonOptions() as $option) {
            if ($option['value'] === $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, string>
     */
    public function refundReasonValues(): array
    {
        return array_map(
            static fn(array $option): string => $option['value'],
            $this->refundReasonOptions()
        );
    }

    public function pendingPayTimeoutMinutes(): int
    {
        return $this->positiveIntSetting(
            'order_pending_pay_timeout_minutes',
            self::DEFAULT_PENDING_PAY_TIMEOUT_MINUTES
        );
    }

    public function pendingPayTimeoutSeconds(): int
    {
        return $this->pendingPayTimeoutMinutes() * 60;
    }

    public function autoReceiveDays(): int
    {
        return $this->positiveIntSetting('order_auto_receive_days', self::DEFAULT_AUTO_RECEIVE_DAYS);
    }

    public function afterSaleDays(): int
    {
        $value = (int) getSystemSetting('refund_after_sale_days', self::DEFAULT_AFTER_SALE_DAYS);
        return max(0, min(3650, $value));
    }

    /**
     * @return array{name:string, phone:string, address:string}
     */
    public function returnReceiver(): array
    {
        return [
            'name' => trim((string) getSystemSetting('refund_return_receiver_name', '')),
            'phone' => trim((string) getSystemSetting('refund_return_receiver_phone', '')),
            'address' => trim((string) getSystemSetting('refund_return_receiver_address', '')),
        ];
    }

    private function positiveIntSetting(string $code, int $default): int
    {
        $value = (int) getSystemSetting($code, $default);
        if ($value <= 0) {
            return $default;
        }
        return min(1440, $value);
    }

    /**
     * @return array<int, array{value:string, label:string}>
     */
    private function normalizeRefundReasonOptions(string $value): array
    {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $options = [];
        $labels = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string)($row['label'] ?? ''));
            $optionValue = trim((string)($row['value'] ?? ''));
            if ($label === '') {
                continue;
            }
            if ($optionValue === '') {
                $optionValue = $label;
            }
            if (isset($labels[$label])) {
                continue;
            }
            $labels[$label] = true;
            $options[] = ['value' => $optionValue, 'label' => $label];
        }

        return $options;
    }
}
