<?php

declare(strict_types=1);

namespace app\service\connector;

class CustomerServiceSettingService
{
    public function clientMode(): string
    {
        $mode = $this->string('client_customer_service_mode', 'phone');
        return in_array($mode, ['phone', 'system'], true) ? $mode : 'phone';
    }

    public function widgetUrl(): string
    {
        return $this->string('customer_service_widget_url');
    }

    public function apiBase(): string
    {
        return $this->string('customer_service_api_base');
    }

    public function socketBase(): string
    {
        return $this->string('customer_service_socket_base');
    }

    public function platformCode(): string
    {
        return strtolower($this->string('customer_service_platform_code', 'mallbase'));
    }

    public function contextKeyId(): string
    {
        return $this->string('customer_service_context_key_id');
    }

    public function contextSecret(): string
    {
        return $this->string('customer_service_context_secret');
    }

    public function contextTtl(): int
    {
        return max(60, min(300, $this->int('customer_service_context_ttl', 300)));
    }

    public function connectorEnabled(): bool
    {
        return $this->bool('customer_service_connector_enabled');
    }

    public function connectorSecret(): string
    {
        return $this->string('customer_service_connector_secret');
    }

    public function timestampWindow(): int
    {
        return max(60, $this->int('customer_service_timestamp_window', 300));
    }

    public function allowedIps(): string
    {
        return $this->string('customer_service_allowed_ips');
    }

    public function operatorAdminId(): int
    {
        return $this->int('customer_service_operator_admin_id', 1);
    }

    private function string(string $code, string $default = ''): string
    {
        return trim((string) $this->setting($code, $default));
    }

    private function int(string $code, int $default = 0): int
    {
        $value = $this->setting($code, (string) $default);
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    private function bool(string $code, bool $default = false): bool
    {
        $value = $this->setting($code, $default ? '1' : '0');
        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    protected function setting(string $code, mixed $default = null): mixed
    {
        return getSystemSetting($code, $default);
    }
}
