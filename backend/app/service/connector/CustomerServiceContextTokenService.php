<?php
declare(strict_types=1);

namespace app\service\connector;

use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

class CustomerServiceContextTokenService extends BaseService
{
    protected string $modelClass = \app\model\user\User::class;

    /**
     * @param array<string, mixed> $payload
     */
    public function issue(array $payload): string
    {
        $settings = app()->make(CustomerServiceSettingService::class);
        $keyId = $settings->contextKeyId();
        if ($keyId === '') {
            throw new BusinessException('客服上下文 Key ID 未配置', 503);
        }
        if (preg_match('/^ctx_[A-Za-z0-9_-]{20,64}$/', $keyId) !== 1) {
            throw new BusinessException('客服上下文 Key ID 无效', 503);
        }

        $secret = $settings->contextSecret();
        if ($secret === '') {
            throw new BusinessException('客服上下文密钥未配置', 503);
        }
        if (strlen($secret) < 32) {
            throw new BusinessException('客服上下文密钥长度不足', 503);
        }

        $platformCode = strtolower(trim($settings->platformCode()));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $platformCode) !== 1) {
            throw new BusinessException('客服平台标识无效', 503);
        }

        $visitor = $payload['visitor'] ?? null;
        $visitorId = is_array($visitor) && isset($visitor['id']) && is_scalar($visitor['id'])
            ? trim((string) $visitor['id'])
            : '';
        if ($visitorId === '' || strlen($visitorId) > 128) {
            throw new BusinessException('客服访客标识不能为空');
        }
        $visitor['id'] = $visitorId;
        $payload['visitor'] = $visitor;

        $now = time();
        $ttl = max(60, min(300, $settings->contextTtl()));
        $body = array_merge($payload, [
            'iss' => $platformCode,
            'aud' => 'customer-service',
            'sub' => $visitorId,
            'platformCode' => $platformCode,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(16)),
        ]);

        $header = [
            'alg' => 'HS256',
            'typ' => 'cs-context+jwt',
            'kid' => $keyId,
        ];
        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
