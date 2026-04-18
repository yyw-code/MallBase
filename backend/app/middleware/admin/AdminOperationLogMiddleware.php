<?php

declare (strict_types=1);

namespace app\middleware\admin;

use Closure;
use app\service\admin\AdminOperationLogService;
use think\Request;
use think\Response;

/**
 * 操作日志中间件
 */
class AdminOperationLogMiddleware
{
    /**
     * 是否启用
     */
    protected bool $enable = true;

    /**
     * 是否记录响应数据
     */
    protected bool $logResponse = false;

    /**
     * 是否记录请求参数
     */
    protected bool $logParams = true;

    /**
     * 不需要记录的路径
     */
    protected array $excludePaths = [
        'admin/auth/admin/login',
        'admin/auth/admin/info',
    ];

    /**
     * 请求参数中不需要记录的字段
     */
    protected array $excludeFields = [
        'password',
        'password_confirm',
        'token',
    ];

    /**
     * 处理请求
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->enable) {
            return $next($request);
        }

        // 检查是否需要记录
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        // 记录开始时间
        $startTime = microtime(true);

        // 执行请求
        $response = $next($request);

        // 计算执行时间
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2); // 毫秒

        // 记录操作日志
        $this->logOperation($request, $response, $duration);

        return $response;
    }

    /**
     * 检查是否需要跳过记录
     *
     * @param Request $request
     * @return bool
     */
    protected function shouldSkip(Request $request): bool
    {
        // 检查是否登录
        $adminId = $request->admin_id ?? null;
        if (empty($adminId)) {
            return true;
        }

        // 检查路径是否在排除列表中
        $path = $request->pathinfo();
        foreach ($this->excludePaths as $excludePath) {
            if (strpos($path, $excludePath) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 记录操作日志
     *
     * @param Request $request
     * @param Response $response
     * @param float $duration 执行时间（毫秒）
     */
    protected function logOperation(Request $request, Response $response, float $duration): void
    {
        try {
            $service = app()->make(AdminOperationLogService::class);
            
            $service->log(
                adminId: $request->admin_id,
                username: $request->username,
                nickname: $request->nickname,
                path: $request->pathinfo(),
                method: $request->method(),
                params: $this->logParams ? $this->getRequestParams($request) : null,
                response: $this->logResponse ? $this->getResponseData($response) : null,
                status: $response->getCode(),
                ip: $request->ip(),
                userAgent: $request->header('user-agent', ''),
                duration: $duration
            );
        } catch (\Exception $e) {
            // 记录日志失败不影响业务
            // 可以记录到文件日志
            // \think\facade\Log::error('操作日志记录失败：' . $e->getMessage());
        }
    }

    /**
     * 获取请求参数
     *
     * @param Request $request
     * @return array
     */
    protected function getRequestParams(Request $request): array
    {
        $params = $request->param();

        // 过滤敏感字段
        foreach ($this->excludeFields as $field) {
            if (isset($params[$field])) {
                $params[$field] = '******';
            }
        }

        // 限制参数长度
        $paramsString = json_encode($params);
        if (strlen($paramsString) > 5000) {
            $params = ['params_too_long' => '参数过长，已截断'];
        }

        return $params;
    }

    /**
     * 获取响应数据
     *
     * @param Response $response
     * @return array|null
     */
    protected function getResponseData(Response $response): ?array
    {
        $content = $response->getContent();
        
        if (empty($content)) {
            return null;
        }

        // 尝试解析 JSON
        $data = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // 过滤敏感信息
            if (isset($data['token'])) {
                unset($data['token']);
            }
            
            // 限制响应数据长度
            $dataString = json_encode($data);
            if (strlen($dataString) > 5000) {
                $data = ['response_too_long' => '响应数据过长，已截断'];
            }
            
            return $data;
        }

        return null;
    }
}