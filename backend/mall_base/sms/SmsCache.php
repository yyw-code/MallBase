<?php

declare(strict_types=1);

namespace mall_base\sms;

/**
 * SMS 子系统使用的最小缓存契约
 *
 * 抽出窄接口便于:
 *  - 单元测试用内存 stub 替代 Redis,不依赖 App 容器
 *  - 未来切换到 PSR-16 / 自带客户端时只换实现
 *
 * 线上默认实现:{@see CacheBackedSmsCache} 包装 think\facade\Cache
 */
interface SmsCache
{
    public function has(string $key): bool;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, int $ttl = 0): void;

    public function delete(string $key): void;
}
