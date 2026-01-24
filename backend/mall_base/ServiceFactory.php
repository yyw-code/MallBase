<?php

namespace mall_base;

/**
 * 服务工厂类
 * 
 * 功能说明：
 * - 轻量级的服务实例创建和管理
 * - 避免在 Swoole 模式下使用 ThinkPHP 容器的静态单例问题
 * - 支持简单的实例缓存（可选）
 * - 性能优化，适合高并发场景
 * 
 * 设计理念：
 * - 简单直接，避免复杂的依赖注入逻辑
 * - 不使用静态变量，避免 Swoole 内存问题
 * - 提供缓存机制，避免重复创建
 * 
 * 使用示例：
 * ```php
 * // 创建新实例
 * $userService = ServiceFactory::make(\app\service\UserService::class);
 * 
 * // 带缓存的实例
 * $userService = ServiceFactory::make(\app\service\UserService::class, true);
 * ```
 */
class ServiceFactory
{
    /**
     * 实例缓存（可选）
     * @var array
     */
    private static array $instances = [];
    
    /**
     * 创建服务实例
     * 
     * @param string $serviceName 服务类名
     * @param bool $cache 是否缓存实例
     * @return mixed 服务实例
     */
    public static function make(string $serviceName, bool $cache = false)
    {
        // 如果启用了缓存且实例已存在，直接返回
        if ($cache && isset(self::$instances[$serviceName])) {
            return self::$instances[$serviceName];
        }
        
        // 检查类是否存在
        if (!class_exists($serviceName)) {
            throw new \InvalidArgumentException("Service class not exists: {$serviceName}");
        }
        
        // 使用反射创建实例，支持构造函数参数注入
        try {
            $reflection = new \ReflectionClass($serviceName);
            
            // 检查是否有构造函数
            $constructor = $reflection->getConstructor();
            
            if ($constructor === null) {
                // 无参构造函数
                $instance = new $serviceName();
            } else {
                // 获取构造函数参数
                $params = $constructor->getParameters();
                $args = [];
                
                // 尝试自动解析依赖
                foreach ($params as $param) {
                    $paramType = $param->getType();
                    
                    // 如果有类型提示且不是内置类型
                    if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()) {
                        $paramClassName = $paramType->getName();
                        
                        // 递归创建依赖
                        $args[] = self::make($paramClassName, $cache);
                    } elseif ($param->isDefaultValueAvailable()) {
                        // 使用默认值
                        $args[] = $param->getDefaultValue();
                    } else {
                        // 无法解析的参数，跳过
                        $args[] = null;
                    }
                }
                
                // 创建实例
                $instance = $reflection->newInstanceArgs($args);
            }
        } catch (\ReflectionException $e) {
            throw new \RuntimeException("Failed to create service instance: {$serviceName}", 0, $e);
        }
        
        // 如果启用缓存，保存实例
        if ($cache) {
            self::$instances[$serviceName] = $instance;
        }
        
        return $instance;
    }
    
    /**
     * 创建新实例（不使用缓存）
     * 
     * @param string $serviceName 服务类名
     * @return mixed 服务实例
     */
    public static function create(string $serviceName)
    {
        return self::make($serviceName, false);
    }
    
    /**
     * 获取单例实例（使用缓存）
     * 
     * @param string $serviceName 服务类名
     * @return mixed 服务实例
     */
    public static function singleton(string $serviceName)
    {
        return self::make($serviceName, true);
    }
    
    /**
     * 清除缓存
     * 
     * @param string|null $serviceName 服务类名（为空则清除所有）
     * @return void
     */
    public static function clearCache(?string $serviceName = null): void
    {
        if ($serviceName === null) {
            self::$instances = [];
        } elseif (isset(self::$instances[$serviceName])) {
            unset(self::$instances[$serviceName]);
        }
    }
    
    /**
     * 获取缓存统计信息
     * 
     * @return array
     */
    public static function getCacheStats(): array
    {
        return [
            'cached_count' => count(self::$instances),
            'cached_services' => array_keys(self::$instances),
        ];
    }
}
