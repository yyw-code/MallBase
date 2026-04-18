<?php

declare(strict_types=1);

namespace app\service\order;

use mall_base\base\BaseService;
use think\facade\Cache;

/**
 * 售后单号生成器
 *
 * 规则：R + 6 位日期（YYMMDD）+ 10 位日内递增序列，总长 17 位
 *
 * 设计动机：
 *  - 与 {@see OrderSnGenerator} 完全同构，差异仅在前缀与 Redis 键命名
 *  - 前缀 "R" 让售后单号与订单号一眼可辨，避免混淆
 *  - Redis 键独立（refund_sn_seq:），与订单号序列互不干扰
 *
 * 并发模型 / 降级策略同 {@see OrderSnGenerator}
 */
class RefundSnGenerator extends BaseService
{
    /**
     * 售后单号前缀
     */
    private const PREFIX = 'R';

    /**
     * Redis 键前缀
     */
    private const CACHE_PREFIX = 'refund_sn_seq:';

    /**
     * 日内序列过期时间（秒），48h 避免跨零点边界
     */
    private const EXPIRE_SECONDS = 86400 * 2;

    /**
     * 生成下一个售后单号
     */
    public function next(): string
    {
        $date = date('ymd');
        $seq  = $this->incrDailySeq($date);

        return self::PREFIX . $date . str_pad((string) $seq, 10, '0', STR_PAD_LEFT);
    }

    /**
     * 原子递增日内序列
     */
    private function incrDailySeq(string $date): int
    {
        $key = self::CACHE_PREFIX . $date;

        try {
            $handler = Cache::handler();
            if (is_object($handler) && method_exists($handler, 'incr')) {
                $seq = (int) $handler->incr($key);

                // 第一次递增后设置过期时间，避免键永久驻留
                if ($seq === 1 && method_exists($handler, 'expire')) {
                    $handler->expire($key, self::EXPIRE_SECONDS);
                }

                return $seq;
            }
        } catch (\Throwable $e) {
            // 忽略，进入降级
        }

        return $this->fallbackSeq();
    }

    /**
     * 降级序列：毫秒时间戳 + 随机偏移，填充 10 位
     */
    private function fallbackSeq(): int
    {
        $micro = (int) (microtime(true) * 1000);
        return ($micro % 1_000_000_000) + random_int(0, 999);
    }
}
