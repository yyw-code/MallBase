<?php

declare(strict_types=1);

namespace mall_base\sms;

use think\facade\Cache;

/**
 * SmsCache 默认实现:转发到 think\facade\Cache(项目默认 Redis)
 */
final class CacheBackedSmsCache implements SmsCache
{
    public function has(string $key): bool
    {
        return Cache::has($key);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($key, $default);
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        Cache::set($key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        Cache::delete($key);
    }
}
