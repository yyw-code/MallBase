<?php

declare(strict_types=1);

namespace app\service\order;

use mall_base\base\BaseService;
use think\facade\Cache;

/**
 * 订单号生成器
 *
 * 规则：6 位日期（YYMMDD）+ 10 位日内递增序列，总长 16 位
 *
 * 并发模型：
 *  - 走 Redis 原子 INCR，单机下天然无冲突
 *  - 每日 key 第一次使用后设置 48h 过期，自然回收无需清理
 *  - 集群环境下若单 Redis 不可用，应在此处扩展雪花 ID（本 MVP 不做）
 *
 * 降级策略：
 *  - Redis 不可用时回落到 microtime + random，保证可用性
 *  - 降级路径仅保证不碰撞，不保证严格递增
 */
class OrderSnGenerator extends BaseService
{
    /**
     * Redis 键前缀
     */
    private const CACHE_PREFIX = 'order_sn_seq:';

    /**
     * 日内序列过期时间（秒），48h 避免跨零点边界
     */
    private const EXPIRE_SECONDS = 86400 * 2;

    /**
     * 生成下一个订单号
     */
    public function next(): string
    {
        $date = date('ymd');
        $seq  = $this->incrDailySeq($date);

        return $date . str_pad((string) $seq, 10, '0', STR_PAD_LEFT);
    }

    /**
     * 原子递增日内序列
     *
     * 优先走 Redis handler->incr / expire；任何异常降级到无状态生成路径
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
     * 降级序列：微秒时间戳 + 随机偏移，填充 10 位
     */
    private function fallbackSeq(): int
    {
        $micro = (int) (microtime(true) * 1000); // 毫秒
        return ($micro % 1_000_000_000) + random_int(0, 999);
    }
}
