<?php

declare(strict_types=1);

namespace app\client\service\order;

use app\admin\model\goods\Goods;
use app\admin\model\goods\GoodsSku;
use app\admin\model\order\Cart;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use think\facade\Db;

/**
 * 买家购物车服务
 *
 * 行级唯一性由表 UNIQUE(user_id, sku_id, delete_time) 保证：
 *  - add 走 UPSERT 语义，命中已有行则数量累加
 *  - 不进行“加入前校验库存”，允许超量放入购物车；库存在结算时由 OrderService 乐观锁兜底
 *  - 列表聚合补齐：商品名/主图/现价/库存状态，减少前端二次请求
 *
 * @extends BaseService<Cart>
 */
class CartService extends BaseService
{
    protected string $modelClass = Cart::class;

    /**
     * 单条购物车数量上限（单 SKU 一行）
     */
    private const MAX_QUANTITY_PER_SKU = 999;

    /**
     * 加入购物车（UPSERT 累加）
     *
     * @return int 购物车行 ID
     */
    public function add(int $userId, int $skuId, int $quantity): int
    {
        $this->assertPositiveQty($quantity);

        $sku = $this->ensureSaleableSku($skuId);
        $goodsId = (int) $sku['goods_id'];

        return $this->transaction(function () use ($userId, $goodsId, $skuId, $quantity) {
            /** @var Cart|null $existing */
            $existing = $this->model()
                ->where('user_id', $userId)
                ->where('sku_id', $skuId)
                ->whereNull('delete_time')
                ->find();

            if ($existing) {
                $newQuantity = min((int) $existing->quantity + $quantity, self::MAX_QUANTITY_PER_SKU);
                $existing->save([
                    'quantity' => $newQuantity,
                    'selected' => 1,
                ]);
                return (int) $existing->id;
            }

            /** @var Cart $cart */
            $cart = $this->model()->create([
                'user_id'  => $userId,
                'goods_id' => $goodsId,
                'sku_id'   => $skuId,
                'quantity' => min($quantity, self::MAX_QUANTITY_PER_SKU),
                'selected' => 1,
            ]);
            return (int) $cart->id;
        });
    }

    /**
     * 修改购物车单行数量（绝对值，非累加）
     */
    public function updateQuantity(int $userId, int $cartId, int $quantity): void
    {
        $this->assertPositiveQty($quantity);
        if ($quantity > self::MAX_QUANTITY_PER_SKU) {
            throw new BusinessException('单商品购买上限为 ' . self::MAX_QUANTITY_PER_SKU . ' 件');
        }

        $cart = $this->findOwnedCart($userId, $cartId);
        $cart->save(['quantity' => $quantity]);
    }

    /**
     * 切换勾选状态
     *
     * @param array<int, int> $cartIds
     */
    public function toggleSelected(int $userId, array $cartIds, int $selected): void
    {
        $ids = $this->normalizeCartIds($cartIds);
        if ($ids === []) {
            return;
        }

        $this->model()
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->whereNull('delete_time')
            ->update(['selected' => $selected === 1 ? 1 : 0]);
    }

    /**
     * 批量删除购物车
     *
     * @param array<int, int> $cartIds
     */
    public function remove(int $userId, array $cartIds): void
    {
        $ids = $this->normalizeCartIds($cartIds);
        if ($ids === []) {
            return;
        }

        $this->model()
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->whereNull('delete_time')
            ->select()
            ->each(function (Cart $cart) {
                $cart->delete();
            });
    }

