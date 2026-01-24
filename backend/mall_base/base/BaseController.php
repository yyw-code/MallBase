<?php

namespace mall_base\base;

use mall_base\ServiceFactory;
use think\facade\Request;
use think\Response;

/**
 * 控制器基类
 *
 * 功能说明：
 * - 提供控制器通用功能
 * - 提供响应方法封装
 * - 支持魔术方法自动获取 Service 实例
 *
 * 设计理念：
 * - Controller 负责接收请求、参数验证、调用 Service、返回响应
 * - 通过魔术方法自动创建 Service 实例
 * - 不包含业务逻辑，业务逻辑由 Service 处理
 *
 * 使用示例：
 * ```php
 * class UserController extends BaseController
 * {
 *     public function list(): Response
 *     {
 *         return $this->success($this->userService->getUserList());
 *     }
 * }
 * ```
 *
 * 注意：使用魔术方法时，需要在子类中添加 @property 注释以支持 PhpStorm 代码提示
 * ```php
 * /**
 *  * User 控制器
 *  * 
 *  * @property \app\service\UserService $userService UserService 实例
 *  *\/
 * class UserController extends BaseController
 * {
 * }
 * ```
 *
 * 详细说明请参考：./SERVICE_FACTORY.md
 **/
abstract class BaseController
{
    protected array $services = [];

    /**
     * 构造函数
     */
    public function __construct()
    {
    }

    /**
     * 获取服务实例（延迟加载）
     *
     * 使用 ServiceFactory 创建服务实例，避免直接 new
     *
     * @param string $serviceName 服务类名
     * @return BaseService 服务实例
     */
    protected function getService(string $serviceName): BaseService
    {
        if (!isset($this->services[$serviceName])) {
            $this->services[$serviceName] = ServiceFactory::create($serviceName);
        }

        return $this->services[$serviceName];
    }

    /**
     * 魔术方法：自动获取 Service 实例
     *
     * 当访问不存在的属性时，自动尝试创建对应的 Service 实例
     *
     * 属性命名规则：service 类名转驼峰
     * - UserService -> $this->userService
     * - OrderService -> $this->orderService
     *
     * @param string $name 属性名
     * @return mixed
     * @throws \Exception
     */
    public function __get(string $name)
    {
        if (str_ends_with($name, 'Service')) {
            $className = str_replace('_', '', ucwords($name, '_'));
            $serviceName = '\\app\\service\\' . $className;

            if (!class_exists($serviceName)) {
                throw new \Exception("Service 类不存在: {$serviceName}");
            }

            if (!isset($this->services[$serviceName])) {
                $this->services[$serviceName] = ServiceFactory::create($serviceName);
            }

            return $this->services[$serviceName];
        }

        throw new \Exception("属性不存在: {$name}");
    }

    /**
     * 成功响应
     *
     * @param mixed $data 响应数据
     * @param string $message 响应消息
     * @param int $code 响应状态码
     * @return Response
     */
    protected function success($data = null, string $message = '操作成功', int $code = 200): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ]);
    }

    /**
     * 失败响应
     *
     * @param string $message 错误消息
     * @param int $code 错误状态码
     * @param mixed $data 响应数据
     * @return Response
     */
    protected function error(string $message = '操作失败', int $code = 500, $data = null): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ]);
    }

    protected function getParam(?string $key = null, $default = null)
    {
        if ($key === null) {
            return Request::param();
        }

        return Request::param($key, $default);
    }

    protected function getGet(?string $key = null, $default = null)
    {
        if ($key === null) {
            return Request::get();
        }

        return Request::get($key, $default);
    }

    protected function getPost(?string $key = null, $default = null)
    {
        if ($key === null) {
            return Request::post();
        }

        return Request::post($key, $default);
    }

    protected function getPagination(int $defaultPage = 1, int $defaultPageSize = 10): array
    {
        $page = (int)$this->getParam('page', $defaultPage);
        $pageSize = (int)$this->getParam('page_size', $defaultPageSize);

        return [
            'page' => max(1, $page),
            'page_size' => max(1, min(100, $pageSize)),
        ];
    }
}
