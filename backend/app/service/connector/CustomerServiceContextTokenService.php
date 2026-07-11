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
        $secret = $settings->contextSecret();
        if ($secret === '') {
            throw new BusinessException('客服上下文密钥未配置', 503);
        }

        $now = time();
        $ttl = $settings->contextTtl();
        $body = array_merge($payload, [
            'platformCode' => $settings->platformCode(),
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
            $this->base64UrlEncode(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
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
