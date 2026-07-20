<?php

declare(strict_types=1);

namespace app\controller\admin\upgrade;

use app\service\admin\upgrade\UpgradeAdminService;
use mall_base\base\BaseController;
use think\facade\Log;
use think\Response;
use Throwable;

/**
 * 后台升级 HTTP 入口。这里只做参数接收、固定错误映射和安全响应头；
 * 任务创建与文件协议全部交给 UpgradeAdminService。
 *
 * @extends BaseController<UpgradeAdminService>
 */
final class UpgradeController extends BaseController
{
    protected string $serviceClass = UpgradeAdminService::class;

    public function records(): Response
    {
        [$page, $limit] = $this->getPagination();
        try {
            return $this->success($this->service()->getList($page, $limit), '获取升级记录成功')
                ->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    public function overview(): Response
    {
        try {
            return $this->success($this->service()->getOverview(), '获取版本概览成功')
                ->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    public function releases(): Response
    {
        try {
            return $this->success($this->service()->getReleaseCatalog(), '获取平台版本目录成功')
                ->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    public function createJob(): Response
    {
        try {
            $result = $this->service()->createJob(
                (int) ($this->request->admin_id ?? 0),
                $this->request->post('action', ''),
                $this->request->post('target_version', ''),
            );

            return $this->success($result, '升级任务已创建')
                ->header($this->securityHeaders());
        } catch (Throwable $exception) {
            return $this->mapError($exception);
        }
    }

    private function mapError(Throwable $exception): Response
    {
        [$status, $reason, $message] = match ($exception->getMessage()) {
            'UPGRADE_RECORD_ARGUMENT_INVALID', 'UPGRADE_ENTRY_ARGUMENT_INVALID', 'UPGRADE_CATALOG_ARGUMENT_INVALID' => [400, $exception->getMessage(), '升级请求参数无效'],
            'UPGRADE_ENTRY_CONFLICT' => [400, 'UPGRADE_ENTRY_CONFLICT', '已有升级任务等待或正在执行'],
            'UPGRADE_RECORD_INVALID' => [500, 'UPGRADE_RECORD_INVALID', '升级记录文件损坏，请检查任务记录'],
            'UPGRADE_ROOT_UNAVAILABLE', 'UPGRADE_RECORD_UNAVAILABLE', 'UPGRADE_ENTRY_UNAVAILABLE', 'UPGRADE_OVERVIEW_UNAVAILABLE' => [503, $exception->getMessage(), '升级服务共享目录暂时不可用'],
            'UPGRADE_CATALOG_UNAVAILABLE' => [503, 'UPGRADE_CATALOG_UNAVAILABLE', '平台版本目录暂时不可用'],
            default => [503, 'UPGRADE_ADMIN_UNAVAILABLE', '升级服务暂时不可用'],
        };
        if ($status >= 500) {
            try {
                Log::warning('upgrade admin request failed: ' . $reason);
            } catch (Throwable) {
                // 日志异常不能覆盖固定的对外错误响应。
            }
        }

        return json([
            'code' => $status,
            'message' => $message,
            'data' => ['reason' => $reason],
            'timestamp' => time(),
        ], $status)->header($this->securityHeaders());
    }

    /** @return array<string, string> */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
