<?php

declare(strict_types=1);

namespace app\controller\client\order;

use app\service\client\payment\NotifyService;
use mall_base\log\Logger;
use think\Response;
use Throwable;

/**
 * 微信支付回调控制器
 *
 * 约束（遵循 .codex/skills/thinkPHP/payment-notify-idempotency）：
 *  - 本入口绝对路径 POST /api/notify/wechat/pay，路由不挂任何鉴权中间件
 *  - 控制器仅做透传，业务全部在 NotifyService::handle()
 *  - 应答必须用 V3 协议规定的 JSON 体（直接 json_encode，不走项目统一 success 信封）
 *  - 所有抛出的异常都要兜底成 5xx，避免微信认为已处理而停止重试
 */
class PayNotifyController
{
    /**
     * 微信支付回调入口
     */
    public function wechat()
    {
        try {
            $request = request();
            $headers = $this->collectHeaders($request);
            $rawBody = (string) $request->getContent();

            /** @var NotifyService $service */
            $service = app()->make(NotifyService::class);
            $result = $service->handle($headers, $rawBody);

            return $this->respond((int) $result['status'], (array) $result['body']);
        } catch (Throwable $e) {
            Logger::instance()->critical('微信支付回调主控异常', ['error' => $e->getMessage()]);
            return $this->respond(500, ['code' => 'FAIL', 'message' => '服务异常']);
        }
    }

    /**
     * 微信退款回调入口
     */
    public function wechatRefund()
    {
        try {
            $request = request();
            $headers = $this->collectHeaders($request);
            $rawBody = (string) $request->getContent();

            /** @var NotifyService $service */
            $service = app()->make(NotifyService::class);
            $result = $service->handleRefund($headers, $rawBody);

            return $this->respond((int) $result['status'], (array) $result['body']);
        } catch (Throwable $e) {
            Logger::instance()->critical('微信退款回调主控异常', ['error' => $e->getMessage()]);
            return $this->respond(500, ['code' => 'FAIL', 'message' => '服务异常']);
        }
    }

    /**
     * 收集所有请求头（含微信四个签名头）
     *
     * @return array<string, string>
     */
    private function collectHeaders($request): array
    {
        $headers = [];
        $serverHeaders = $request->header();
        if (is_array($serverHeaders)) {
            foreach ($serverHeaders as $name => $value) {
                $headers[(string) $name] = is_array($value) ? implode(',', $value) : (string) $value;
            }
        }
        return $headers;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body): Response
    {
        return Response::create($body, 'json', $status)
            ->header(['Content-Type' => 'application/json; charset=utf-8']);
    }
}
