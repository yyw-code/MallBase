<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

class InstallCheckMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->pathinfo();
        $isInstallRoute = str_starts_with($path, 'install/') || $path === 'install';
        $isInstalled = file_exists(root_path() . 'install' . DIRECTORY_SEPARATOR . 'install.lock');

        if ($isInstalled
            && $isInstallRoute
            && str_starts_with($path, 'install/api/')
        ) {
            return json(['code' => 400, 'message' => '系统已安装', 'data' => null]);
        }

        if (!$isInstalled && !$isInstallRoute) {
            if ($request->isAjax() || str_contains($request->header('accept', ''), 'application/json')) {
                return json(['code' => 302, 'message' => '系统未安装', 'data' => ['redirect' => '/install']], 302);
            }

            return redirect('/install');
        }

        return $next($request);
    }
}
