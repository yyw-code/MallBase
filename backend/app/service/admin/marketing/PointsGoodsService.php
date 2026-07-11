<?php
declare(strict_types=1);

namespace app\service\admin\marketing;

use app\model\goods\Goods;
use app\model\goods\GoodsSku;
use app\model\marketing\PointsExchangeOrder;
use app\model\marketing\PointsGoods;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台积分商品服务
 *
 * @extends BaseService<PointsGoods>
 */
class PointsGoodsService extends BaseService
{
    protected string $modelClass = PointsGoods::class;

    protected function buildListQuery(array $where)
    {
        $query = $this->model();
        $keyword = trim((string) ($where['keyword'] ?? ''));
        if ($keyword !== '') {
            $goodsIds = $this->model(Goods::class)
                ->whereLike('name', '%' . $keyword . '%')
                ->whereNull('delete_time')
                ->column('id');
            $query->where(function ($q) use ($keyword, $goodsIds): void {
                $q->where('id', (int) $keyword);
                if ($goodsIds !== []) {
                    $q->whereOr('goods_id', 'in', $goodsIds);
                }
            });
        }

        return $query
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where): void {
                $q->where('status', (int) $where['status']);
            });
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $query = $this->buildListQuery($where);

        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $list = $this->formatRows($rows);

        return compact('total', 'list');
    }

    /**
     * @return array<string,mixed>
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);
        if (!$info) {
            throw new BusinessException('积分商品不存在');
        }

        return $this->formatRows([$info->toArray()])[0] ?? [];
    }

    public function create(array $data): int
    {
        $payload = $this->normalizePayload($data);
        $this->validatePayload($payload);
        $this->assertSkuUnique($payload['sku_id']);

        /** @var PointsGoods $model */
        $model = $this->model();
        $model->save($payload);

        return (int) $model->id;
    }

    public function update(int $id, array $data): bool
    {
        /** @var PointsGoods|null $model */
        $model = $this->model()->find($id);
        if (!$model) {
            throw new BusinessException('积分商品不存在');
        }

        $payload = $this->normalizePayload($data);
        $this->validatePayload($payload);
        $this->assertSkuUnique($payload['sku_id'], $id);

        $model->save($payload);

        return true;
    }

    public function delete(int $id): bool
    {
        /** @var PointsGoods|null $model */
        $model = $this->model()->find($id);
        if (!$model) {
            throw new BusinessException('积分商品不存在');
        }
        if ($this->model(PointsExchangeOrder::class)->where('points_goods_id', $id)->count() > 0) {
            throw new BusinessException('已有兑换记录的积分商品不能删除，可停用');
        }

        $model->delete();

        return true;
    }

    public function updateStatus(int $id, int $status): bool
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态不合法');
        }

        /** @var PointsGoods|null $model */
        $model = $this->model()->find($id);
        if (!$model) {
            throw new BusinessException('积分商品不存在');
        }
        $model->save(['status' => $status]);

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizePayload(array $data): array
    {
        return [
            'goods_id' => (int) ($data['goods_id'] ?? 0),
            'sku_id' => (int) ($data['sku_id'] ?? 0),
            'points_price' => max(0, (int) ($data['points_price'] ?? 0)),
            'exchange_stock' => max(0, (int) ($data['exchange_stock'] ?? 0)),
            'limit_per_user' => max(0, (int) ($data['limit_per_user'] ?? 0)),
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? 1),
            'remark' => mb_substr(trim((string) ($data['remark'] ?? '')), 0, 255),
        ];
    }

    private function validatePayload(array $payload): void
    {
        if ((int) $payload['goods_id'] <= 0 || (int) $payload['sku_id'] <= 0) {
            throw new BusinessException('请选择兑换商品和规格');
        }
        if ((int) $payload['points_price'] <= 0) {
            throw new BusinessException('兑换积分必须大于 0');
        }
        if (!in_array((int) $payload['status'], [0, 1], true)) {
            throw new BusinessException('状态不合法');
        }

        /** @var Goods|null $goods */
        $goods = $this->model(Goods::class)
            ->where('id', (int) $payload['goods_id'])
            ->whereNull('delete_time')
            ->find();
        if (!$goods) {
            throw new BusinessException('商品不存在');
        }

        /** @var GoodsSku|null $sku */
        $sku = $this->model(GoodsSku::class)
            ->where('id', (int) $payload['sku_id'])
            ->where('goods_id', (int) $payload['goods_id'])
            ->find();
        if (!$sku) {
            throw new BusinessException('商品规格不存在');
        }
    }

    private function assertSkuUnique(int $skuId, int $excludeId = 0): void
    {
        $exists = $this->model()
            ->where('sku_id', $skuId)
            ->when($excludeId > 0, function ($q) use ($excludeId): void {
                $q->where('id', '<>', $excludeId);
            })
            ->count() > 0;
        if ($exists) {
            throw new BusinessException('该商品规格已配置为积分商品');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function formatRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $goodsIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['goods_id'], $rows)));
        $skuIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['sku_id'], $rows)));
        $goodsRows = $this->model(Goods::class)
            ->whereIn('id', $goodsIds)
            ->field('id,name,main_image,images,price,stock,status,is_on_sale')
            ->select()
            ->toArray();
        $skuMap = $this->model(GoodsSku::class)
            ->whereIn('id', $skuIds)
            ->column('id,goods_id,spec_values,price,stock,status,image', 'id');
        $goodsMap = [];
        foreach ($goodsRows as $goods) {
            $goodsMap[(int) $goods['id']] = $goods;
        }

        $hydrator = app()->make(AssetHydrator::class);
        foreach ($rows as &$row) {
            $goods = $goodsMap[(int) ($row['goods_id'] ?? 0)] ?? [];
            $sku = $skuMap[(int) ($row['sku_id'] ?? 0)] ?? [];
            $fallbackImage = $hydrator->firstImageValue($goods['images'] ?? []);
            $row['goods_name'] = (string) ($goods['name'] ?? '');
            $row['goods_image'] = (string) (($sku['image'] ?? '') ?: ($goods['main_image'] ?? '') ?: $fallbackImage);
            $row['goods_price'] = (string) ($goods['price'] ?? '0.00');
            $row['goods_stock'] = (int) ($goods['stock'] ?? 0);
            $row['goods_status'] = (int) ($goods['status'] ?? 0);
            $row['goods_is_on_sale'] = (int) ($goods['is_on_sale'] ?? 0);
            $row['sku_spec'] = (string) ($sku['spec_values'] ?? '');
            $row['sku_price'] = (string) ($sku['price'] ?? '0.00');
            $row['sku_stock'] = (int) ($sku['stock'] ?? 0);
            $row['sku_status'] = (int) ($sku['status'] ?? 0);
            $row['available_stock'] = min((int) ($row['exchange_stock'] ?? 0), (int) ($sku['stock'] ?? 0));
        }
        unset($row);

        return $hydrator->hydrateFields($rows, [
            'goods_image' => 'goods_image_full_url',
        ]);
    }
}
