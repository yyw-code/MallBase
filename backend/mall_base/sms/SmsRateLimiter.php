<?php

declare(strict_types=1);

namespace mall_base\sms;

/**
 * 短信频控
 *
 * 三层防刷:
 *  1) 同手机号 60s 内最多 1 次
 *  2) 同手机号 24h 内最多 N 次(可配置,默认 5)
 *  3) 同 IP 1min 内最多 M 次(可配置,默认 3)
 *
 * 命中任一限制就抛 SmsException,业务方收到后向用户提示并返回 429 等友好错误。
 *
 * 数据存储:
 *  - 通过 SmsCache 抽象,默认实现转发到 think\facade\Cache(项目默认 Redis)
 *  - key 格式:sms:rl:<scope>:<key>:<window>,过期由后端 TTL 自动回收
 */
final class SmsRateLimiter
{
    private const KEY_PREFIX = 'sms:rl:';

    /** 同手机号 60s 内最多 1 次 */
    private const MOBILE_INTERVAL_TTL = 60;

    /** 同手机号 24h 默认次数上限 */
    private const MOBILE_DAILY_TTL = 86400;

    /** 同 IP 1min 默认次数上限 */
    private const IP_MINUTE_TTL = 60;

    public function __construct(
        private readonly SmsCache $cache,
        private readonly int $mobileDailyLimit = 5,
        private readonly int $ipMinuteLimit = 3,
    ) {
    }

    /**
     * 在发送前调用。命中限制就抛 SmsException
     *
     * @throws SmsException
     */
    public function assertCanSend(string $mobile, string $ip = ''): void
    {
        // 1. 同手机号 60s
        $intervalKey = self::KEY_PREFIX . 'mobile_interval:' . $mobile;
        if ($this->cache->has($intervalKey)) {
            throw new SmsException('验证码请求过于频繁,请 60 秒后再试');
        }

        // 2. 同手机号 24h
        $dailyKey = self::KEY_PREFIX . 'mobile_daily:' . $mobile . ':' . date('Ymd');
        $dailyCount = (int) $this->cache->get($dailyKey, 0);
        if ($dailyCount >= $this->mobileDailyLimit) {
            throw new SmsException(sprintf('该手机号今日已超过 %d 次验证码上限', $this->mobileDailyLimit));
        }

        // 3. 同 IP 1min
        if ($ip !== '') {
            $ipKey = self::KEY_PREFIX . 'ip_minute:' . $ip;
            $ipCount = (int) $this->cache->get($ipKey, 0);
            if ($ipCount >= $this->ipMinuteLimit) {
                throw new SmsException('该 IP 验证码请求过于频繁,请稍后再试');
            }
        }
    }

    /**
     * 在发送成功后调用,更新三个计数器
     */
    public function record(string $mobile, string $ip = ''): void
    {
        $intervalKey = self::KEY_PREFIX . 'mobile_interval:' . $mobile;
        $this->cache->set($intervalKey, 1, self::MOBILE_INTERVAL_TTL);

        $dailyKey = self::KEY_PREFIX . 'mobile_daily:' . $mobile . ':' . date('Ymd');
        $current = (int) $this->cache->get($dailyKey, 0);
        $this->cache->set($dailyKey, $current + 1, self::MOBILE_DAILY_TTL);

        if ($ip !== '') {
            $ipKey = self::KEY_PREFIX . 'ip_minute:' . $ip;
            $current = (int) $this->cache->get($ipKey, 0);
            $this->cache->set($ipKey, $current + 1, self::IP_MINUTE_TTL);
        }
    }
}
