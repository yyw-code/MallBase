<?php

namespace mall_base\base;

use mall_base\log\Logger;
use mall_base\log\LogContext;
use mall_base\log\Trace;
use Throwable;

/**
 * 驱动基类
 * 
 * 支持 Swoole 协程环境的驱动基类
 * 
 * 功能说明：
 * - 提供驱动层的统一抽象接口
 * - 支持多平台驱动切换（如短信、文件上传等）
 * - 提供驱动配置管理
 * - 提供驱动实例缓存
 * - 协程安全
 * - 自动异常捕获和日志记录
 * 
 * 使用场景：
 * - 短信服务：阿里云等
 * - 文件上传：OSS、COS、七牛云等
 * - 支付服务：微信支付等
 * 
 * 设计理念：
 * - 定义统一的接口规范
 * - 子类实现具体的平台逻辑
 * - 通过配置灵活切换驱动
 * - 支持驱动实例缓存
 * - 协程环境安全
 * 
 * 使用示例：
 * ```php
 * // 定义短信驱动基类
 * abstract class BaseSmsDriver extends BaseDriver
 * {
 *     abstract public function send(string $phone, string $code): bool;
 * }
 * 
 * // 实现阿里云短信驱动
 * class AliyunSmsDriver extends BaseSmsDriver
 * {
 *     public function send(string $phone, string $code): bool
 *     {
 *         // 实现阿里云发送逻辑
 *     }
 * }
 * 
 * // 使用驱动
 * $driver = new AliyunSmsDriver(['access_key' => 'xxx']);
 * $driver->send('13800138000', '1234');
 * ```
 */
abstract class BaseDriver
{
    /**
     * 驱动配置
     * @var array<string, mixed>
     */
    protected array $config = [];

    /**
     * 错误信息
     * @var string|null
     */
    protected ?string $error = null;

    /**
     * 错误码
     * @var int|null
     */
    protected ?int $errorCode = null;

    /**
     * 驱动类型
     * @var string|null
     */
    protected ?string $driverType = null;

    /**
     * 驱动名称
     * @var string|null
     */
    protected ?string $driverName = null;

    /**
     * 构造函数
     * 
     * @param array<string, mixed> $config 驱动配置
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->driverType = $config['driver_type'] ?? null;
        $this->driverName = $config['driver_name'] ?? null;
        $this->init();
    }

    /**
     * 初始化驱动
     * 子类可以重写此方法进行初始化操作
     */
    protected function init(): void
    {
        // 子类可重写
    }

    /**
     * 获取配置项
     * 
     * @param string $key 配置键名（支持点号分割的嵌套键名）
     * @param mixed $default 默认值
     * @return mixed
     */
    protected function getConfig(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * 设置配置项
     * 
     * @param string $key 配置键名
     * @param mixed $value 配置值
     * @return self
     */
    protected function setConfig(string $key, $value): self
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $k) {
            if (!isset($config[$k]) || !is_array($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }

        $config = $value;

        return $this;
    }

    /**
     * 获取所有配置
     * 
     * @return array
     */
    public function getAllConfig(): array
    {
        return $this->config;
    }

    /**
     * 设置错误信息
     * 
     * @param string $message 错误消息
     * @param int|null $code 错误码
     * @return self
     */
    protected function setError(string $message, ?int $code = null): self
    {
        $this->error = $message;
        $this->errorCode = $code;
        return $this;
    }

    /**
     * 获取错误信息
     * 
     * @return string|null
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * 获取错误码
     * 
     * @return int|null
     */
    public function getErrorCode(): ?int
    {
        return $this->errorCode;
    }

    /**
     * 是否有错误
     * 
     * @return bool
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * 清除错误信息
     * 
     * @return self
     */
    protected function clearError(): self
    {
        $this->error = null;
        $this->errorCode = null;
        return $this;
    }

    /**
     * 获取日志记录器
     * 
     * @return Logger
     */
    protected function logger(): Logger
    {
        return Logger::instance('Driver', static::class);
    }

    /**
     * 记录日志（快捷方法）
     * 
     * @param string $message 日志消息
     * @param string $level 日志级别
     * @param array<string, mixed> $context 上下文数据
     * @return void
     */
    protected function log(string $message, string $level = 'info', array $context = []): void
    {
        $logger = $this->logger();
        $logger->$level($message, $context);
    }

    /**
     * 检查是否在协程上下文中
     * 
     * @return bool
     */
    public function isCoroutine(): bool
    {
        $context = LogContext::getAll();
        return isset($context['coroutine_id']);
    }

    /**
     * 获取 Trace ID
     * 
     * @return string
     */
    public function getTraceId(): string
    {
        return Trace::getCoroutineTraceId();
    }

    /**
     * 获取驱动类型
     * 
     * @return string|null
     */
    public function getDriverType(): ?string
    {
        return $this->driverType;
    }

    /**
     * 获取驱动名称
     * 
     * @return string|null
     */
    public function getDriverName(): ?string
    {
        return $this->driverName;
    }

    /**
     * 获取驱动信息
     * 
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver_type' => $this->driverType,
            'driver_name' => $this->driverName,
            'error' => $this->error,
            'error_code' => $this->errorCode,
            'is_coroutine' => $this->isCoroutine(),
        ];
    }

    /**
     * 安全执行方法（自动捕获异常并记录日志）
     * 
     * 使用场景：子类方法中调用，自动捕获异常并记录日志
     * 
     * @param callable $callback 要执行的回调
     * @param string $action 操作描述（用于日志）
     * @return mixed
     */
    protected function safeCall(callable $callback, string $action = '操作')
    {
        try {
            $this->logger()->info("开始{$action}");
            $result = $callback();
            $this->logger()->success("完成{$action}");
            return $result;
        } catch (Throwable $e) {
            $this->setError($e->getMessage(), $e->getCode());
            $this->logger()->driverError($e, $this->driverType ?? 'unknown', $this->driverName ?? 'unknown');
            return false;
        }
    }
}