    /**
     * 查询用户购物车列表，聚合商品/SKU 信息
     *
     * @return array{list: array<int, array<string, mixed>>, total_quantity: int, selected_quantity: int}
     */
    public function list(int $userId): array
    {
        $rows = $this->model()
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        if ($rows === []) {
            return ['list' => [], 'total_quantity' => 0, 'selected_quantity' => 0];
        }

        $skuIds = array_values(array_unique(array_map(static fn(array $r): int => (int) $r['sku_id'], $rows)));
        $goodsIds = array_values(array_unique(array_map(static fn(array $r): int => (int) $r['goods_id'], $rows)));

        $skuMap = $this->fetchSkuMap($skuIds);
        $goodsMap = $this->fetchGoodsMap($goodsIds);

        $totalQuantity = 0;
        $selectedQuantity = 0;
        $list = [];
        foreach ($rows as $row) {
            $sku   = $skuMap[(int) $row['sku_id']]   ?? null;
            $goods = $goodsMap[(int) $row['goods_id']] ?? null;

            $invalid = $sku === null
                || $goods === null
                || (int) $sku['status'] !== 1
                || (int) $goods['status'] !== 1
                || (int) $goods['is_on_sale'] !== 1;

            $stockShortage = $sku !== null && (int) $sku['stock'] < (int) $row['quantity'];

            $list[] = [
                'id'          => (int) $row['id'],
                'goods_id'    => (int) $row['goods_id'],
                'sku_id'      => (int) $row['sku_id'],
                'quantity'    => (int) $row['quantity'],
                'selected'    => (int) $row['selected'],
                'goods_name'  => $goods['name'] ?? '',
                'goods_image' => buildUploadUrl($goods['main_image'] ?? ''),
                'sku_image'   => buildUploadUrl($sku['image'] ?? ''),
                'sku_spec'    => $sku['spec_values'] ?? '',
                'unit_price'  => $sku !== null ? (float) $sku['price'] : 0.0,
                'stock'       => $sku !== null ? (int) $sku['stock'] : 0,
                'is_invalid'  => $invalid,
                'is_short_of_stock' => !$invalid && $stockShortage,
            ];

            $totalQuantity += (int) $row['quantity'];
            if ((int) $row['selected'] === 1 && !$invalid && !$stockShortage) {
                $selectedQuantity += (int) $row['quantity'];
            }
        }

        return [
            'list' => $list,
            'total_quantity' => $totalQuantity,
            'selected_quantity' => $selectedQuantity,
        ];
    }

    /**
     * 清空购物车中已失效 / 用户主动移除的行
     * OrderService 下单成功后会调用，这里暴露公共方法便于复用
     *
     * @param array<int, int> $cartIds
     */
    public function removeSelected(int $userId, array $cartIds): void
    {
        $this->remove($userId, $cartIds);
    }

    /**
     * 规整前端传入的 cart_ids：去重、过滤非正整数
     *
     * @param array<int, mixed> $cartIds
     * @return array<int, int>
     */
    public function normalizeCartIds(array $cartIds): array
    {
        $ids = [];
        foreach ($cartIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = $intId;
            }
        }
        return array_values($ids);
    }

    /**
     * 校验 SKU 可售，返回轻量数据
     *
     * @return array{id:int, goods_id:int, stock:int, status:int, price:string}
     */
    private function ensureSaleableSku(int $skuId): array
    {
        /** @var GoodsSku|null $sku */
        $sku = app()->make(GoodsSku::class)
            ->where('id', $skuId)
            ->where('status', 1)
            ->find();
        if ($sku === null) {
            throw new BusinessException('商品规格不存在或已下架');
        }

        $goods = app()->make(Goods::class)
            ->where('id', $sku->goods_id)
            ->whereNull('delete_time')
            ->find();
        if ($goods === null || (int) $goods->status !== 1 || (int) $goods->is_on_sale !== 1) {
            throw new BusinessException('商品已下架');
        }

        return [
            'id'       => (int) $sku->id,
            'goods_id' => (int) $sku->goods_id,
            'stock'    => (int) $sku->stock,
            'status'   => (int) $sku->status,
            'price'    => (string) $sku->price,
        ];
    }

    /**
     * 校验购物车行归属
     */
    private function findOwnedCart(int $userId, int $cartId): Cart
    {
        /** @var Cart|null $cart */
        $cart = $this->model()
            ->where('id', $cartId)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($cart === null) {
            throw new BusinessException('购物车记录不存在');
        }
        return $cart;
    }

    /**
     * 正数校验
     */
    private function assertPositiveQty(int $qty): void
    {
        if ($qty <= 0) {
            throw new BusinessException('数量必须大于 0');
        }
    }

    /**
     * @param array<int, int> $skuIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchSkuMap(array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }
        $rows = Db::name('goods_sku')
            ->whereIn('id', $skuIds)
            ->column('id,goods_id,spec_values,price,stock,image,status', 'id');
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, int> $goodsIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchGoodsMap(array $goodsIds): array
    {
        if ($goodsIds === []) {
            return [];
        }
        $rows = Db::name('goods')
            ->whereIn('id', $goodsIds)
            ->whereNull('delete_time')
            ->column('id,name,main_image,status,is_on_sale', 'id');
        return is_array($rows) ? $rows : [];
    }
}
