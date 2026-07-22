<?php
declare(strict_types=1);

namespace app\middleware\connector;

use Closure;
use app\service\connector\CustomerServiceIdempotencyStore;
use app\service\connector\CustomerServiceSettingService;
use mall_base\exception\BusinessException;
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
        $signatureVersion = $this->header($request, 'X-CS-Signature-Version');

        if ($timestamp === '' || $nonce === '' || $signature === '' || $bodyHash === '') {
            throw new BusinessException('客服连接器签名头缺失', 401);
        }
        $this->assertSignatureHeaders($timestamp, $nonce, $signature, $bodyHash, $headersHash);

        $timestampValue = (int) $timestamp;
        $window = $settings->timestampWindow();
        if ($timestampValue <= 0 || abs(time() - $timestampValue) > $window) {
            throw new BusinessException('客服连接器签名已过期', 401);
        }

        $rawBody = (string) $request->getContent();
        $actualBodyHash = hash('sha256', $rawBody);
        if (!hash_equals($actualBodyHash, $bodyHash)) {
            throw new BusinessException('客服连接器请求体摘要不匹配', 401);
        }
        $actualHeadersHash = $this->signedHeadersHash($request);
        if ($signatureVersion === '2' && $headersHash === '') {
            throw new BusinessException('客服连接器签名头缺失', 401);
        }
        if ($headersHash !== '' && !hash_equals($actualHeadersHash, $headersHash)) {
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

        if (!$this->acquireNonce($nonce, $window)) {
            throw new BusinessException('客服连接器请求已重复', 409);
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

    private function assertSignatureHeaders(
        string $timestamp,
        string $nonce,
        string $signature,
        string $bodyHash,
        string $headersHash
    ): void {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $timestamp)) {
            throw new BusinessException('客服连接器时间戳无效', 401);
        }
        if (!preg_match('/^[\x21-\x7E]{8,128}$/D', $nonce)) {
            throw new BusinessException('客服连接器随机数无效', 401);
        }
        foreach ([$signature, $bodyHash] as $digest) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                throw new BusinessException('客服连接器摘要无效', 401);
            }
        }
        if ($headersHash !== '' && !preg_match('/^[a-f0-9]{64}$/D', $headersHash)) {
            throw new BusinessException('客服连接器身份头摘要无效', 401);
        }
    }

    private function signedHeadersHash(Request $request): string
    {
        $signatureVersion = $this->header($request, 'x-cs-signature-version');
        if ($signatureVersion !== '' && $signatureVersion !== '2') {
            throw new BusinessException('客服连接器签名版本不支持', 401);
        }

        $headers = [];
        $signedHeaderNames = [
            'x-cs-external-user-authenticated',
            'x-cs-external-user-id',
            'x-cs-resource-owner-id',
        ];
        if ($signatureVersion === '2') {
            $signedHeaderNames[] = 'x-cs-idempotency-key';
            $signedHeaderNames[] = 'x-cs-signature-version';
        }
        foreach ($signedHeaderNames as $name) {
            $value = $this->header($request, $name);
            if ($value !== '') {
                if ($name === 'x-cs-idempotency-key' && !preg_match('/^[\x21-\x7E]{1,120}$/D', $value)) {
                    throw new BusinessException('客服连接器幂等键无效', 401);
                }
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
            return app()->make(CustomerServiceIdempotencyStore::class)->claim($key, '1', $ttl);
        } catch (\Throwable $error) {
            throw new BusinessException('客服连接器防重存储不可用，请稍后重试', 503);
        }
    }
}
