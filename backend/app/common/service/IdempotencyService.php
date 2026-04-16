<?php

declare(strict_types=1);

namespace app\common\service;

use mall_base\base\BaseService;
use think\facade\Cache;

/**
 * 幂等服务
 *
 * 用于下单、支付回调、敏感表单提交等需要“同 key 同结果”的场景：
 *  1. acquire(scope, key, payload, ttl)：SETNX 抢占，成功返回 true
 *  2. 已存在 key 时返回 false，调用方应通过 recall() 取回历史结果后原路返回
 *  3. bind(scope, key, payload, ttl)：业务执行成功后回写真实 payload（通常是订单 ID/订单号）
 *
 * 典型调用链：
 *   if (!$idem->acquire('order:create', $userKey)) {
 *       return $idem->recall('order:create', $userKey); // 返回已有订单
 *   }
 *   // ... 真正的下单逻辑 ...
 *   $idem->bind('order:create', $userKey, ['order_id' => $orderId]);
 *
 * 抽自 RequestLockMiddleware 的 setnx+expire 模式，统一封装以供订单/支付/退款复用。
 */
class IdempotencyService extends BaseService
{
    /**
     * 缓存键前缀，区分于 request_lock
     */
    private const CACHE_PREFIX = 'idem:';

    /**
     * 默认 TTL（秒）：10 分钟，覆盖下单高峰前后端重试窗口
     */
    public const DEFAULT_TTL = 600;

    /**
     * 占位值，代表“正在处理中”
     */
    private const PLACEHOLDER = '__processing__';

    /**
     * 原子抢占幂等 key
     *
     * @param string $scope 业务域，例如 order:create / order:pay
     * @param string $key   客户端传入的 idempotency_key（UUID）
     * @param int    $ttl   幂等窗口
     *
     * @return bool true=首次请求应继续处理；false=已存在，应走 recall 返回历史结果
     */
    public function acquire(string $scope, string $key, int $ttl = self::DEFAULT_TTL): bool
    {
        $cacheKey = $this->buildKey($scope, $key);

        try {
            $handler = Cache::handler();
            if (is_object($handler) && method_exists($handler, 'setnx') && method_exists($handler, 'expire')) {
                $acquired = (bool) $handler->setnx($cacheKey, self::PLACEHOLDER);
                if (!$acquired) {
                    return false;
                }
                $handler->expire($cacheKey, $ttl);
                return true;
            }
        } catch (\Throwable $e) {
            // 降级到普通缓存锁
        }

        return $this->acquireFallback($cacheKey, $ttl);
    }

    /**
     * 将真实结果绑定到已抢占的幂等 key 上
     *
     * @param string $scope   业务域
     * @param string $key     幂等 key
     * @param array  $payload 要缓存回放的业务结果（通常是订单号/订单 ID）
     * @param int    $ttl     缓存窗口
     */
    public function bind(string $scope, string $key, array $payload, int $ttl = self::DEFAULT_TTL): void
    {
        Cache::set($this->buildKey($scope, $key), json_encode($payload, JSON_UNESCAPED_UNICODE), $ttl);
    }

    /**
     * 取回历史结果
     *
     * @return array|null null = 没找到或仍为 processing 占位
     */
    public function recall(string $scope, string $key): ?array
    {
        $cached = Cache::get($this->buildKey($scope, $key));
        if ($cached === null || $cached === false || $cached === self::PLACEHOLDER) {
            return null;
        }

        if (is_array($cached)) {
            return $cached;
        }

        $decoded = json_decode((string) $cached, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 手动释放幂等 key（业务失败、允许客户端重试时使用）
     */
    public function release(string $scope, string $key): void
    {
        Cache::delete($this->buildKey($scope, $key));
    }

    /**
     * 构造缓存键
     */
    private function buildKey(string $scope, string $key): string
    {
        return self::CACHE_PREFIX . $scope . ':' . $key;
    }

    /**
     * 非原子降级占位
     *
     * 在缓存驱动不支持 setnx 的极端场景下提供基本幂等能力。
     * 安全边界：本降级存在“读-写”窗口，不能防并发同 key 双请求，
     * 生产环境必须部署 Redis。
     */
    private function acquireFallback(string $cacheKey, int $ttl): bool
    {
        try {
            if (Cache::get($cacheKey) !== null && Cache::get($cacheKey) !== false) {
                return false;
            }
            Cache::set($cacheKey, self::PLACEHOLDER, $ttl);
            return true;
        } catch (\Throwable $e) {
            // 缓存完全不可用时，默认放行一次，避免服务级不可用
            return true;
        }
    }
}
