<?php

namespace mall_base\base;

use think\facade\Db;

/**
 * 服务基类
 *
 * 功能说明：
 * - 提供服务层通用功能
 * - 支持模型注入
 * - 提供事务支持
 * - 提供分页查询支持
 *
 * 设计理念：
 * - Service 负责业务逻辑处理
 * - 通过构造函数注入 Model 实例
 * - 可以调用其他 Service 协同处理业务
 * - 事务控制在 Service 层处理
 *
 * 使用示例：
 * ```php
 * class UserService extends BaseService
 * {
 *     public function __construct()
 *     {
 *         parent::__construct(new UserModel());
 *     }
 *
 *     public function getUserById(int $id)
 *     {
 *         return $this->model->findById($id);
 *     }
 * }
 * ```
 */
abstract class BaseService
{
    /** @var mixed|null Model 实例 */
    protected $model = null;

    /**
     * 构造函数
     *
     * @param BaseModel|null $model Model 实例
     */
    public function __construct(BaseModel $model = null)
    {
        $this->model = $model;
    }


    /**
     * 执行事务
     *
     * @param callable $callback 事务回调
     * @return mixed 回调返回值
     * @throws \Throwable
     */
    protected function transaction(callable $callback)
    {
        Db::startTrans();
        try {
            $result = $callback();
            Db::commit();
            return $result;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 格式化列表响应
     *
     * @param array $list 列表数据
     * @param int $total 总数
     * @param int $page 当前页
     * @param int $pageSize 每页数量
     * @return array
     */
    protected function formatListResult(array $list, int $total, int $page, int $pageSize): array
    {
        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => ceil($total / $pageSize),
            'list' => $list,
        ];
    }
}
