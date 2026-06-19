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
    private const COMPONENT_ACTIVE_WINDOW = 1296000;
    private const CONNECT_TIMEOUT_MS = 500;
    private const TIMEOUT_MS = 1000;

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
            $lock->markPlatformComponentSeen($componentType, $now);

            if (!$lock->reservePlatformReportWindow($now, self::REPORT_INTERVAL)) {
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
            return;
        }

        $next = [
            'instance_id' => (string) $data['instance_id'],
            'last_report_at' => $now,
            'next_report_after' => $now + self::REPORT_INTERVAL,
        ];
        if (!empty($data['token'])) {
            $next['token'] = (string) $data['token'];
        }

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
            return;
        }

        $lock->savePlatformState([
            'last_report_at' => $now,
            'next_report_after' => $now + self::REPORT_INTERVAL,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
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
            return [];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return [];
        }

        $requestHeaders = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $headers);

        $ch = curl_init(rtrim(self::BASE_URL, '/') . $path);
        if ($ch === false) {
            return [];
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
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
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
