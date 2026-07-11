<?php
declare(strict_types=1);

namespace app\service\client\points;

use app\common\service\DeadlockRetryTrait;
use app\common\enum\OperatorType;
use app\model\goods\Goods;
use app\model\goods\GoodsSku;
use app\model\marketing\PointsExchangeOrder;
use app\model\marketing\PointsExchangeOrderLog;
use app\model\marketing\PointsGoods;
use app\service\client\UserAddressService;
use app\service\marketing\PointsExchangeOrderLogService;
use app\service\marketing\PointsExchangeOrderLifecycleService;
use app\service\marketing\PointsFeatureService;
use app\service\order\OrderSnGenerator;
use app\service\order\StockService;
use app\service\upload\AssetHydrator;
use app\service\user\UserPointsAccountService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 前台积分商城服务
 *
 * @extends BaseService<PointsGoods>
 */
class PointsMallService extends BaseService
{
    use DeadlockRetryTrait;

    protected string $modelClass = PointsGoods::class;

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function goodsList(array $where, int $page, int $limit): array
    {
        $this->assertPointsEnabled();
        $query = $this->buildGoodsListQuery($where);

        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('pg.sort', 'asc')
            ->order('pg.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $list = $this->formatGoodsRows($rows);

        return compact('total', 'list');
    }

    /**
     * @return array<string,mixed>
     */
    public function goodsDetail(int $id): array
    {
        $this->assertPointsEnabled();
        $row = $this->activeGoodsQuery()->where('pg.id', $id)->find();
        if (!$row) {
            throw new BusinessException('积分商品不存在或已下架');
        }

        return $this->formatGoodsRows([$row->toArray()])[0] ?? [];
    }

    /**
     * @return array{id:int,sn:string}
     */
    public function exchange(int $userId, int $pointsGoodsId, int $addressId, int $quantity, string $buyerRemark = '', string $idempotencyKey = ''): array
    {
        $this->assertPointsEnabled();
        if ($userId <= 0) {
            throw new BusinessException('请先登录');
        }
        if ($pointsGoodsId <= 0) {
            throw new BusinessException('请选择积分商品');
        }
        if ($addressId <= 0) {
            throw new BusinessException('请选择收货地址');
        }
        $quantity = max(1, min(99, $quantity));
        $idempotencyKey = mb_substr(trim($idempotencyKey), 0, 64);
        if ($idempotencyKey !== '') {
            /** @var PointsExchangeOrder|null $existing */
            $existing = $this->model(PointsExchangeOrder::class)
                ->where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->find();
            if ($existing !== null) {
                return ['id' => (int) $existing->id, 'sn' => (string) $existing->sn];
            }
        }

        $address = app()->make(UserAddressService::class)->getMyInfo($userId, $addressId);
        if ((int) ($address['region_status'] ?? 0) !== 1) {
            throw new BusinessException('该地址所属区域已失效，请重新选择');
        }

        return $this->withDeadlockRetry(function () use ($userId, $pointsGoodsId, $addressId, $quantity, $buyerRemark, $idempotencyKey, $address): array {
            return $this->transaction(function () use ($userId, $pointsGoodsId, $addressId, $quantity, $buyerRemark, $idempotencyKey, $address): array {
                if ($idempotencyKey !== '') {
                    /** @var PointsExchangeOrder|null $existing */
                    $existing = $this->model(PointsExchangeOrder::class)
                        ->where('user_id', $userId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lock(true)
                        ->find();
                    if ($existing !== null) {
                        return ['id' => (int) $existing->id, 'sn' => (string) $existing->sn];
                    }
                }

                $snapshot = $this->lockedExchangeSnapshot($pointsGoodsId);
                $this->assertLimit($userId, $pointsGoodsId, $quantity, (int) $snapshot['limit_per_user']);
                $totalPoints = (int) $snapshot['points_price'] * $quantity;
                if ($totalPoints <= 0) {
                    throw new BusinessException('兑换积分配置不合法');
                }

                /** @var PointsGoods $pointsGoods */
                $pointsGoods = $snapshot['points_goods'];
                if ((int) $pointsGoods->exchange_stock < $quantity) {
                    throw new BusinessException('兑换库存不足');
                }
                $pointsGoods->exchange_stock = (int) $pointsGoods->exchange_stock - $quantity;
                $pointsGoods->exchanged_count = (int) $pointsGoods->exchanged_count + $quantity;
                $pointsGoods->save();

                app()->make(StockService::class)->decrease((int) $snapshot['sku_id'], $quantity);
                $sn = 'PX' . app()->make(OrderSnGenerator::class)->next();
                app()->make(UserPointsAccountService::class)->deductForExchange($userId, $sn, $totalPoints);

                /** @var PointsExchangeOrder $order */
                $order = $this->model(PointsExchangeOrder::class);
                $order->save([
                    'sn' => $sn,
                    'user_id' => $userId,
                    'points_goods_id' => $pointsGoodsId,
                    'goods_id' => (int) $snapshot['goods_id'],
                    'sku_id' => (int) $snapshot['sku_id'],
                    'goods_name' => mb_substr((string) $snapshot['goods_name'], 0, 200),
                    'goods_image' => (string) $snapshot['goods_image'],
                    'sku_spec' => mb_substr((string) $snapshot['sku_spec'], 0, 500),
                    'points_price' => (int) $snapshot['points_price'],
                    'quantity' => $quantity,
                    'total_points' => $totalPoints,
                    'address_id' => $addressId,
                    'receiver_name' => mb_substr((string) ($address['receiver_name'] ?? ''), 0, 50),
                    'receiver_phone' => mb_substr((string) ($address['receiver_mobile'] ?? ''), 0, 30),
                    'receiver_province' => mb_substr((string) ($address['province_name'] ?? ''), 0, 50),
                    'receiver_city' => mb_substr((string) ($address['city_name'] ?? ''), 0, 50),
                    'receiver_district' => mb_substr((string) ($address['district_name'] ?? ''), 0, 50),
                    'receiver_address' => mb_substr((string) ($address['address_detail'] ?? ''), 0, 255),
                    'status' => PointsExchangeOrder::STATUS_PENDING_SHIP,
                    'buyer_remark' => mb_substr(trim($buyerRemark), 0, 255),
                    'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                ]);
                app()->make(PointsExchangeOrderLogService::class)->record(
                    order: $order,
                    action: PointsExchangeOrderLog::ACTION_CREATE,
                    fromStatus: null,
                    toStatus: PointsExchangeOrder::STATUS_PENDING_SHIP,
                    operatorType: OperatorType::BUYER,
                    operatorId: $userId,
                    remark: '用户提交积分兑换'
                );

                return ['id' => (int) $order->id, 'sn' => $sn];
            });
        });
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function myOrders(int $userId, array $where, int $page, int $limit): array
    {
        $this->assertPointsEnabled();
        $query = $this->model(PointsExchangeOrder::class)
            ->where('user_id', $userId)
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where): void {
                $q->where('status', (int) $where['status']);
            });

        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $list = $this->formatOrderRows($rows);

        return compact('total', 'list');
    }

    /**
     * @return array<string,mixed>
     */
    public function myOrderDetail(int $userId, int $id): array
    {
        $this->assertPointsEnabled();
        $order = $this->model(PointsExchangeOrder::class)
            ->where('id', $id)
            ->where('user_id', $userId)
            ->find();
        if (!$order) {
            throw new BusinessException('兑换单不存在');
        }

        return $this->formatOrderRows([$order->toArray()])[0] ?? [];
    }

    public function cancelOrder(int $userId, int $id): bool
    {
        $this->assertPointsEnabled();
        if ($userId <= 0) {
            throw new BusinessException('请先登录');
        }
        if ($id <= 0) {
            throw new BusinessException('兑换单不存在');
        }

        return app()->make(PointsExchangeOrderLifecycleService::class)->closePending(
            id: $id,
            remark: '用户取消兑换',
            operatorType: OperatorType::BUYER,
            operatorId: $userId,
            expectedUserId: $userId
        );
    }

    private function buildGoodsListQuery(array $where)
    {
        $query = $this->activeGoodsQuery();
        $keyword = trim((string) ($where['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('g.name', '%' . $keyword . '%');
        }

        return $query;
    }

    private function activeGoodsQuery()
    {
        return $this->model()
            ->alias('pg')
            ->join('mb_goods g', 'g.id = pg.goods_id')
            ->join('mb_goods_sku sku', 'sku.id = pg.sku_id AND sku.goods_id = pg.goods_id')
            ->where('pg.status', 1)
            ->where('pg.exchange_stock', '>', 0)
            ->where('g.status', 1)
            ->where('g.is_on_sale', 1)
            ->whereNull('g.delete_time')
            ->where('sku.status', 1)
            ->field('pg.*');
    }

    /**
     * @return array<string,mixed>
     */
    private function lockedExchangeSnapshot(int $pointsGoodsId): array
    {
        /** @var PointsGoods|null $pointsGoods */
        $pointsGoods = $this->model()->where('id', $pointsGoodsId)->lock(true)->find();
        if (!$pointsGoods || (int) $pointsGoods->status !== 1) {
            throw new BusinessException('积分商品不存在或已下架');
        }

        /** @var Goods|null $goods */
        $goods = $this->model(Goods::class)
            ->where('id', (int) $pointsGoods->goods_id)
            ->where('status', 1)
            ->where('is_on_sale', 1)
            ->whereNull('delete_time')
            ->find();
        if (!$goods) {
            throw new BusinessException('兑换商品已下架');
        }

        /** @var GoodsSku|null $sku */
        $sku = $this->model(GoodsSku::class)
            ->where('id', (int) $pointsGoods->sku_id)
            ->where('goods_id', (int) $pointsGoods->goods_id)
            ->where('status', 1)
            ->find();
        if (!$sku) {
            throw new BusinessException('兑换商品规格已失效');
        }

        return [
            'points_goods' => $pointsGoods,
            'goods_id' => (int) $pointsGoods->goods_id,
            'sku_id' => (int) $pointsGoods->sku_id,
            'goods_name' => (string) $goods->name,
            'goods_image' => (string) ($sku->image ?: $goods->main_image ?: ''),
            'sku_spec' => (string) ($sku->spec_values ?? ''),
            'points_price' => (int) $pointsGoods->points_price,
            'limit_per_user' => (int) $pointsGoods->limit_per_user,
        ];
    }

    private function assertLimit(int $userId, int $pointsGoodsId, int $quantity, int $limitPerUser): void
    {
        if ($limitPerUser <= 0) {
            return;
        }

        $exchanged = (int) $this->model(PointsExchangeOrder::class)
            ->where('user_id', $userId)
            ->where('points_goods_id', $pointsGoodsId)
            ->where('status', '<>', PointsExchangeOrder::STATUS_CLOSED)
            ->sum('quantity');
        if ($exchanged + $quantity > $limitPerUser) {
            throw new BusinessException('超过该积分商品的限兑数量');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function formatGoodsRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $goodsIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['goods_id'], $rows)));
        $skuIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['sku_id'], $rows)));
        $goodsRows = $this->model(Goods::class)
            ->whereIn('id', $goodsIds)
            ->field('id,name,subtitle,main_image,price,market_price,stock,status,is_on_sale')
            ->select()
            ->toArray();
        $skuMap = $this->model(GoodsSku::class)
            ->whereIn('id', $skuIds)
            ->column('id,spec_values,price,stock,status,image', 'id');
        $goodsMap = [];
        foreach ($goodsRows as $goods) {
            $goodsMap[(int) $goods['id']] = $goods;
        }

        foreach ($rows as &$row) {
            $goods = $goodsMap[(int) ($row['goods_id'] ?? 0)] ?? [];
            $sku = $skuMap[(int) ($row['sku_id'] ?? 0)] ?? [];
            $row['goods_name'] = (string) ($goods['name'] ?? '');
            $row['goods_subtitle'] = (string) ($goods['subtitle'] ?? '');
            $row['goods_image'] = (string) (($sku['image'] ?? '') ?: ($goods['main_image'] ?? ''));
            $row['goods_price'] = (string) ($goods['price'] ?? '0.00');
            $row['market_price'] = (string) ($goods['market_price'] ?? '0.00');
            $row['sku_spec'] = (string) ($sku['spec_values'] ?? '');
            $row['sku_stock'] = (int) ($sku['stock'] ?? 0);
            $row['available_stock'] = min((int) ($row['exchange_stock'] ?? 0), (int) ($sku['stock'] ?? 0));
        }
        unset($row);

        return app()->make(AssetHydrator::class)->hydrateFields($rows, [
            'goods_image' => 'goods_image_full_url',
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function formatOrderRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $status = (int) ($row['status'] ?? 0);
            $row['status_text'] = PointsExchangeOrder::statusText($status);
            $row['delivery_type'] = (string) (($row['delivery_type'] ?? '') === PointsExchangeOrder::DELIVERY_TYPE_VIRTUAL
                ? PointsExchangeOrder::DELIVERY_TYPE_VIRTUAL
                : PointsExchangeOrder::DELIVERY_TYPE_PHYSICAL);
            $row['delivery_type_text'] = PointsExchangeOrder::deliveryTypeLabel((string) $row['delivery_type']);
            $row['receiver_full_address'] = implode(' ', array_filter([
                (string) ($row['receiver_province'] ?? ''),
                (string) ($row['receiver_city'] ?? ''),
                (string) ($row['receiver_district'] ?? ''),
                (string) ($row['receiver_address'] ?? ''),
            ]));
        }
        unset($row);

        return app()->make(AssetHydrator::class)->hydrateFields($rows, [
            'goods_image' => 'goods_image_full_url',
        ]);
    }

    private function assertPointsEnabled(): void
    {
        app()->make(PointsFeatureService::class)->assertEnabled();
    }
}
