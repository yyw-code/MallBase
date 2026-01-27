<?php

namespace mall_base\base;

/**
 * 异常基类
 * 
 * 所有自定义异常的基类，提供统一的异常处理接口
 * 默认状态码：400
 * 
 * 使用示例：
 * ```php
 * class CustomException extends BaseException
 * {
 *     public function __construct(string $message = '自定义错误', int $statusCode = 400, int $code = 0)
 *     {
 *         parent::__construct($message, $statusCode, $code);
 *     }
 * }
 * ```
 */
abstract class BaseException extends \Exception
{
    /** @var int 业务状态码 */
    protected int $statusCode = 400;
    
    /**
     * 构造函数
     * 
     * @param string $message 错误消息
     * @param int $statusCode 业务状态码，默认 400
     * @param int $code 错误码，默认 0
     */
    public function __construct(string $message = '操作失败', int $statusCode = 400, int $code = 0)
    {
        parent::__construct($message, $code);
        $this->statusCode = $statusCode;
    }
    
    /**
     * 获取业务状态码
     * 
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
