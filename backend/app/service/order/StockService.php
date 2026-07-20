<?php

declare(strict_types=1);

namespace app\service\order;

use app\model\goods\GoodsSku;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 库存扣减/回滚服务（并发核心）
 *
 * 设计要点：
 *  1. 使用乐观锁：UPDATE mb_goods_sku SET stock = stock - :qty WHERE id = :id AND stock >= :qty
 *  2. affected_rows = 0 代表库存不足（或 SKU 不存在），统一抛 BusinessException，禁止返回 false
 *  3. 不在方法内部开启事务，交由调用方（OrderService）串联事务
 *     —— 符合 thinkPHP/architecture-layering 与 validate-then-transact 规范
 *  4. 为秒杀预留接口：本 Service 不引入 Redis 预扣，MVP 以数据库乐观锁即可保证不超卖
 *
 * @extends BaseService<GoodsSku>
 */
class StockService extends BaseService
{
    protected string $modelClass = GoodsSku::class;

    /**
     * 乐观锁扣减库存
     *
     * @param int $skuId SKU 主键
     * @param int $qty   扣减数量，必须为正整数
     *
     * @throws BusinessException 库存不足或 SKU 不存在
     */
    public function decrease(int $skuId, int $qty): void
    {
        $this->assertPositiveQty($qty);

        $affected = $this->model()
            ->where('id', $skuId)
            ->where('stock', '>=', $qty)
            ->dec('stock', $qty)
            ->update();

        if ($affected === 0) {
            throw new BusinessException('库存不足');
        }
    }

    /**
     * 回滚库存（取消订单 / 超时关闭 / 退货入库）
     *
     * @param int $skuId SKU 主键
     * @param int $qty   回滚数量，必须为正整数
     *
     * @throws BusinessException SKU 不存在
     */
    public function restore(int $skuId, int $qty): void
    {
        $this->assertPositiveQty($qty);

        $affected = $this->model()
            ->where('id', $skuId)
            ->inc('stock', $qty)
            ->update();

        if ($affected === 0) {
            throw new BusinessException('SKU不存在，无法回滚库存');
        }
    }

    /**
     * 批量扣减库存（按传入顺序，任一失败抛异常交由上层事务回滚）
     *
     * @param array<int, array{sku_id:int, quantity:int}> $items
     *
     * @throws BusinessException
     */
    public function decreaseBatch(array $items): void
    {
        foreach ($items as $item) {
            $this->decrease((int) $item['sku_id'], (int) $item['quantity']);
        }
    }

    /**
     * 批量回滚库存
     *
     * @param array<int, array{sku_id:int, quantity:int}> $items
     */
    public function restoreBatch(array $items): void
    {
        foreach ($items as $item) {
            $this->restore((int) $item['sku_id'], (int) $item['quantity']);
        }
    }

    /**
     * 统一的正数校验，避免业务代码传入负数/0 触发反向扣减
     */
    private function assertPositiveQty(int $qty): void
    {
        if ($qty <= 0) {
            throw new BusinessException('库存变更数量必须大于 0');
        }
    }
}
