<?php

namespace mall_base\drivers;

use mall_base\base\BaseDriver;
use mall_base\exception\DriverException;
use Swoole\Coroutine as Co;

/**
 * 驱动管理器
 * 
 * 支持 Swoole 协程环境的驱动管理
 * 
 * 功能说明：
 * - 管理多种驱动的注册和获取
 * - 支持驱动实例缓存（协程级别）
 * - 支持驱动配置管理
 * - 提供统一的驱动切换接口
 * - 协程安全
 * 
 * 使用场景：
 * - 短信服务：根据配置切换不同的短信平台
 * - 文件上传：根据配置切换不同的云存储平台
 * - 支付服务：根据支付方式切换不同的支付平台
 * 
 * 设计理念：
 * - 集中管理所有驱动
 * - 支持动态注册和获取驱动
 * - 缓存驱动实例，提高性能
 * - 灵活配置，易于扩展
 * - 协程环境安全
 * 
 * 使用示例：
 * ```php
 * use mall_base\drivers\DriverManager;
 * 
 * // 配置驱动映射
 * DriverManager::register('sms', [
 *     'aliyun' => \mall_base\drivers\sms\AliyunSmsDriver::class,
 * ]);
 * 
 * // 获取驱动实例
 * $smsDriver = DriverManager::driver('sms', 'aliyun', $config);
 * $smsDriver->send('13800138000', '1234');
 * ```
 */
class DriverManager
{
    /**
     * 驱动映射（全局共享）
     * @var array<string, array<string, string>>
     */
    protected static array $drivers = [];

    /**
     * 驱动实例缓存（协程级别）
     * @var array<string, array<string, BaseDriver>>
     */
    protected static array $instances = [];

    /**
     * 默认驱动
     * @var array
     */
    protected static array $defaults = [];

    /**
     * 注册驱动
     * 
     * @param string $type 驱动类型（如：sms、upload、payment）
     * @param array $drivers 驱动映射 [驱动名 => 驱动类名]
     * @return void
     */
    public static function register(string $type, array $drivers): void
    {
        foreach ($drivers as $name => $driverClass) {
            self::$drivers[$type][$name] = $driverClass;
        }
    }

    /**
     * 获取已注册驱动映射。
     *
     * @return array<string, string>
     */
    public static function getRegisteredDrivers(string $type): array
    {
        return self::$drivers[$type] ?? [];
    }

    /**
     * 设置默认驱动
     * 
     * @param string $type 驱动类型
     * @param string $name 驱动名称
     * @return void
     */
    public static function setDefault(string $type, string $name): void
    {
        self::$defaults[$type] = $name;
    }

    /**
     * 获取默认驱动名称
     * 
     * @param string $type 驱动类型
     * @return string|null
     */
    public static function getDefault(string $type): ?string
    {
        return self::$defaults[$type] ?? null;
    }

    /**
     * 获取驱动实例（支持协程）
     * 
     * @param string $type 驱动类型
     * @param string|null $name 驱动名称（为空则使用默认驱动）
     * @param array $config 驱动配置
     * @param bool $cache 是否缓存实例
     * @return BaseDriver
     * @throws DriverException
     */
    public static function driver(string $type, ?string $name = null, array $config = [], bool $cache = true): BaseDriver
    {
        // 使用默认驱动
        if ($name === null) {
            $name = self::getDefault($type);
        }

        if ($name === null) {
            throw new DriverException("未设置 {$type} 的默认驱动");
        }

        // 检查驱动是否注册
        if (!isset(self::$drivers[$type][$name])) {
            throw new DriverException("驱动不存在: {$type}.{$name}");
        }

        $driverClass = self::$drivers[$type][$name];
        $cacheKey = "driver.{$type}.{$name}";

        // 使用缓存的实例（协程级别）
        if ($cache) {
            $instance = self::getCachedInstance($cacheKey);
            if ($instance !== null) {
                return $instance;
            }
        }

        // 检查驱动类是否存在
        if (!class_exists($driverClass)) {
            throw new DriverException("驱动类不存在: {$driverClass}");
        }

        // 创建驱动实例
        $instance = new $driverClass($config);

        // 检查是否继承自 BaseDriver
        if (!($instance instanceof BaseDriver)) {
            throw new DriverException("驱动类必须继承自 BaseDriver: {$driverClass}");
        }

        // 缓存实例（协程级别）
        if ($cache) {
            self::setCachedInstance($cacheKey, $instance);
        }

        return $instance;
    }

    /**
     * 获取缓存的驱动实例（协程级别）
     * 
     * @param string $key 缓存键
     * @return BaseDriver[]|null
     */
    private static function getCachedInstance(string $key): ?BaseDriver
    {
        if (self::isCoroutineContext()) {
            return Co::getContext()[$key] ?? null;
        }
        return self::$instances[$key] ?? null;
    }

    /**
     * 设置缓存的驱动实例（协程级别）
     * 
     * @param string $key 缓存键
     * @param BaseDriver $instance 驱动实例
     * @return void
     */
    private static function setCachedInstance(string $key, BaseDriver $instance): void
    {
        if (self::isCoroutineContext()) {
            Co::getContext()[$key] = $instance;
        } else {
            self::$instances[$key] = $instance;
        }
    }

    /**
     * 检查是否在协程上下文中
     * 
     * @return bool
     */
    private static function isCoroutineContext(): bool
    {
        if (!extension_loaded('swoole')) {
            return false;
        }
        
        try {
            // Swoole 4.x 版本
            if (method_exists(Co::class, 'getuid')) {
                return Co::getuid() > 0;
            }
            
            // Swoole 5.x 版本或兼容版本
            $reflection = new \ReflectionMethod(Co::class, 'exists');
            $params = $reflection->getNumberOfParameters();
            
            if ($params === 0) {
                return Co::exists();
            } else {
                // 旧版本需要传递参数
                return Co::exists(-1);
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取驱动实例（不使用缓存）
     * 
     * @param string $type 驱动类型
     * @param string|null $name 驱动名称
     * @param array $config 驱动配置
     * @return BaseDriver
     */
    public static function create(string $type, ?string $name = null, array $config = []): BaseDriver
    {
        return self::driver($type, $name, $config, false);
    }

    /**
     * 清除缓存
     * 
     * @param string|null $type 驱动类型（为空则清除所有）
     * @param string|null $name 驱动名称（为空则清除该类型下所有驱动）
     * @return void
     */
    public static function clearCache(?string $type = null, ?string $name = null): void
    {
        if ($type === null) {
            // 清除所有缓存
            self::$instances = [];
        } elseif ($name === null) {
            // 清除指定类型的所有驱动缓存
            foreach (array_keys(self::$instances) as $key) {
                if (str_starts_with($key, "driver.{$type}.")) {
                    unset(self::$instances[$key]);
                }
            }
        } else {
            // 清除指定驱动的缓存
            $cacheKey = "driver.{$type}.{$name}";
            unset(self::$instances[$cacheKey]);
        }
    }

    /**
     * 获取已注册的驱动列表
     * 
     * @param string|null $type 驱动类型（为空则返回所有）
     * @return array
     */
    public static function getDrivers(?string $type = null): array
    {
        if ($type === null) {
            return self::$drivers;
        }

        return self::$drivers[$type] ?? [];
    }

    /**
     * 检查驱动是否注册
     * 
     * @param string $type 驱动类型
     * @param string $name 驱动名称
     * @return bool
     */
    public static function hasDriver(string $type, string $name): bool
    {
        return isset(self::$drivers[$type][$name]);
    }
}
