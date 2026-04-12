<?php

namespace app\middleware;

use think\Request;
use think\Response;

class CorsMiddleware
{
    private const DEFAULT_ALLOWED_ORIGINS = 'http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,http://127.0.0.1:5173,http://localhost:8080,http://127.0.0.1:8080';

    public function handle(Request $request, \Closure $next)
    {
        $origin = trim((string) $request->header('origin', ''));
        $allowOrigins = $this->getAllowedOrigins();
        $allowAnyOrigin = in_array('*', $allowOrigins, true);
        $originAllowed = $origin !== '' && ($allowAnyOrigin || in_array($origin, $allowOrigins, true));

        $headers = [
            'Vary' => 'Origin',
            'Access-Control-Allow-Methods' => env('CORS_ALLOW_METHODS', 'GET,POST,PUT,DELETE,OPTIONS'),
            'Access-Control-Allow-Headers' => env('CORS_ALLOW_HEADERS', 'Authorization,Content-Type,X-Requested-With'),
        ];
        if ($originAllowed) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        // 预检请求
        if ($request->isOptions()) {
            if ($origin !== '' && !$originAllowed) {
                return response('', 403)->header(['Vary' => 'Origin']);
            }

            return response('', 204)->header($headers);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response->header($headers);
    }

    private function getAllowedOrigins(): array
    {
        $rawOrigins = (string) env('CORS_ALLOWED_ORIGINS', self::DEFAULT_ALLOWED_ORIGINS);
        $origins = array_map('trim', explode(',', $rawOrigins));
        return array_values(array_filter($origins, static fn($item) => $item !== ''));
    }
}
