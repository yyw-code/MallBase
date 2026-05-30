<?php

declare(strict_types=1);

namespace app\controller\admin\demo;

use app\service\admin\demo\DemoResetService;
use mall_base\base\BaseController;

/**
 * 演示站数据恢复控制器
 *
 * @extends BaseController<DemoResetService>
 */
class DemoResetController extends BaseController
{
    protected string $serviceClass = DemoResetService::class;

    public function reset()
    {
        $result = $this->service()->startQueuedReset($this->currentSiteUrl());

        return $this->success($result, '演示数据恢复任务已开始');
    }

    public function start()
    {
        $result = $this->service()->startQueuedReset($this->currentSiteUrl());

        return $this->success($result, '演示数据恢复任务已开始');
    }

    public function status()
    {
        return $this->success($this->service()->getResetStatus(), '获取成功');
    }

    private function currentSiteUrl(): string
    {
        $forwardedHost = $this->firstHeaderValue((string) $this->request->header('x-forwarded-host', ''));
        if ($forwardedHost !== '') {
            $forwardedProto = $this->firstHeaderValue((string) $this->request->header('x-forwarded-proto', ''));
            $scheme = in_array(strtolower($forwardedProto), ['http', 'https'], true)
                ? strtolower($forwardedProto)
                : $this->request->scheme();

            return rtrim($scheme . '://' . $forwardedHost, '/');
        }

        $domain = rtrim((string) $this->request->domain(), '/');
        if ($domain !== '') {
            return $domain;
        }

        $host = method_exists($this->request, 'host') ? (string) $this->request->host() : '';
        if ($host === '') {
            return '';
        }

        return rtrim($this->request->scheme() . '://' . $host, '/');
    }

    private function firstHeaderValue(string $value): string
    {
        $parts = explode(',', $value);

        return trim((string) ($parts[0] ?? ''));
    }
}
