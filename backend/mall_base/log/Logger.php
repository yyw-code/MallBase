<?php

namespace mall_base\log;

use think\facade\Log;
use think\facade\App;
use Throwable;

/**
 * 统一日志记录器
 *
 * 功能说明：
 * - 统一日志格式，解决日志乱七八糟、格式不统一的问题
 * - 自动根据 APP_DEBUG 环境调整日志级别和详细程度
 * - 自动集成 Trace ID、协程 ID 等上下文信息
 * - 支持不同模块的日志分类
 * - 提供友好的日志输出格式
 * - 支持协程级别的实例缓存，提升性能
 *
 * 使用场景：
 * - 队列任务日志（BaseJob）
 * - 驱动操作日志（BaseDriver）
 * - 业务逻辑日志
 * - 异常错误日志
 *
 * 设计理念：
 * - 统一的日志格式，便于查看和分析
 * - 开发环境显示详细信息，生产环境只记录关键信息
 * - 自动收集上下文信息，减少手动记录的工作量
 * - 支持结构化日志，便于日志分析工具处理
 * - 协程环境下缓存实例，避免重复创建，提升性能
 * - 高性能优化：避免 __callStatic，减少 array_merge，完整重置状态
 *
 * 日志格式示例：
 * ```
 * [2024-01-25 03:00:00] [INFO] [Job] [SendEmailJob] 开始发送邮件到: user@example.com
 * ├─ Trace ID: trace_65b0e8f0a1b2c
 * ├─ 协程 ID: 1
 * └─ 数据: {"email":"user@example.com"}
 * ```
 *
 *
 * @method static void emergency(string|\Stringable $message, array $context = []) 记录emergency信息
 * @method static void alert(string|\Stringable $message, array $context = []) 记录警报信息
 * @method static void critical(string|\Stringable $message, array $context = []) 记录紧急情况
 * @method static void error(string|\Stringable $message, array $context = []) 记录错误信息
 * @method static void warning(string|\Stringable $message, array $context = []) 记录warning信息
 * @method static void notice(string|\Stringable $message, array $context = []) 记录notice信息
 * @method static void info(string|\Stringable $message, array $context = []) 记录一般信息
 * @method static void debug(string|\Stringable $message, array $context = []) 记录调试信息
 * @method static void sql(string|\Stringable $message, array $context = []) 记录sql信息
 */
class Logger
{
    /** @var string 模块名称 */
    protected string $module = '';

    /** @var string|null 类名 */
    protected ?string $className = null;

    /** @var string|null 日志通道 */
    protected ?string $channel = null;

    /** @var float 开始时间 */
    protected float $startTime = 0;

    /** @var array<string, mixed> 额外上下文（传递给日志系统） */
    protected array $context = [];

    /** @var array<string, mixed> 日志消息中显示的数据（始终显示） */
    protected array $data = [];

    /** @var bool 是否为调试模式 */
    protected bool $debugMode = false;

    /**
     * 私有构造函数
     *
     * @param string $module 模块名称
     * @param string|null $className 类名
     * @param string|null $channel 日志通道
     */
    protected function __construct(string $module, ?string $className = null, ?string $channel = null)
    {
        $this->module = $module;
        $this->className = $className;
        $this->channel = $channel;
        $this->debugMode = App::get('app')->isDebug();

        // 自动初始化 Trace ID（如果还没初始化）
        if (Trace::getCoroutineTraceId() === '') {
            Trace::setCoroutineTraceId(Trace::id());
        }

        // 自动添加类名到上下文（如果提供了类名）
        if ($className !== null) {
            LogContext::addGlobal('class_name', $className);
        }
    }

    // ==================== 静态快捷方法（使用 __callStatic 魔术方法）====================

    /**
     * 静态方法调用魔术方法
     *
     * 支持：Logger::info(), Logger::error(), Logger::success(), Logger::exception() 等
     *
     * @param string $name 方法名
     * @param array $arguments 参数
     * @return void
     */
    public static function __callStatic(string $name, array $arguments): void
    {
        $instance = self::instance();
        $methodName = '_' . $name;

        if (method_exists($instance, $methodName)) {
            call_user_func_array([$instance, $methodName], $arguments);
            return;
        }

        throw new \BadMethodCallException("Method {$name} does not exist.");
    }

    // ==================== 静态工厂方法 ====================

    /**
     * 获取日志实例（支持协程级别的实例缓存）
     *
     * 在 Swoole 协程环境下，会缓存 Logger 实例到协程上下文中，
     * 避免重复创建实例，提升性能。
     *
     * 支持自动识别调用类名：
     * - 如果 $className 为 null，自动从调用栈获取类名
     * - 如果 $module 为空字符串，自动从类名的 namespace 提取模块名
     *
     * 支持指定日志通道：
     * - $channel 参数可以指定日志通道，如 'elasticsearch', 'redis' 等
     * - 如果不指定，使用默认通道
     *
     * @param string $module 模块名称（默认空字符串，自动识别）
     * @param string|null $className 类名（默认 null，自动识别）
     * @param string|null $channel 日志通道（默认 null，使用默认通道）
     * @return self
     */
    public static function instance(string $module = '', ?string $className = null, ?string $channel = null): self
    {
        // 自动识别调用类名
        if ($className === null) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT, 3);

