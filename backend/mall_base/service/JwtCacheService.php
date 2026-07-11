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
    public const GUARD_ADMIN = 'admin';
    public const GUARD_CLIENT = 'client';

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
     * @param string $guard 身份域：admin / client
     * @param string|null $sid 登录会话ID，client 多端登录使用
     */
    public function storeRefreshToken(
        string $token,
        int $userId,
        int $expire,
        string $guard = self::GUARD_ADMIN,
        ?string $sid = null
    ): void
    {
        $cacheKey = $this->buildCacheKey($userId, $guard, $sid);
        Cache::set($cacheKey, $token, $expire);
    }

    /**
     * 验证 refresh_token 是否在 Redis 中
     *
     * @param string $token refresh_token
     * @param int $userId 用户ID
     * @param string $guard 身份域：admin / client
     * @param string|null $sid 登录会话ID，client 多端登录使用
     * @return bool
     */
    public function verifyRefreshToken(
        string $token,
        int $userId,
        string $guard = self::GUARD_ADMIN,
        ?string $sid = null
    ): bool
    {
        $cacheKey = $this->buildCacheKey($userId, $guard, $sid);
        return Cache::get($cacheKey) === $token;
    }

    /**
     * 撤销 refresh_token（用于登出或踢下线）
     *
     * @param int $userId 用户ID
     * @param string $guard 身份域：admin / client
     * @param string|null $sid 登录会话ID，client 多端登录使用
     */
    public function revokeRefreshToken(
        int $userId,
        string $guard = self::GUARD_ADMIN,
        ?string $sid = null
    ): void
    {
        $cacheKey = $this->buildCacheKey($userId, $guard, $sid);
        Cache::delete($cacheKey);
    }

    /**
     * 清除用户所有 Token
     *
     * @param int $userId 用户ID
     * @param string $guard 身份域：admin / client
     * @param string|null $sid 登录会话ID，client 多端登录使用
     */
    public function clearUserTokens(
        int $userId,
        string $guard = self::GUARD_ADMIN,
        ?string $sid = null
    ): void
    {
        $this->revokeRefreshToken($userId, $guard, $sid);
    }

    /**
     * 构造 refresh_token 缓存 key。
     */
    public function buildCacheKey(
        int $userId,
        string $guard = self::GUARD_ADMIN,
        ?string $sid = null
    ): string
    {
        $guard = $this->normalizeGuard($guard);
        $key = $this->cachePrefix . $guard . ':' . $userId;

        if ($sid !== null && trim($sid) !== '') {
            $key .= ':' . trim($sid);
        }

        return $key;
    }

    private function normalizeGuard(string $guard): string
    {
        return match ($guard) {
            self::GUARD_ADMIN,
            self::GUARD_CLIENT => $guard,
            default => throw new \InvalidArgumentException('不支持的 Token 身份域'),
        };
    }
}
