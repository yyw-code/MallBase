<?php

namespace app;

use mall_base\exception\AuthException;
use mall_base\exception\BusinessException;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        switch (true) {
            case $e instanceof BusinessException:
            case $e instanceof AuthException:
            case $e instanceof ValidateException:
                $code = $e->getCode() ?: 400;
                break;
            default:
                $code = 400;
            // 其他错误交给系统处理
//                return parent::render($request, $e);
        }

        // 根据环境返回不同详细程度的信息
        $details = [];
        if (env('app_debug')) {
            $details = [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];

            $trace = $e->getTrace();
            try {
                $encodedTrace = json_encode($trace, JSON_UNESCAPED_UNICODE);
                $details['trace'] = $trace;
                $details['trace_type'] = 'array';
            } catch (\Exception $e) {
                $details['trace'] = $e->getTraceAsString();
                $details['trace_type'] = 'string';
                $details['trace_error'] = json_last_error_msg();
            }
        }
        $message = $e->getMessage();
        return json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'data' => [],
            'details' => $details,
        ]);
    }
}
