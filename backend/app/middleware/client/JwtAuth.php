<?php

declare (strict_types=1);

namespace app\middleware\client;

use Closure;
use mall_base\exception\AuthException;
use mall_base\service\JwtCacheService;
use mall_base\service\JwtService;
use think\Request;
use think\Response;

/**
 * 前台用户 JWT 认证中间件
 */
class JwtAuth
{
    /**
     * Token 字段名
     */
    protected string $tokenField = 'token';

    /**
     * Token 位置：header、query、body
     */
    protected string $tokenLocation = 'header';

    /**
     * 处理请求
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     * @throws AuthException
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 获取 Token
        $token = $this->getToken($request);

        if (empty($token)) {
            throw new AuthException('未提供认证令牌');
        }

        // 验证 Token
        $jwtService = new JwtService();

        try {
            $decoded = $jwtService->decode($token);
        } catch (\Exception $e) {
            throw new AuthException('Token 无效或已过期');
        }

        if (($decoded->data->type ?? null) !== 'access') {
            throw new AuthException('Token 类型无效');
        }

        if (isset($decoded->data->guard) && $decoded->data->guard !== JwtCacheService::GUARD_CLIENT) {
            throw new AuthException('Token 身份域无效');
        }

        // 将用户信息存入请求对象
        $request->user_id = $decoded->data->user_id ?? null;
        $request->account = $decoded->data->account ?? null;
        $request->register_type = $decoded->data->register_type ?? null;
        $request->client_type = $decoded->data->client_type ?? null;
        $request->sid = $decoded->data->sid ?? null;
        $request->token = $token;

        return $next($request);
    }

    /**
     * 获取 Token
     *
     * @param Request $request
     * @return string|null
     */
    protected function getToken(Request $request): ?string
    {
        $token = null;

        // 从 Header 获取
        if ($this->tokenLocation === 'header') {
            $token = $request->header('Authorization');
            if ($token && strpos($token, 'Bearer ') === 0) {
                $token = substr($token, 7);
            }
        }

        // 从 Query 获取
        if (empty($token) && $this->tokenLocation === 'query') {
            $token = $request->param($this->tokenField);
        }

        // 从 Body 获取
        if (empty($token) && $this->tokenLocation === 'body') {
            $token = $request->post($this->tokenField);
        }

        return $token ?: null;
    }
}
