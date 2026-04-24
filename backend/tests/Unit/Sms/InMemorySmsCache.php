<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use mall_base\sms\SmsCache;

/**
 * 内存型 SmsCache 实现,仅用于单元测试
 *
 * 特性:
 *  - 不实现 TTL 倒计时(测试用例不依赖时间流逝)
 *  - 提供 forget() 用于测试中精准清理某个 key
 */
final class InMemorySmsCache implements SmsCache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->store[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }

    /** 测试辅助 */
    public function forget(string $key): void
    {
        unset($this->store[$key]);
    }
}
