<?php

namespace mall_base\base;

use think\facade\Db;
use think\Container;

/**
 * 服务基类
 *
 * @template TModel of BaseModel
 *
 * 功能说明：
 * - 提供服务层通用功能
 * - Service 完全无状态，不持有 Model 实例
 * - Model 通过方法调用时动态获取
 * - 提供事务支持
 * - 提供分页查询支持
 *
 * 设计理念：
 * - Service 负责业务逻辑处理
 * - Service 完全无状态，适合 Swoole 常驻进程
 * - Model 实例在需要时动态创建，用完即弃
 * - 避免状态污染和内存泄漏
 */
abstract class BaseService
{
    /**
     * Model 类名（子类必须定义）
     *
     * @var class-string<TModel>
     */
    protected string $modelClass = '';

    /**
     * 获取 Model 实例（每次调用返回新实例）
     *
     * 为什么每次返回新实例：
     * 1. Model 可能有状态（如查询构造器）
     * 2. 避免跨请求状态污染
     * 3. Swoole 常驻进程下最安全
     * 4. 实例化开销极小（ThinkPHP Model 很轻量）
     *
     * - 不传参数时，返回当前 Service 默认 Model（TModel）
     * - 传入具体 Model::class 时，返回对应 Model 类型（提升 IDE 跳转准确度）
     *
     * @template TRequestedModel of BaseModel
     * @param class-string<TRequestedModel>|null $modelClass
     * @return TModel|TRequestedModel
     */
    protected function model(?string $modelClass = null)
    {
        $className = $modelClass ?? ($this->modelClass ?? '');
        if (empty($className)) {
            throw new \RuntimeException('Service 必须定义 $modelClass 属性');
        }

        return Container::getInstance()->make($className, [], true);
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
