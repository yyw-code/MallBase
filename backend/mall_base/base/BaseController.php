<?php

namespace mall_base\base;

use mall_base\exception\BusinessException;
use think\App;
use think\exception\ValidateException;
use think\facade\Request;
use think\Response;
use think\Validate;

/**
 * @template TService of BaseService
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     * @access public
     * @param App $app 应用对象
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {
    }


    /**
     * 获取 Service 实例（每次返回缓存的实例）
     *
     * - 不传参数时，返回当前控制器默认 Service（TService）
     * - 传入具体 Service::class 时，返回对应 Service 类型（提升 IDE 跳转准确度）
     *
     * @template TRequestedService of BaseService
     * @param class-string<TRequestedService>|null $serviceClass
     * @return TService|TRequestedService
     * @throws BusinessException
     */
    protected function service(?string $serviceClass = null)
    {
        $className = $serviceClass ?? ($this->serviceClass ?? '');

        if (empty($className)) {
            throw new BusinessException('请传入 serviceClass 或定义 $serviceClass 属性');
        }

        return app()->make($className);
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
    protected function error(string $message = '操作失败', int $code = 400, $data = null): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ]);
    }

    protected function getPagination(int $defaultPage = 1, int $defaultLimit = 10): array
    {
        $page = (int)$this->request->param('page', $defaultPage);
        $pageSize = (int)$this->request->param('limit', $defaultLimit);

        return [max(1, $page), max(1, min(100, $pageSize))];
    }

    /**
     * 验证数据
     * @access protected
     * @param array $data 数据
     * @param string|array $validate 验证器名或者验证规则数组
     * @param array $message 提示信息
     * @param bool $batch 是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, string|array $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }
}
