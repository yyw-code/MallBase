<?php

namespace mall_base\service;

use think\facade\Cache;

/**
 * JWT 缓存服务（公共层）
 *
 * 管理 refresh_token 在 Redis 中的存储、验证和撤销
 * admin 和 client 模块均可使用
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
     * @param int $userId 用户ID
     * @param int $expire 过期时间（秒）
     */
    public function storeRefreshToken(string $token, int $userId, int $expire): void
    {
        $cacheKey = $this->cachePrefix . $userId;
        Cache::set($cacheKey, $token, $expire);
    }

    /**
     * 验证 refresh_token 是否在 Redis 中
     *
     * @param string $token refresh_token
     * @param int $userId 用户ID
     * @return bool
     */
    public function verifyRefreshToken(string $token, int $userId): bool
    {
        $cacheKey = $this->cachePrefix . $userId;
        return Cache::get($cacheKey) === $token;
    }

    /**
     * 撤销 refresh_token（用于登出或踢下线）
     *
     * @param int $userId 用户ID
     */
    public function revokeRefreshToken(int $userId): void
    {
        $cacheKey = $this->cachePrefix . $userId;
        Cache::delete($cacheKey);
    }

    /**
     * 清除用户所有 Token
     *
     * @param int $userId 用户ID
     */
    public function clearUserTokens(int $userId): void
    {
        $this->revokeRefreshToken($userId);
    }
}
