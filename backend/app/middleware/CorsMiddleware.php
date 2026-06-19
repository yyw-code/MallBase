<?php

declare(strict_types=1);

namespace app\middleware;

use think\Request;

/**
 * 跨域中间件（极简实现）
 *
 * 默认策略：反射请求 Origin 到 Access-Control-Allow-Origin，附带 Credentials: true。
 * 适用场景：前后端通过 Authorization: Bearer <JWT> 鉴权的 API 项目（本项目即是）。
 *
 * 重要约束：
 * 1. 本中间件必须是 backend/app/middleware.php 全局链的第一个。
 *    否则 InstallCheckMiddleware 会把 OPTIONS 预检重定向到 /install，导致所有跨域请求被挂起。
 * 2. 本项目不依赖 Cookie / Session（前端 request.ts 未设 withCredentials），
 *    Credentials: true 在当前代码里是 no-op。若后续引入 Cookie 鉴权，"反射任意 Origin + Credentials"
 *    将变成经典的"任意站点带凭据跨域调用"漏洞 —— 此时必须改为 Origin 白名单或要求签名。
 * 3. 非跨域请求（无 Origin 头）不写任何 Access-Control-* 响应头，避免污染 CDN / 代理缓存。
 */
class CorsMiddleware
{
    private const ALLOW_METHODS = 'GET, POST, PUT, DELETE, OPTIONS';
    private const ALLOW_HEADERS = 'Authorization, Content-Type, X-Requested-With, X-MallBase-Client';
    private const MAX_AGE       = '86400';

    public function handle(Request $request, \Closure $next)
    {
        $origin = trim((string) $request->header('origin', ''));

        // 预检请求：直接 204，后续统一补 CORS 头
        $response = $request->isOptions()
            ? response('', 204)
            : $next($request);

        // 非跨域请求不写 CORS 头，避免污染 CDN / 代理缓存语义
        if ($origin === '') {
            return $response;
        }

        return $response->header([
            'Access-Control-Allow-Origin'      => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods'     => self::ALLOW_METHODS,
            'Access-Control-Allow-Headers'     => self::ALLOW_HEADERS,
            'Access-Control-Max-Age'           => self::MAX_AGE,
            'Vary'                             => 'Origin',
        ]);
    }
}
