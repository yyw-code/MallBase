<?php

declare(strict_types=1);

namespace app\middleware;

use think\Request;

/**
 * 跨域中间件（极简实现）
 *
 * 默认策略：只为 Authorization JWT API 反射请求 Origin，不允许跨域 Cookie 凭据。
 * 适用场景：前后端通过 Authorization: Bearer <JWT> 鉴权的 API 项目（本项目即是）。
 *
 * 重要约束：
 * 1. 本中间件必须是 backend/app/middleware.php 全局链的第一个。
 *    否则 InstallCheckMiddleware 会把 OPTIONS 预检重定向到 /install，导致所有跨域请求被挂起。
 * 2. 升级页面使用 HttpOnly Cookie，但只接受服务端同源校验，不提供 credentialed CORS。
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
            'Access-Control-Allow-Methods'     => self::ALLOW_METHODS,
            'Access-Control-Allow-Headers'     => self::ALLOW_HEADERS,
            'Access-Control-Max-Age'           => self::MAX_AGE,
            'Vary'                             => 'Origin',
        ]);
    }
}
