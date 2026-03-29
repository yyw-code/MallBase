<?php

namespace app\admin\service\cache;

use think\facade\Cache;

/**
 * JWT 缓存服务
 *
 * 管理 refresh_token 在 Redis 中的存储、验证和撤销
 */
class JwtCacheService
{
    /**
     * Redis 缓存前缀
     */
    protected string $cachePrefix = 'jwt:refresh:';

    /**
     * 存储 refresh_token 到 Redis
     *
     * @param string $token refresh_token
     * @param int $adminId 管理员ID
     * @param int $expire 过期时间（秒）
     */
    public function storeRefreshToken(string $token, int $adminId, int $expire): void
    {
        $cacheKey = $this->cachePrefix . $adminId;
        Cache::set($cacheKey, $token, $expire);
    }

    /**
     * 验证 refresh_token 是否在 Redis 中
     *
     * @param string $token refresh_token
     * @param int $adminId 管理员ID
     * @return bool
     */
    public function verifyRefreshToken(string $token, int $adminId): bool
    {
        $cacheKey = $this->cachePrefix . $adminId;
        return Cache::get($cacheKey) === $token;
    }

    /**
     * 撤销 refresh_token（用于登出或踢下线）
     *
     * @param int $adminId 管理员ID
     */
    public function revokeRefreshToken(int $adminId): void
    {
        $cacheKey = $this->cachePrefix . $adminId;
        Cache::delete($cacheKey);
    }
}