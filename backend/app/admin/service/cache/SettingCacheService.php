<?php

namespace app\admin\service\cache;

use think\facade\Cache;

/**
 * 设置缓存服务
 *
 * 缓存结构：
 * - setting:group:{code}  → 分组下所有配置项（key-value 对）
 * - setting:all           → 所有分组列表
 */
class SettingCacheService
{
    /**
     * 缓存前缀
     */
    protected string $groupPrefix = 'setting:group:';
    protected string $allKey = 'setting:all';

    /**
     * 获取分组的缓存 key
     */
    protected function getGroupCacheKey(string $code): string
    {
        return $this->groupPrefix . $code;
    }

    /**
     * 获取分组配置（先走缓存，未命中则回调获取并缓存）
     *
     * @param string $groupCode 分组编码
     * @param callable $callback 回调函数，返回配置数据
     * @return array
     */
    public function getGroupSettings(string $groupCode, callable $callback): array
    {
        $cacheKey = $this->getGroupCacheKey($groupCode);

        return Cache::remember($cacheKey, $callback, 86400); // 缓存1天
    }

    /**
     * 清除指定分组的缓存
     */
    public function clearGroup(string $groupCode): void
    {
        $cacheKey = $this->getGroupCacheKey($groupCode);
        Cache::delete($cacheKey);
    }

    /**
     * 清除所有设置缓存
     */
    public function clearAll(): void
    {
        // 清除分组列表缓存
        Cache::delete($this->allKey);

        // 通过标签清除（如果驱动支持 tag）
        try {
            Cache::tag('setting')->clear();
        } catch (\Exception $e) {
            // 驱动不支持 tag 时忽略
        }
    }

    /**
     * 获取所有分组列表（走缓存）
     */
    public function getAllGroups(callable $callback): array
    {
        return Cache::remember($this->allKey, $callback, 86400);
    }

    /**
     * 获取单个设置项值（先走缓存，未命中则回调获取并缓存）
     *
     * @param string $code 设置项编码
     * @param callable $callback 回调函数
     * @return mixed
     */
    public function getSettingValue(string $code, callable $callback): mixed
    {
        $cacheKey = 'setting:value:' . $code;
        return Cache::remember($cacheKey, $callback, 86400);
    }

    /**
     * 清除单个设置项缓存
     */
    public function clearSettingValue(string $code): void
    {
        Cache::delete('setting:value:' . $code);
    }
}
