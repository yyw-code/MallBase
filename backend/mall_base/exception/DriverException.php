<?php

namespace mall_base\exception;

use mall_base\base\BaseException;

/**
 * 驱动异常类
 * 
 * 用于驱动层面的错误处理，如驱动不存在、配置错误、调用失败等
 * 默认状态码：400
 * 
 * 使用场景：
 * - 驱动未注册
 * - 驱动类不存在
 * - 驱动配置错误
 * - 驱动调用失败
 * - 驱动不支持的错误
 * 
 * 使用示例：
 * ```php
 * throw new DriverException('驱动不存在: sms.aliyun');
 * throw new DriverException('配置错误', 400);
 * throw new DriverException('发送失败', 400, 1001);
 * ```
 */
class DriverException extends BaseException
{
    /**
     * 构造函数
     * 
     * @param string $message 错误消息
     * @param int $statusCode 业务状态码，默认 400
     * @param int $code 错误码，默认 0
     */
    public function __construct(string $message = '驱动操作失败', int $statusCode = 400, int $code = 0)
    {
        parent::__construct($message, $statusCode, $code);
    }
}
