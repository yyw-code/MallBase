<?php
declare(strict_types=1);

namespace app\middleware\client;

use Closure;
use mall_base\service\JwtService;
use think\Request;
use think\Response;

/**
 * 前台用户可选 JWT 认证。
 *
 * 没有 Token 或 Token 无效时按匿名继续访问；有合法 Token 时注入用户信息。
 */
class OptionalJwtAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->getToken($request);
        if ($token === null || $token === '') {
            return $next($request);
        }

        try {
            $decoded = (new JwtService())->decode($token);
            if (($decoded->data->type ?? null) === 'access') {
                $request->user_id = $decoded->data->user_id ?? null;
                $request->account = $decoded->data->account ?? null;
                $request->register_type = $decoded->data->register_type ?? null;
                $request->token = $token;
            }
        } catch (\Throwable) {
            // 公开接口保持匿名可访问，私有接口仍使用强制 JwtAuth。
        }

        return $next($request);
    }

    protected function getToken(Request $request): ?string
    {
        $token = $request->header('Authorization');
        if ($token && str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        return $token ?: null;
    }
}
