<?php

namespace mall_base\exception;

use mall_base\base\BaseException;

/**
 * 任务异常类
 * 
 * 用于队列任务、定时任务执行过程中的错误处理
 * 默认状态码：400
 * 
 * 使用场景：
 * - 队列任务执行失败（配合 think-queue）
 * - 定时任务执行异常
 * - 任务参数验证失败
 * - 任务业务逻辑错误
 * 
 * 使用示例：
 * ```php
 * // 在 BaseJob 子类中使用
 * class SendEmailJob extends BaseJob
 * {
 *     public function handle(): void
 *     {
 *         if (!$this->validateEmail()) {
 *             throw new JobException('邮箱格式错误');
 *         }
 *         
 *         // 业务逻辑...
 *     }
 * }
 * ```
 */
class JobException extends BaseException
{
    /**
     * 构造函数
     * 
     * @param string $message 错误消息
     * @param int $statusCode 业务状态码，默认 400
     * @param int $code 错误码，默认 0
     */
    public function __construct(string $message = '任务执行失败', int $statusCode = 400, int $code = 0)
    {
        parent::__construct($message, $statusCode, $code);
    }
}
