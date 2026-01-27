<?php

namespace mall_base\base;

use mall_base\log\Logger;
use mall_base\log\LogContext;
use mall_base\log\Trace;
use Throwable;

/**
 * 任务基类（配合 think-queue 使用）
 * 
 * 提供队列任务的基础设施支持
 * 注意：重试、超时等功能由 think-queue 提供，本类只提供基础能力
 * 
 * 功能说明：
 * - 统一的错误处理和日志记录
 * - 自动捕获并记录任务执行异常（子类无需手动 try-catch）
 * - 根据 APP_DEBUG 自动调整日志详细程度
 * - 协程环境支持
 * - 自动生成 Trace ID 用于链路追踪
 * 
 * 使用场景：
 * - 队列任务（配合 think-queue）
 * - 定时任务
 * 
 * 设计理念：
 * - 只提供基础设施，不重复实现 think-queue 的功能
 * - 简洁、专注、易用
 * - 支持 Swoole 协程环境
 * - 统一的日志格式，便于问题追踪
 * - 自动异常捕获，减少子类重复代码
 * 
 * 使用示例：
 * ```php
 * namespace app\job;
 * 
 * use mall_base\base\BaseJob;
 * use mall_base\exception\JobException;
 * 
 * class SendEmailJob extends BaseJob
 * {
 *     public function __construct(
 *         protected string $email,
 *         protected string $subject,
 *         protected string $content
 *     ) {}
 *     
 *     public function handle(): void
 *     {
 *         // 记录日志（自动包含 Trace ID、协程 ID 等）
 *         $this->logger()->info('发送邮件到: ' . $this->email);
 *         
 *         // 业务逻辑（异常会被自动捕获并记录，无需手动 try-catch）
 *         $result = $this->sendEmail();
 *         
 *         if (!$result) {
 *             throw new JobException('邮件发送失败');
 *         }
 *         
 *         $this->logger()->success('邮件发送成功');
 *     }
 * }
 * 
 * // 入队
 * use think\facade\Queue;
 * Queue::push(SendEmailJob::class, [
 *     'email' => 'user@example.com',
 *     'subject' => '测试邮件',
 *     'content' => '邮件内容'
 * ]);
 * ```
 */
abstract class BaseJob
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        // Logger::instance() 会自动初始化 Trace ID 和添加类名到上下文
    }

    /**
     * 执行任务（think-queue 调用此方法）
     * 自动捕获异常并记录日志
     * 
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->handle();
        } catch (Throwable $e) {
            // 记录异常日志
            $this->logger()->jobError($e);
            
            // 重新抛出异常，让 think-queue 处理重试
            throw $e;
        }
    }

    /**
     * 任务处理逻辑（子类实现）
     * 注意：此方法的异常会被自动捕获并记录，子类无需手动 try-catch
     * 
     * @return void
     * @throws Throwable
     */
    abstract public function handle(): void;

    /**
     * 获取日志记录器
     * 
     * @return Logger
     */
    protected function logger(): Logger
    {
        return Logger::instance('Job', static::class);
    }

    /**
     * 记录日志（快捷方法）
     * 
     * @param string $message 日志消息
     * @param string $level 日志级别
     * @param array<string, mixed> $context 上下文数据
     * @return void
     */
    protected function log(string $message, string $level = 'info', array $context = []): void
    {
        $logger = $this->logger();
        $logger->$level($message, $context);
    }

    /**
     * 检查是否在协程上下文中
     * 
     * @return bool
     */
    public function isCoroutine(): bool
    {
        $context = LogContext::getAll();
        return isset($context['coroutine_id']);
    }

    /**
     * 获取 Trace ID
     * 
     * @return string
     */
    public function getTraceId(): string
    {
        return Trace::getCoroutineTraceId();
    }
}
