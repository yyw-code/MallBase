<?php

namespace mall_base\log;

use Swoole\Coroutine as Co;

/**
 * 链路追踪类
 * 
 * 功能说明：
 * - 生成和管理 Trace ID
 * - 支持协程环境下的 Trace ID 隔离
 * - 支持跨服务调用传递 Trace ID
 * 
 * 使用场景：
 * - 分布式追踪
 * - 日志关联
 * - 问题定位
 * 
 * 设计理念：
 * - 一次请求生成一个 Trace ID
 * - 协程环境下每个协程可以独立追踪
 * - 支持手动设置和自动生成
 */
class Trace
{
    /** @var string|null Trace ID */
    protected static ?string $traceId = null;

    /**
     * 获取 Trace ID
     * 
     * @return string
     */
    public static function id(): string
    {
        if (self::$traceId === null) {
            self::$traceId = self::generate();
        }
        
        return self::$traceId;
    }

    /**
     * 设置 Trace ID
     * 
     * @param string $traceId
     * @return void
     */
    public static function set(string $traceId): void
    {
        self::$traceId = $traceId;
    }

    /**
     * 重置 Trace ID
     * 
     * @return void
     */
    public static function reset(): void
    {
        self::$traceId = null;
    }

    /**
     * 生成 Trace ID
     * 
     * @return string
     */
    protected static function generate(): string
    {
        return uniqid('trace_', true);
    }

    /**
     * 获取协程级别的 Trace ID（协程环境）
     * 
     * @return string|null
     */
    public static function getCoroutineTraceId(): ?string
    {
        if (!extension_loaded('swoole') || !Co::exists()) {
            return self::id();
        }

        $context = Co::getContext();
        return $context['trace_id'] ?? self::id();
    }

    /**
     * 设置协程级别的 Trace ID
     * 
     * @param string $traceId
     * @return void
     */
    public static function setCoroutineTraceId(string $traceId): void
    {
        if (extension_loaded('swoole') && Co::exists()) {
            $context = Co::getContext();
            $context['trace_id'] = $traceId;
        } else {
            self::set($traceId);
        }
    }
}
