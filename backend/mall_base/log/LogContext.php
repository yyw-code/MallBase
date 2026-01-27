<?php

namespace mall_base\log;

use Swoole\Coroutine as Co;

/**
 * 日志上下文类
 * 
 * 功能说明：
 * - 管理日志的公共上下文信息
 * - 支持协程环境下的上下文隔离
 * - 自动收集 Trace ID、协程 ID 等信息
 * 
 * 使用场景：
 * - 收集请求相关的公共信息
 * - 在日志中自动附加上下文
 * - 协程环境下的上下文管理
 * 
 * 设计理念：
 * - 全局上下文：整个请求/任务期间共享
 * - 协程上下文：每个协程独立
 * - 自动收集：减少手动设置的工作量
 */
class LogContext
{
    /** @var array<string, mixed> 全局上下文 */
    protected static array $globalContext = [];

    /**
     * 获取全局上下文
     * 
     * @return array<string, mixed>
     */
    public static function getGlobal(): array
    {
        return self::$globalContext;
    }

    /**
     * 设置全局上下文
     * 
     * @param array<string, mixed> $context
     * @return void
     */
    public static function setGlobal(array $context): void
    {
        self::$globalContext = array_merge(self::$globalContext, $context);
    }

    /**
     * 添加全局上下文
     * 
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function addGlobal(string $key, mixed $value): void
    {
        self::$globalContext[$key] = $value;
    }

    /**
     * 获取协程上下文
     * 
     * @return array<string, mixed>
     */
    public static function getCoroutine(): array
    {
        if (!extension_loaded('swoole')) {
            return self::getGlobal();
        }
        
        // 新版 Swoole 需要传递协程 ID 给 exists()
        $cid = Co::getCid();
        if ($cid <= 0 || !Co::exists($cid)) {
            return self::getGlobal();
        }

        $context = Co::getContext();
        return $context['log_context'] ?? [];
    }

    /**
     * 设置协程上下文
     * 
     * @param array<string, mixed> $context
     * @return void
     */
    public static function setCoroutine(array $context): void
    {
        if (!extension_loaded('swoole')) {
            self::setGlobal($context);
            return;
        }
        
        // 新版 Swoole 需要传递协程 ID 给 exists()
        $cid = Co::getCid();
        if ($cid <= 0 || !Co::exists($cid)) {
            self::setGlobal($context);
            return;
        }

        $swContext = Co::getContext();
        $swContext['log_context'] = array_merge(self::getCoroutine(), $context);
    }

    /**
     * 添加协程上下文
     * 
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function addCoroutine(string $key, mixed $value): void
    {
        if (!extension_loaded('swoole')) {
            self::addGlobal($key, $value);
            return;
        }
        
        // 新版 Swoole 需要传递协程 ID 给 exists()
        $cid = Co::getCid();
        if ($cid <= 0 || !Co::exists($cid)) {
            self::addGlobal($key, $value);
            return;
        }

        $swContext = Co::getContext();
        if (!isset($swContext['log_context'])) {
            $swContext['log_context'] = [];
        }
        $swContext['log_context'][$key] = $value;
    }

    /**
     * 获取完整的上下文（包括 Trace ID、协程 ID 等）
     * 
     * @return array<string, mixed>
     */
    public static function getAll(): array
    {
        $context = [];

        // 全局上下文
        $context = array_merge($context, self::getGlobal());

        // 协程上下文
        if (extension_loaded('swoole')) {
            $cid = Co::getCid();
            if ($cid > 0 && Co::exists($cid)) {
                $context = array_merge($context, self::getCoroutine());
                $context['coroutine_id'] = $cid;
            }
        }

        // 自动添加 Trace ID
        $context['trace_id'] = Trace::getCoroutineTraceId();

        return $context;
    }

    /**
     * 清空全局上下文
     * 
     * @return void
     */
    public static function clearGlobal(): void
    {
        self::$globalContext = [];
    }

    /**
     * 清空协程上下文
     * 
     * @return void
     */
    public static function clearCoroutine(): void
    {
        if (!extension_loaded('swoole')) {
            return;
        }
        
        // 新版 Swoole 需要传递协程 ID 给 exists()
        $cid = Co::getCid();
        if ($cid > 0 && Co::exists($cid)) {
            $context = Co::getContext();
            unset($context['log_context']);
        }
    }

    /**
     * 清空所有上下文
     * 
     * @return void
     */
    public static function clearAll(): void
    {
        self::clearGlobal();
        self::clearCoroutine();
    }
}