            // 查找调用 instance() 的类（跳过 Logger 自身）
            foreach ($trace as $frame) {
                if (isset($frame['class']) && $frame['class'] !== __CLASS__) {
                    $className = $frame['class'];
                    break;
                }
            }

            // 如果没找到类名，使用当前执行的文件名作为标识
            if ($className === null && isset($trace[0]['file'])) {
                $className = basename($trace[0]['file'], '.php');
            }
        }

        // 自动识别模块名（从类名的 namespace 提取）
        if ($module === '' && $className !== null) {
            // 从类名中提取最后一段作为模块名
            // 例如：app\service\UserService -> Service
            // 例如：mall_base\base\BaseJob -> BaseJob
            $parts = explode('\\', $className);
            $module = end($parts);
        }

        // 如果还是没有模块名，使用默认值
        if ($module === '') {
            $module = 'App';
        }

        $cacheKey = 'logger_' . $module . '_' . ($className ?? 'null') . '_' . ($channel ?? 'default');

        // 检查是否有协程级别的缓存
        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            $context = \Swoole\Coroutine::getContext();
            if (isset($context[$cacheKey])) {
                $logger = $context[$cacheKey];
                // 完全重置状态（避免污染）
                $logger->reset();
                return $logger;
            }
            // 创建新实例并缓存
            $logger = new self($module, $className, $channel);
            $context[$cacheKey] = $logger;
            return $logger;
        }

        // 非协程环境：直接创建新实例
        return new self($module, $className, $channel);
    }

    /**
     * 清除协程级别的日志实例缓存
     *
     * 在协程结束时调用，释放内存
     *
     * @return void
     */
    public static function clearCoroutineCache(): void
    {
        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            $context = \Swoole\Coroutine::getContext();
            foreach ($context as $key => $value) {
                if (strpos($key, 'logger_') === 0) {
                    unset($context[$key]);
                }
            }
        }
    }


    // ====================  外部调用  ====================

    /**
     * 记录成功日志（实例方法，支持链式调用）
     *
     * @param string|\Stringable $message 日志消息
     * @param array<string, mixed> $context 上下文数据
     * @return self
     */
    public function success(string|\Stringable $message, array $context = []): self
    {
        $this->log('success', $message, $context);
        return $this;
    }

    /**
     * 记录失败日志（内部方法）
     *
     * @param string|\Stringable $message
     * @param array<string, mixed> $context 上下文数据
     * @return self
     */
    public function fail(string|\Stringable $message, array $context = []): self
    {
        $this->log('fail', $message, $context);
        return $this;
    }

    /**
     * 记录调试日志
     *
     * @param string|\Stringable $message 日志消息
     * @param array<string, mixed> $context 上下文数据
     * @return self
     */
    public function debug(string|\Stringable $message, array $context = []): self
    {
        if ($this->debugMode) {
            $this->log('debug', $message, $context);
        }
        return $this;
    }

    /**
     * 记录信息日志（实例方法，支持链式调用）
     *
     * @param string|\Stringable $message 日志消息
     * @param array<string, mixed> $context 上下文数据
     * @return self
     */
    public function info(string|\Stringable $message, array $context = []): self
    {
        $this->log('info', $message, $context);
        return $this;
    }


    /**
     * 记录警告日志（实例方法，支持链式调用）
     *
     * @param string|\Stringable $message
     * @param array<string, mixed> $context 上下文数据
     * @return self
     */
    public function warning(string|\Stringable $message, array $context = []): self
    {
        $this->log('warning', $message, $context);
        return $this;
    }

    /**
     * 记录错误日志（内部方法）
     *
     * @param string|\Stringable $message 日志消息
     * @param array<string, mixed> $context 上下文数据
     * @return self
     */
    public function error(string|\Stringable $message, array $context = []): self
    {
        $this->log('error', $message, $context);
        return $this;
    }


    /**
     * 记录异常（实例方法，支持链式调用）
     *
     * @param Throwable $exception 异常对象
     * @param string $message 附加消息
     * @return self
     */
    public function exception(Throwable $exception, string $message = ''): self
    {
        $context = [
            'exception_class' => get_class($exception),
            'exception_code' => $exception->getCode(),
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
        ];

        // 调试模式下包含堆栈信息
        if ($this->debugMode) {
            $context['exception_trace'] = $exception->getTraceAsString();
        }

        $fullMessage = $message ? $message . ': ' . $exception->getMessage() : $exception->getMessage();

        $this->error($fullMessage, $context);

        return $this;
    }

    /**
     * 记录开始日志
     *
     * @param string $action 操作描述
     * @return self
     */
    public function start(string $action): self
    {
        $this->startTime = microtime(true);
        return $this->info("开始{$action}");
    }

    /**
     * 记录完成日志
     *
     * @param string $action 操作描述
     * @return self
     */
    public function complete(string $action): self
    {
        return $this->success("完成{$action}");
    }


    /**
     * 记录任务异常日志（BaseJob 使用）
     *
     * @param Throwable $e 异常对象
     * @param string|null $customMessage 自定义错误消息
     * @return self
     */
    public function jobError(Throwable $e, ?string $customMessage = null): self
    {
        $message = $customMessage ?? $e->getMessage();
        $context = [
            'exception_class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
        ];

        $this->error($message, $context);
        return $this;
    }

    /**
     * 记录驱动异常日志（BaseDriver 使用）
     *
     * @param Throwable $e 异常对象
     * @param string $driverType 驱动类型
     * @param string $driverName 驱动名称
     * @return self
     */
    public function driverError(Throwable $e, string $driverType, string $driverName): self
    {
        $message = "驱动异常 [{$driverType}.{$driverName}]: " . $e->getMessage();
        $context = [
            'exception_class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
            'driver_type' => $driverType,
            'driver_name' => $driverName,
        ];

        $this->error($message, $context);
        return $this;
    }


    // ====================  属性  ====================

    /**
     * 设置额外上下文（传递给日志系统）
     *
     * 注意：这些数据不会直接显示在日志消息中，而是作为结构化数据
     * 传递给 ThinkPHP 日志系统，用于日志分析工具等
     *
     * @param array<string, mixed> $context
     * @return self
     */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    /**
     * 设置日志消息中显示的数据（始终显示）
     *
     * 这些数据会直接显示在日志消息中，无论 APP_DEBUG 是否开启
     * 适合显示关键的业务数据，如 worker_id、task_name 等
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public function withData(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * 设置日志通道
     *
     * @param string $channel 通道名称
     * @return self
     */
    public function withChannel(string $channel): self
    {
        $this->channel = $channel;
        return $this;
    }

    // ==================== 实例方法（内部方法，以下划线开头）====================

    /**
     * 重置实例状态（性能优化：避免状态污染）
     *
     * 在复用 Logger 实例时调用，确保每次调用都是独立的状态
     *
     * @return void
     */
    protected function reset(): void
    {
        $this->context = [];
        $this->data = [];
        $this->startTime = 0;
        // 注意：不重置 className 和 module，因为这些是实例的固有属性
    }


    /**
     * 记录日志（性能优化：统一合并上下文，便于未来优化）
     *
     * @param string $level 日志级别
     * @param string|\Stringable $message 日志消息
     * @param array<string, mixed> $context 上下文数据
     * @return void
     */
    protected function log(string $level, string|\Stringable $message, array $context = []): void
    {
        // 统一在 log() 中合并上下文，便于未来优化 / 缓存
        // 优先级：调用时传入的 > 实例的 > 全局的
        $allContext = array_merge(
            LogContext::getAll(),  // 全局上下文（协程级别）
            $this->context,         // 实例上下文
            $context                // 调用时传入的上下文
        );

        // 格式化日志消息
        $logMessage = $this->formatMessage($message);

        // 记录到 ThinkPHP 日志
        if ($this->channel) {
            Log::channel($this->channel)->$level($logMessage, $allContext);
        } else {
            Log::$level($logMessage, $allContext);
        }
    }

    /**
     * 格式化日志消息
     *
     * @param string $message 原始消息
     * @return string
     */
    protected function formatMessage(string $message): string
    {
        $lines = [];

        // 主日志行
        $module = $this->module ? sprintf('[%s]', $this->module) : '';
        $class = $this->className ? sprintf('[%s]', $this->className) : '';

        $lines[] = sprintf('%s%s %s', $module, $class, $message);

        // 始终显示 data（业务数据）
        if (!empty($this->data)) {
            $jsonData = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[] = sprintf('└─ 数据: %s', $jsonData);
        }

        // 调试模式下显示详细信息
        if ($this->debugMode) {
            $lines[] = $this->formatDebugInfo();
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * 格式化调试信息
     *
     * @return string
     */
    protected function formatDebugInfo(): string
    {
        $lines = [];

        // Trace ID
        $traceId = Trace::getCoroutineTraceId();
        if ($traceId) {
            $lines[] = sprintf('├─ Trace ID: %s', $traceId);
        }

        // 协程 ID
        $coroutineId = $this->getCoroutineId();
        if ($coroutineId !== null) {
            $lines[] = sprintf('├─ 协程 ID: %d', $coroutineId);
        }

        // 执行时间
        if ($this->startTime > 0) {
            $duration = round(microtime(true) - $this->startTime, 3);
            $lines[] = sprintf('├─ 执行时间: %ss', number_format($duration, 3));
        }

        // 上下文数据（如果有额外上下文）
        if (!empty($this->context)) {
            $jsonData = json_encode($this->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[] = sprintf('└─ 上下文: %s', $jsonData);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * 获取协程 ID
     *
     * @return int|null
     */
    protected function getCoroutineId(): ?int
    {
        if (extension_loaded('swoole')) {
            $context = LogContext::getAll();
            return $context['coroutine_id'] ?? null;
        }
        return null;
    }


}
