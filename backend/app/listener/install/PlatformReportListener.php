<?php

declare(strict_types=1);

namespace app\listener\install;

use app\service\install\PlatformReporter;
use Throwable;

final class PlatformReportListener
{
    public function handle(mixed $payload = null): void
    {
        try {
            app()->make(PlatformReporter::class)->tick($this->componentType());
        } catch (Throwable) {
            // 平台实例统计不能影响正常请求。
        }
    }

    private function componentType(): string
    {
        $request = request();
        $header = strtolower(trim((string) $request->header('X-MallBase-Client', '')));
        if (in_array($header, ['backend_php', 'admin_web', 'uniapp', 'wechat_miniapp'], true)) {
            return $header;
        }

        $path = '/' . ltrim((string) $request->pathinfo(), '/');
        if (str_starts_with($path, '/admin/api')) {
            return 'admin_web';
        }
        if (str_starts_with($path, '/client/api')) {
            return 'uniapp';
        }

        return 'backend_php';
    }
}
