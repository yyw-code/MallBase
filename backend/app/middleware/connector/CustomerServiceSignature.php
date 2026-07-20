<?php
declare(strict_types=1);

namespace app\middleware\connector;

use Closure;
use app\service\connector\CustomerServiceSettingService;
use mall_base\exception\BusinessException;
use think\facade\Cache;
use think\Request;
use think\Response;

/**
 * 客服系统服务端连接器签名校验。
 *
 * 签名串：
 * METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + BODY_SHA256 + ["\n" + HEADERS_SHA256]
 */
class CustomerServiceSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app()->make(CustomerServiceSettingService::class);
        if (!$settings->connectorEnabled()) {
            throw new BusinessException('客服连接器未启用', 403);
        }

        $secret = $settings->connectorSecret();
        if ($secret === '') {
            throw new BusinessException('客服连接器密钥未配置', 503);
        }
        $this->assertConnectorSecret($secret);

        $this->assertIpAllowed($request, $settings);

        $timestamp = $this->header($request, 'X-CS-Timestamp');
        $nonce = $this->header($request, 'X-CS-Nonce');
        $signature = strtolower($this->header($request, 'X-CS-Signature'));
        $bodyHash = strtolower($this->header($request, 'X-CS-Body-SHA256'));
        $headersHash = strtolower($this->header($request, 'X-CS-Headers-SHA256'));

        if ($timestamp === '' || $nonce === '' || $signature === '' || $bodyHash === '') {
            throw new BusinessException('客服连接器签名头缺失', 401);
        }

        $timestampValue = (int) $timestamp;
        $window = $settings->timestampWindow();
        if ($timestampValue <= 0 || abs(time() - $timestampValue) > $window) {
            throw new BusinessException('客服连接器签名已过期', 401);
        }

        if (!$this->acquireNonce($nonce, $window)) {
            throw new BusinessException('客服连接器请求已重复', 409);
        }

        $rawBody = (string) $request->getContent();
        $actualBodyHash = hash('sha256', $rawBody);
        if (!hash_equals($actualBodyHash, $bodyHash)) {
            throw new BusinessException('客服连接器请求体摘要不匹配', 401);
        }
        if ($headersHash !== '' && !hash_equals($this->signedHeadersHash($request), $headersHash)) {
            throw new BusinessException('客服连接器身份头摘要不匹配', 401);
        }

        $path = $this->canonicalRequestPath($request);
        $canonicalParts = [
            strtoupper($request->method()),
            $path,
            (string) $timestampValue,
            $nonce,
            $bodyHash,
        ];
        if ($headersHash !== '') {
            $canonicalParts[] = $headersHash;
        }

        $canonical = implode("\n", $canonicalParts);
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new BusinessException('客服连接器签名无效', 401);
        }

        return $next($request);
    }

    private function assertIpAllowed(Request $request, CustomerServiceSettingService $settings): void
    {
        $configured = $settings->allowedIps();
        if ($configured === '') {
            return;
        }

        $allowed = array_filter(array_map('trim', explode(',', $configured)));
        if ($allowed === []) {
            return;
        }

        $ip = (string) $request->ip();
        if (!in_array($ip, $allowed, true)) {
            throw new BusinessException('客服连接器来源 IP 不允许', 403);
        }
    }

    private function header(Request $request, string $name): string
    {
        $value = $request->header($name, '');
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        return trim((string) $value);
    }

    private function canonicalRequestPath(Request $request): string
    {
        $rawUrl = $request->url(false);
        $path = parse_url($rawUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/')) {
            throw new BusinessException('客服连接器请求路径无效', 401);
        }

        $query = parse_url($rawUrl, PHP_URL_QUERY);
        return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }

    private function assertConnectorSecret(string $secret): void
    {
        if (strlen($secret) < 32) {
            throw new BusinessException('客服连接器密钥长度不足', 503);
        }
    }

    private function signedHeadersHash(Request $request): string
    {
        $headers = [];
        foreach ([
            'x-cs-external-user-authenticated',
            'x-cs-external-user-id',
            'x-cs-resource-owner-id',
        ] as $name) {
            $value = $this->header($request, $name);
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        ksort($headers);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ':' . $value;
        }

        return hash('sha256', implode("\n", $lines));
    }

    private function acquireNonce(string $nonce, int $ttl): bool
    {
        $key = 'customer_service_connector_nonce:' . sha1($nonce);
        try {
            $handler = Cache::handler();
            return is_object($handler) && $this->acquireAtomicNonce($handler, $key, $ttl);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function acquireAtomicNonce(object $handler, string $key, int $ttl): bool
    {
        $command = ['SET', $key, '1', 'EX', (string) $ttl, 'NX'];

        try {
            if (method_exists($handler, 'rawCommand')) {
                $result = $handler->rawCommand(...$command);
            } elseif (method_exists($handler, 'executeRaw')) {
                $result = $handler->executeRaw($command);
            } else {
                return false;
            }

            return $result === true || strtoupper((string) $result) === 'OK';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
