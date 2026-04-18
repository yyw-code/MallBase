<?php

declare (strict_types=1);

namespace app\service\cache;

use think\facade\Cache;

/**
 * 权限缓存服务
 */
class PermissionCacheService
{
    /**
     * 是否启用缓存
     */
    protected bool $enable = true;

    /**
     * 缓存前缀
     */
    protected string $prefix = 'admin:permissions:';

    /**
     * 缓存有效期（秒）
     */
    protected int $expire = 3600;

    /**
     * 获取用户权限缓存
     *
     * @param int $adminId 管理员ID
     * @return array
     */
    public function get(int $adminId)
    {
        if (!$this->enable) {
            return [];
        }

        $cacheKey = $this->getCacheKey($adminId);

        $cache = Cache::get($cacheKey);
        if (empty($cache) || !is_array($cache)) {
            $cache = [];
        }
        return $cache;
    }

    /**
     * 设置用户权限缓存
     *
     * @param int $adminId 管理员ID
     * @param array $permissions 权限列表
     * @return bool
     */
    public function set(int $adminId, array $permissions): bool
    {
        if (!$this->enable) {
            return true;
        }

        $cacheKey = $this->getCacheKey($adminId);
        return Cache::set($cacheKey, $permissions, $this->expire);
    }

    /**
     * 清除用户权限缓存
     *
     * @param int $adminId 管理员ID
     * @return bool
     */
    public function clearUser(int $adminId): bool
    {
        if (!$this->enable) {
            return true;
        }

        $cacheKey = $this->getCacheKey($adminId);
        return Cache::delete($cacheKey);
    }

    /**
     * 清除所有用户权限缓存
     *
     * @return bool
     */
    public function clearAll(): bool
    {
        if (!$this->enable) {
            return true;
        }

        return Cache::clear($this->prefix);
    }

    /**
     * 清除多个用户的权限缓存
     *
     * @param array $adminIds 管理员ID数组
     * @return void
     */
    public function clearUsers(array $adminIds): void
    {
        if (!$this->enable || empty($adminIds)) {
            return;
        }

        foreach ($adminIds as $adminId) {
            $this->clearUser($adminId);
        }
    }

    /**
     * 设置是否启用缓存
     *
     * @param bool $enable
     * @return void
     */
    public function setEnable(bool $enable): void
    {
        $this->enable = $enable;
    }

    /**
     * 设置缓存前缀
     *
     * @param string $prefix
     * @return void
     */
    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    /**
     * 设置缓存有效期
     *
     * @param int $expire
     * @return void
     */
    public function setExpire(int $expire): void
    {
        $this->expire = $expire;
    }

    /**
     * 获取缓存 Key
     *
     * @param int $adminId 管理员ID
     * @return string
     */
    protected function getCacheKey(int $adminId): string
    {
        return $this->prefix . $adminId;
    }
}