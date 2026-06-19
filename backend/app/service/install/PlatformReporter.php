<?php

declare(strict_types=1);

namespace app\service\install;

use Closure;
use mall_base\log\Logger;
use Throwable;

/**
 * 平台实例低频上报服务。
 *
 * 只上报安装实例、版本、环境摘要和心跳，不读取商家业务数据。
 */
final class PlatformReporter
{
    private const BASE_URL = 'https://platform.gosowong.cn';
    private const APP_CODE = 'mallbase';
    private const REPORT_INTERVAL = 86400;
    private const RETRY_INTERVAL = 300;
    private const COMPONENT_ACTIVE_WINDOW = 1296000;
    private const CONNECT_TIMEOUT_MS = 2000;
    private const TIMEOUT_MS = 5000;

    public function __construct(
        private readonly ?InstallLockService $lockService = null,
        private readonly ?Closure $transport = null,
    ) {
    }

    public function tick(string $componentType = 'backend_php'): void
    {
        try {
            $lock = $this->lockService();
            if (!$lock->isInstalled()) {
                return;
            }

            $state = $lock->getPlatformState();
            if (($state['disabled'] ?? false) === true) {
                return;
            }

            $now = time();
            $state = $this->repairPendingWindow($lock, $state, $now);
            $lock->markPlatformComponentSeen($componentType, $now);

            if (!$lock->reservePlatformReportWindow($now, self::RETRY_INTERVAL)) {
                return;
            }

            $state = $lock->getPlatformState();
            if (empty($state['instance_id']) || empty($state['token'])) {
                $this->activate($lock, $state, $now);
                return;
            }

            $this->heartbeat($lock, (string) $state['token'], $componentType, $now);
        } catch (Throwable $e) {
            Logger::instance()->debug('平台实例统计跳过', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function activate(InstallLockService $lock, array $state, int $now): void
    {
        $payload = [
            'app_code' => self::APP_CODE,
            'app_version' => $this->appVersion(),
            'environment' => $this->environment('github'),
        ];

        if (!empty($state['instance_id'])) {
            $payload['instance_id'] = (string) $state['instance_id'];
        }

        $response = $this->post('/api/v1/telemetry/activate', $payload);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        if (empty($data['instance_id'])) {
            $this->recordFailure($lock, 'activate_failed', $response, $now);
            return;
        }

        if (empty($data['token'])) {
            $lock->savePlatformState([
                'instance_id' => (string) $data['instance_id'],
            ]);
            $this->recordFailure($lock, 'activate_token_missing', $response, $now);
            return;
        }

        $next = [
            'instance_id' => (string) $data['instance_id'],
            'token' => (string) $data['token'],
            'last_report_at' => $now,
            'next_report_after' => $now + self::REPORT_INTERVAL,
            'last_report_error' => '',
            'last_report_error_at' => 0,
        ];

        $lock->savePlatformState($next);
    }

    private function heartbeat(InstallLockService $lock, string $token, string $componentType, int $now): void
    {
        $version = $this->appVersion();
        $components = $lock->getActivePlatformComponents($now, self::COMPONENT_ACTIVE_WINDOW, $version);
        if ($components === []) {
            $components = [
                [
                    'type' => $componentType,
                    'version' => $version,
                ],
            ];
        }

        $response = $this->post('/api/v1/telemetry/heartbeat', [
            'heartbeat_id' => $this->heartbeatId($now),
            'app_version' => $version,
            'environment' => $this->environment(),
            'components' => $components,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        if (($response['data']['accepted'] ?? false) !== true) {
            $this->recordFailure($lock, 'heartbeat_rejected', $response, $now);
            return;
        }

        $lock->savePlatformState([
            'last_report_at' => $now,
            'next_report_after' => $now + self::REPORT_INTERVAL,
            'last_report_error' => '',
            'last_report_error_at' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function recordFailure(
        InstallLockService $lock,
        string $fallbackReason,
        array $response,
        int $now,
    ): void {
        $reason = trim((string) ($response['_error'] ?? $fallbackReason));

        $lock->savePlatformState([
            'last_report_error' => $reason !== '' ? $reason : $fallbackReason,
            'last_report_error_at' => $now,
            'next_report_after' => $now + self::RETRY_INTERVAL,
        ]);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function repairPendingWindow(InstallLockService $lock, array $state, int $now): array
    {
        $nextReportAfter = (int) ($state['next_report_after'] ?? 0);
        if ($nextReportAfter <= $now + self::RETRY_INTERVAL) {
            return $state;
        }

        $hasCredentials = !empty($state['instance_id']) && !empty($state['token']);
        $hasSuccessfulReport = !empty($state['last_report_at']);
        if ($hasCredentials && $hasSuccessfulReport) {
            return $state;
        }

        return $lock->savePlatformState([
            'next_report_after' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<array-key, string> $headers
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, array $headers = []): array
    {
        if ($this->transport !== null) {
            $transport = $this->transport;
            $response = $transport($path, $payload, $headers);

            return is_array($response) ? $response : [];
        }

        if (!function_exists('curl_init')) {
            return ['_error' => 'curl_missing'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['_error' => 'payload_encode_failed'];
        }

        $requestHeaders = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $this->formatHeaders($headers));

        $ch = curl_init(rtrim(self::BASE_URL, '/') . $path);
        if ($ch === false) {
            return ['_error' => 'curl_init_failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
        ]);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($raw)) {
            return ['_error' => $curlError !== '' ? $curlError : 'request_failed'];
        }

        if ($raw === '') {
            return ['_error' => 'empty_response', '_status' => $status];
        }

        if ($status < 200 || $status >= 300) {
            return ['_error' => 'http_' . $status, '_status' => $status];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['_error' => 'invalid_json', '_status' => $status];
    }

    /**
     * @param array<array-key, string> $headers
     * @return array<int, string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            if (is_string($key)) {
                $formatted[] = $key . ': ' . $value;
                continue;
            }

            $formatted[] = $value;
        }

        return $formatted;
    }

    /**
     * @return array<string, string>
     */
    private function environment(?string $installSource = null): array
    {
        $environment = [
            'php_version' => PHP_VERSION,
            'db_driver' => (string) config('database.default', 'mysql'),
            'os' => PHP_OS_FAMILY,
            'arch' => php_uname('m') ?: '',
            'timezone' => date_default_timezone_get(),
        ];

        if ($installSource !== null) {
            $environment['install_source'] = $installSource;
        }

        return $environment;
    }

    private function appVersion(): string
    {
        $path = dirname(rtrim(root_path(), DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . '.version';
        if (is_file($path)) {
            $version = trim((string) file_get_contents($path));
            if ($version !== '') {
                return $version;
            }
        }

        return '1.0.0';
    }

    private function heartbeatId(int $now): string
    {
        $day = gmdate('Y-m-d', $now);
        $hash = md5(self::APP_CODE . ':' . $day);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    private function lockService(): InstallLockService
    {
        return $this->lockService ?? app()->make(InstallLockService::class);
    }
}
