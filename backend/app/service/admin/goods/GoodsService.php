<?php
declare(strict_types=1);

namespace app\service\admin\goods;

use app\model\goods\Goods;
use app\model\goods\GoodsBrand;
use app\model\goods\GoodsCategory;
use app\model\goods\GoodsSku;
use app\model\goods\GoodsTag;
use app\model\goods\GoodsTagRelation;
use app\model\setting\FreightTemplate;
use app\service\admin\support\CsvExportService;
use app\service\upload\AssetHydrator;
use app\service\upload\AssetIdNormalizer;
use app\service\upload\AssetService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 商品服务
 * @extends BaseService<Goods>
 */
class GoodsService extends BaseService
{
    private const DEFAULT_SINGLE_SKU_SPEC_VALUES = '';
    private const EXPORT_LIMIT = 5000;
    private const STOCK_WARNING_THRESHOLD = 10;

    /**
     * 默认 Model 类名
     */
    protected string $modelClass = Goods::class;

    /**
     * 构建列表查询条件
     */
    protected function buildListQuery(array $where)
    {
        $query = $this->model();
        $view = (string) ($where['view'] ?? 'all');

        if ($view === 'recycle') {
            $query->whereNotNull('delete_time');
        } else {
            $query->whereNull('delete_time');
            if ($view === 'on_sale') {
                $query->where('status', 1)->where('is_on_sale', 1);
            } elseif ($view === 'off_sale') {
                $query->where('status', 1)->where('is_on_sale', 0);
            } elseif ($view === 'disabled') {
                $query->where('status', 0);
            }
        }

        return $query
            ->when(!empty($where['keyword']), function ($q) use ($where) {
                $q->whereLike('name|subtitle', "%{$where['keyword']}%");
            })
            ->when(!empty($where['category_id']), function ($q) use ($where) {
                $q->where('category_id', $where['category_id']);
            })
            ->when(!empty($where['brand_id']), function ($q) use ($where) {
                $q->where('brand_id', $where['brand_id']);
            })
            ->when(($where['is_on_sale'] ?? null) !== null && $where['is_on_sale'] !== '', function ($q) use ($where) {
                $q->where('is_on_sale', $where['is_on_sale']);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', $where['status']);
            })
            ->when(!empty($where['stock_warning']), function ($q) {
                $q->where('stock', '<=', self::STOCK_WARNING_THRESHOLD);
            });
    }

    /**
     * 获取商品列表
     *
     * @param array $where 搜索条件
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array{total: int, list: array}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $query = $this->buildListQuery($where);
        $list = $query->order('id', 'desc')->page($page, $limit)->select();
        $total = $this->buildListQuery($where)->count();

        $listArray = $list->toArray();
        $listArray = $this->appendListDerivedFields($listArray);

        $list = $listArray;
        return compact('total', 'list');
    }

    /**
     * @return array{total:int,tabs:array<int,array{key:string,label:string,count:int}>}
     */
    public function stats(array $where): array
    {
        $baseWhere = $where;
        unset($baseWhere['view'], $baseWhere['status'], $baseWhere['is_on_sale']);

        $tabs = [];
        $views = [
            'all' => '全部',
            'on_sale' => '出售中',
            'off_sale' => '已下架',
            'disabled' => '已禁用',
            'recycle' => '回收站',
        ];

        foreach ($views as $key => $label) {
            $viewWhere = $baseWhere;
            $viewWhere['view'] = $key;
            $tabs[] = [
                'key' => $key,
                'label' => $label,
                'count' => (int) $this->buildListQuery($viewWhere)->count(),
            ];
        }

        $total = (int) ($tabs[0]['count'] ?? 0);
        return compact('total', 'tabs');
    }

    public function exportCsv(array $where): string
    {
        $rows = $this->buildListQuery($where)
            ->order('id', 'desc')
            ->limit(self::EXPORT_LIMIT)
            ->select()
            ->toArray();
        $rows = $this->appendListDerivedFields($rows);

        foreach ($rows as &$row) {
            $row['is_on_sale_text'] = (int) ($row['is_on_sale'] ?? 0) === 1 ? '上架' : '下架';
            $row['status_text'] = (int) ($row['status'] ?? 0) === 1 ? '启用' : '禁用';
        }
        unset($row);

        return app()->make(CsvExportService::class)->make([
            'id' => 'ID',
            'name' => '商品名称',
            'category_name' => '分类',
            'brand_name' => '品牌',
            'price' => '价格',
            'stock' => '库存',
            'sales' => '销量',
            'is_on_sale_text' => '上架状态',
            'status_text' => '状态',
            'create_time' => '创建时间',
        ], $rows);
    }

    /**
     * @param array<int, array<string, mixed>> $listArray
     * @return array<int, array<string, mixed>>
     */
    private function appendListDerivedFields(array $listArray): array
    {
        if (empty($listArray)) {
            return [];
        }

        $categoryIds = array_unique(array_column($listArray, 'category_id'));
        $categories = $this->model(GoodsCategory::class)
            ->whereIn('id', $categoryIds)
            ->column('name', 'id');

        $brandIds = array_filter(array_unique(array_column($listArray, 'brand_id')));
        $brands = !empty($brandIds)
            ? $this->model(GoodsBrand::class)->whereIn('id', $brandIds)->column('name', 'id')
            : [];

        $goodsIds = array_column($listArray, 'id');
        $tagMap = $this->batchGetGoodsTags($goodsIds);

        foreach ($listArray as &$item) {
            $firstImageValue = app()->make(AssetHydrator::class)->firstImageValue($item['images'] ?? []);
            if (empty($item['main_image']) && $firstImageValue !== '') {
                $item['main_image'] = $firstImageValue;
            }
            $item['category_name'] = $categories[$item['category_id']] ?? '';
            $item['brand_name'] = $brands[$item['brand_id']] ?? '';
            $item['tags'] = $tagMap[$item['id']] ?? [];
        }
        unset($item);

        return app()->make(AssetHydrator::class)->hydrateGoodsList($listArray);
    }

    /**
     * 获取商品详情
     *
     * @param int $id 商品 ID
     * @return array 商品详情（含图片、SKU、标签）
     * @throws BusinessException 商品不存在时抛出
     */
    public function getInfo(int $id): array
    {
        $goods = $this->model()->where('id', $id)->whereNull('delete_time')->find();

        if (!$goods) {
            throw new BusinessException('商品不存在');
        }

        $result = $goods->toArray();
        $firstImageValue = app()->make(AssetHydrator::class)->firstImageValue($result['images'] ?? []);
        if (empty($result['main_image']) && $firstImageValue !== '') {
            $result['main_image'] = $firstImageValue;
        }

        // 获取商品SKU
        $skus = $this->model(GoodsSku::class)
            ->where('goods_id', $id)
            ->select();
        $result['skus'] = $skus->toArray();

        // 获取商品标签
        $tagIds = $this->model(GoodsTagRelation::class)
            ->where('goods_id', $id)
            ->column('tag_id');

        if (!empty($tagIds)) {
            $tags = $this->model(GoodsTag::class)
                ->whereIn('id', $tagIds)
                ->select();
            $result['tags'] = $tags->toArray();
        } else {
            $result['tags'] = [];
        }

        return app()->make(AssetHydrator::class)->hydrateGoodsDetail($result);
    }

    /**
     * 创建商品
     *
     * @param array $data 商品数据
     * @return int 新创建的商品 ID
     * @throws BusinessException 分类或品牌不存在时抛出
     */
    public function create(array $data): int
    {
        $data = $this->normalizeImages($data);
        $data = $this->normalizeMainImage($data);
        $data = $this->normalizeSpecType($data);
        $data = $this->normalizeSpecMeta($data);
        $data = $this->normalizeSkusBySpecType($data);

        // 业务校验（事务外）
        $this->validateCategoryAndBrand($data);
        $this->validateFreightTemplate($data);
        $this->validateSkuCodes($data['skus'] ?? [], null);
        $this->validateAssetRefs($data);

        // 事务内只做写入
        $goodsId = $this->transaction(function () use ($data) {
            $goods = $this->model();
            $goods->save($data);

            $goodsId = $goods->id;

            // 同步SKU
            if (!empty($data['skus']) && is_array($data['skus'])) {
                $this->syncSkus($goodsId, $data['skus']);
            }

            // 同步标签
            if (!empty($data['tag_ids']) && is_array($data['tag_ids'])) {
                $this->syncTags($goodsId, $data['tag_ids']);
            }

            $this->syncGoodsAssetUsage((int) $goodsId, $data);

            // 从SKU汇总价格和库存
            $this->updatePriceAndStock($goodsId);

            return $goodsId;
        });

        return (int) $goodsId;
    }

    /**
     * 更新商品
     *
     * @param int $id 商品 ID
     * @param array $data 商品数据
     * @return bool 更新成功返回 true
     * @throws BusinessException 商品、分类或品牌不存在时抛出
     */
    public function update(int $id, array $data): bool
    {
        $data = $this->normalizeImages($data);
        $data = $this->normalizeMainImage($data);
        $data = $this->normalizeSpecType($data);
        $data = $this->normalizeSpecMeta($data);
        $data = $this->normalizeSkusBySpecType($data);

        // 业务校验（事务外）
        $goods = $this->model()->where('id', $id)->whereNull('delete_time')->find();

        if (!$goods) {
            throw new BusinessException('商品不存在');
        }

        $this->validateCategoryAndBrand($data);
        $this->validateFreightTemplate($data);
        $this->validateSkuCodes($data['skus'] ?? [], $id);
        $this->validateAssetRefs($data);

        // 事务内只做写入
        $this->transaction(function () use ($goods, $data) {
            $goods->save($data);

            // 同步SKU
            if (array_key_exists('skus', $data) && is_array($data['skus'])) {
                $this->syncSkus((int) $goods->id, $data['skus']);
            }

            // 同步标签
            if (array_key_exists('tag_ids', $data) && is_array($data['tag_ids'])) {
                $this->syncTags((int) $goods->id, $data['tag_ids']);
            }

            $this->syncGoodsAssetUsage((int) $goods->id, $data);

            // 从SKU汇总价格和库存
            $this->updatePriceAndStock((int) $goods->id);

            return true;
        });

        return true;
    }

    /**
     * 删除商品
     *
     * @param int $id 商品 ID
     * @return bool 删除成功返回 true
     * @throws BusinessException 商品不存在时抛出
     */
    public function delete(int $id): bool
    {
        // 业务校验（事务外）
        $goods = $this->model()->where('id', $id)->whereNull('delete_time')->find();

        if (!$goods) {
            throw new BusinessException('商品不存在');
        }

        $goods->save(['delete_time' => time()]);
        return true;
    }

    public function restore(int $id): bool
    {
        $goods = $this->model()->where('id', $id)->whereNotNull('delete_time')->find();

        if (!$goods) {
            throw new BusinessException('回收站商品不存在');
        }

        $goods->save(['delete_time' => null]);
        return true;
    }

    public function purge(int $id): bool
    {
        $goods = $this->model()->where('id', $id)->whereNotNull('delete_time')->find();

        if (!$goods) {
            throw new BusinessException('回收站商品不存在');
        }

        return (bool) $this->transaction(function () use ($id, $goods) {
            $this->model(GoodsSku::class)->where('goods_id', $id)->delete();
            $this->model(GoodsTagRelation::class)->where('goods_id', $id)->delete();

            return $goods->delete();
        });
    }

    /**
     * 更新商品状态
     *
     * @param int $id 商品 ID
     * @param int $status 状态（1=启用，0=禁用）
     * @return bool 更新成功返回 true
     * @throws BusinessException 商品不存在时抛出
     */
    public function updateStatus(int $id, int $status): bool
    {
        $goods = $this->model()->where('id', $id)->whereNull('delete_time')->find();

        if (!$goods) {
            throw new BusinessException('商品不存在');
        }

        $goods->save(['status' => $status]);

        return true;
    }

    /**
     * 更新商品上架状态
     *
     * @param int $id 商品 ID
     * @param int $isOnSale 是否上架（1=上架，0=下架）
     * @return bool 更新成功返回 true
     * @throws BusinessException 商品不存在时抛出
     */
    public function updateOnSale(int $id, int $isOnSale): bool
    {
        $goods = $this->model()->where('id', $id)->whereNull('delete_time')->find();

        if (!$goods) {
            throw new BusinessException('商品不存在');
        }

        $goods->save(['is_on_sale' => $isOnSale]);

        return true;
    }

    /**
     * 同步商品SKU（先删后增）
     *
     * @param int $goodsId 商品 ID
     * @param array $skus SKU数据
     */
    protected function syncSkus(int $goodsId, array $skus): void
    {
        $this->model(GoodsSku::class)->where('goods_id', $goodsId)->delete();

        if (!empty($skus)) {
            $normalizer = app()->make(AssetIdNormalizer::class);
            $data = array_map(function ($sku) use ($goodsId, $normalizer) {
                return [
                    'goods_id' => $goodsId,
                    'spec_values' => $sku['spec_values'] ?? '',
                    'sku_code' => $sku['sku_code'] ?? '',
                    'price' => $sku['price'] ?? 0,
                    'market_price' => $sku['market_price'] ?? 0,
                    'stock' => $sku['stock'] ?? 0,
                    'image' => $normalizer->normalizeSingle($sku['image'] ?? ''),
                    'status' => $sku['status'] ?? 1,
                    'weight' => isset($sku['weight']) && $sku['weight'] !== '' ? (float) $sku['weight'] : null,
                ];
            }, $skus);
            $this->model(GoodsSku::class)->saveAll($data);
        }
    }

    /**
     * 校验 SKU 编码唯一性
     *
     * @param array $skus
     * @param int|null $excludeGoodsId
     */
    protected function validateSkuCodes(array $skus, ?int $excludeGoodsId): void
    {
        if (empty($skus)) {
            return;
        }

        $codes = [];
        foreach ($skus as $index => $sku) {
            $code = trim((string) ($sku['sku_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            if (isset($codes[$code])) {
                throw new BusinessException("SKU编码重复：{$code}");
            }

            $codes[$code] = $index;
        }

        if (empty($codes)) {
            return;
        }

        $query = $this->model(GoodsSku::class)->whereIn('sku_code', array_keys($codes));
        if ($excludeGoodsId !== null) {
            $query->where('goods_id', '<>', $excludeGoodsId);
        }

        $exists = $query->column('sku_code');
        if (!empty($exists)) {
            throw new BusinessException('SKU编码已存在：' . implode('、', array_unique($exists)));
        }
    }

    protected function normalizeSpecType(array $data): array
    {
        $rawSpecType = (int) ($data['spec_type'] ?? 0);
        if (in_array($rawSpecType, [Goods::SPEC_TYPE_SINGLE, Goods::SPEC_TYPE_MULTI], true)) {
            $data['spec_type'] = $rawSpecType;
            return $data;
        }

        $hasSpecMeta = !empty($data['spec_meta']) && is_array($data['spec_meta']);
        $hasMultiSku = false;
        foreach (($data['skus'] ?? []) as $sku) {
            if (!is_array($sku)) {
                continue;
            }

            if (trim((string) ($sku['spec_values'] ?? '')) !== '') {
                $hasMultiSku = true;
                break;
            }
        }

        $data['spec_type'] = ($hasSpecMeta || $hasMultiSku)
            ? Goods::SPEC_TYPE_MULTI
            : Goods::SPEC_TYPE_SINGLE;

        return $data;
    }

    /**
     * 规范化规格元数据
     */
    protected function normalizeSpecMeta(array $data): array
    {
        if (!array_key_exists('spec_meta', $data)) {
            if (($data['spec_type'] ?? Goods::SPEC_TYPE_SINGLE) === Goods::SPEC_TYPE_SINGLE) {
                $data['spec_meta'] = [];
            }
            return $data;
        }

        if (($data['spec_type'] ?? Goods::SPEC_TYPE_SINGLE) === Goods::SPEC_TYPE_SINGLE) {
            $data['spec_meta'] = [];
            return $data;
        }

        if (!is_array($data['spec_meta'])) {
            $data['spec_meta'] = [];
            return $data;
        }

        $normalizer = app()->make(AssetIdNormalizer::class);
        $data['spec_meta'] = array_values(array_map(function (array $item) use ($normalizer) {
            $values = array_values(array_map(function (array $value) use ($normalizer) {
                return [
                    'value' => (string) ($value['value'] ?? ''),
                    'pic' => $normalizer->normalizeSingle($value['pic'] ?? ''),
                ];
            }, array_filter($item['values'] ?? [], 'is_array')));

            return [
                'name' => (string) ($item['name'] ?? ''),
                'add_pic' => (int) (($item['add_pic'] ?? 0) ? 1 : 0),
                'values' => $values,
            ];
        }, array_filter($data['spec_meta'], 'is_array')));

        return $data;
    }

    protected function normalizeSkusBySpecType(array $data): array
    {
        if (($data['spec_type'] ?? Goods::SPEC_TYPE_SINGLE) === Goods::SPEC_TYPE_MULTI) {
            $data['skus'] = is_array($data['skus'] ?? null) ? array_values($data['skus']) : [];
            return $data;
        }

        $data['skus'] = [$this->buildSingleSpecSku($data)];
        return $data;
    }

    protected function buildSingleSpecSku(array $data): array
    {
        return [
            'spec_values' => self::DEFAULT_SINGLE_SKU_SPEC_VALUES,
            'sku_code' => '',
            'price' => $data['price'] ?? 0,
            'market_price' => $data['market_price'] ?? 0,
            'stock' => $data['stock'] ?? 0,
            'image' => app()->make(AssetIdNormalizer::class)->normalizeSingle($data['main_image'] ?? ''),
            'status' => $data['status'] ?? 1,
        ];
    }

    /**
     * 同步商品标签（先删后增）
     *
     * @param int $goodsId 商品 ID
     * @param array $tagIds 标签 ID 数组
     */
    protected function syncTags(int $goodsId, array $tagIds): void
    {
        $this->model(GoodsTagRelation::class)->where('goods_id', $goodsId)->delete();

        if (!empty($tagIds)) {
            $data = array_map(fn(int $tagId) => [
                'goods_id' => $goodsId,
                'tag_id' => $tagId,
            ], $tagIds);
            $this->model(GoodsTagRelation::class)->saveAll($data);
        }
    }

    /**
     * 从SKU汇总价格和库存
     *
     * @param int $goodsId 商品 ID
     */
    protected function updatePriceAndStock(int $goodsId): void
    {
        $goods = $this->model()->find($goodsId);
        if (!$goods) {
            return;
        }

        $skus = $this->model(GoodsSku::class)
            ->where('goods_id', $goodsId)
            ->select();

        if ($skus->isEmpty()) {
            return;
        }

        $goodsArray = $goods->toArray();
        $specType = (int) ($goodsArray['spec_type'] ?? Goods::SPEC_TYPE_SINGLE);

        if ($specType === Goods::SPEC_TYPE_SINGLE) {
            $singleSku = $skus->toArray()[0] ?? [];
            $goods->save([
                'price' => (float) ($singleSku['price'] ?? 0),
                'market_price' => $singleSku['market_price'] ?? null,
                'stock' => (int) ($singleSku['stock'] ?? 0),
            ]);
            return;
        }

        $minPrice = null;
        $minMarketPrice = null;
        $totalStock = 0;

        foreach ($skus->toArray() as $sku) {
            $price = (float) ($sku['price'] ?? 0);
            if ($minPrice === null || $price < $minPrice) {
                $minPrice = $price;
            }
            $marketPrice = $sku['market_price'] ?? null;
            if ($minMarketPrice === null && $marketPrice !== null && $marketPrice !== '') {
                $minMarketPrice = (float) $marketPrice;
            } elseif ($marketPrice !== null && $marketPrice !== '' && (float) $marketPrice < (float) $minMarketPrice) {
                $minMarketPrice = (float) $marketPrice;
            }
            $totalStock += (int) ($sku['stock'] ?? 0);
        }

        $goods->save([
            'price' => $minPrice ?? 0,
            'market_price' => $minMarketPrice,
            'stock' => $totalStock,
        ]);
    }

    /**
     * 批量获取商品标签（避免 N+1）
     *
     * @param array<int> $goodsIds 商品 ID 数组
     * @return array<int, array> 以 goods_id 为 key 的标签列表
     */
    protected function batchGetGoodsTags(array $goodsIds): array
    {
        if (empty($goodsIds)) {
            return [];
        }

        $relations = $this->model(GoodsTagRelation::class)
            ->whereIn('goods_id', $goodsIds)
            ->select();

        if ($relations->isEmpty()) {
            return [];
        }

        $groupedRelations = [];
        $tagIds = [];
        foreach ($relations->toArray() as $relation) {
            $groupedRelations[$relation['goods_id']][] = $relation['tag_id'];
            $tagIds[] = $relation['tag_id'];
        }

        $tagIds = array_unique($tagIds);
        $tags = $this->model(GoodsTag::class)
            ->whereIn('id', $tagIds)
            ->select()
            ->toArray();

        $tagMap = array_column($tags, null, 'id');

        $result = [];
        foreach ($groupedRelations as $goodsId => $tIds) {
            $goodsTags = [];
            foreach ($tIds as $tid) {
                if (isset($tagMap[$tid])) {
                    $goodsTags[] = $tagMap[$tid];
                }
            }
            $result[$goodsId] = $goodsTags;
        }

        return $result;
    }

    /**
     * 规范化轮播图字段：入库只保存图片路径数组
     */
    protected function normalizeImages(array $data): array
    {
        if (!array_key_exists('images', $data)) {
            return $data;
        }

        if (!is_array($data['images'])) {
            $data['images'] = [];
            return $data;
        }

        $images = [];
        foreach ($data['images'] as $image) {
            $url = app()->make(AssetIdNormalizer::class)->normalizeSingle($image);
            if ($url === '') {
                continue;
            }

            $images[] = $url;
        }

        $data['images'] = $images;
        return $data;
    }

    /**
     * 规范化主图字段：main_image 为空时，优先使用 images[0]
     */
    protected function normalizeMainImage(array $data): array
    {
        if (array_key_exists('main_image', $data)) {
            $data['main_image'] = app()->make(AssetIdNormalizer::class)->normalizeSingle($data['main_image']);
        }
        if (array_key_exists('main_video', $data)) {
            $data['main_video'] = app()->make(AssetIdNormalizer::class)->normalizeSingle($data['main_video']);
        }

        if (!empty($data['main_image'])) {
            return $data;
        }
        $firstImageValue = app()->make(AssetHydrator::class)->firstImageValue($data['images'] ?? []);
        if ($firstImageValue !== '') {
            $data['main_image'] = $firstImageValue;
        }
        return $data;
    }

    /**
     * 校验商品引用的素材是否存在。
     */
    protected function validateAssetRefs(array $data): void
    {
        $ids = $this->collectGoodsAssetIds($data);
        app()->make(AssetService::class)->assertUsableAssets($ids);
    }

    /**
     * @return array<int, int>
     */
    protected function collectGoodsAssetIds(array $data): array
    {
        $normalizer = app()->make(AssetIdNormalizer::class);
        $values = [];
        $values[] = $data['main_image'] ?? '';
        $values[] = $data['main_video'] ?? '';
        foreach ($normalizer->normalizeMany($data['images'] ?? []) as $image) {
            $values[] = $image;
        }
        foreach ((array) ($data['spec_meta'] ?? []) as $group) {
            foreach ((array) ($group['values'] ?? []) as $value) {
                $values[] = $value['pic'] ?? '';
            }
        }
        foreach ((array) ($data['skus'] ?? []) as $sku) {
            if (is_array($sku)) {
                $values[] = $sku['image'] ?? '';
            }
        }
        foreach ($this->extractAssetIdsFromHtml((string) ($data['description'] ?? '')) as $id) {
            $values[] = $id;
        }

        return $normalizer->collectAssetIds($values);
    }

    protected function syncGoodsAssetUsage(int $goodsId, array $data): void
    {
        $assetService = app()->make(AssetService::class);
        $normalizer = app()->make(AssetIdNormalizer::class);

        $assetService->syncUsage('goods', $goodsId, 'main_image', [$data['main_image'] ?? '']);
        $assetService->syncUsage('goods', $goodsId, 'main_video', [$data['main_video'] ?? '']);
        $assetService->syncUsage('goods', $goodsId, 'images', $normalizer->normalizeMany($data['images'] ?? []));

        $specPicIds = [];
        foreach ((array) ($data['spec_meta'] ?? []) as $group) {
            foreach ((array) ($group['values'] ?? []) as $value) {
                $specPicIds[] = $value['pic'] ?? '';
            }
        }
        $assetService->syncUsage('goods', $goodsId, 'spec_meta.values.pic', $specPicIds);

        $skuImageIds = [];
        foreach ((array) ($data['skus'] ?? []) as $sku) {
            if (is_array($sku)) {
                $skuImageIds[] = $sku['image'] ?? '';
            }
        }
        $assetService->syncUsage('goods', $goodsId, 'skus.image', $skuImageIds);
        $assetService->syncUsage('goods', $goodsId, 'description', $this->extractAssetIdsFromHtml((string) ($data['description'] ?? '')));
    }

    /**
     * @return array<int, int>
     */
    protected function extractAssetIdsFromHtml(string $html): array
    {
        if ($html === '' || !str_contains($html, 'data-asset-id')) {
            return [];
        }

        preg_match_all('/\bdata-asset-id=["\']?(\d+)["\']?/i', $html, $matches);
        return array_values(array_unique(array_map('intval', $matches[1] ?? [])));
    }

    /**
     * 校验分类和品牌存在性
     *
     * @param array $data 商品数据
     * @throws BusinessException 分类或品牌不存在时抛出
     */
    protected function validateCategoryAndBrand(array $data): void
    {
        if (!empty($data['category_id'])) {
            $category = $this->model(GoodsCategory::class)->find($data['category_id']);
            if (!$category) {
                throw new BusinessException('商品分类不存在');
            }
        }

        if (!empty($data['brand_id'])) {
            $brand = $this->model(GoodsBrand::class)->find($data['brand_id']);
            if (!$brand) {
                throw new BusinessException('品牌不存在');
            }
        }
    }

    /**
     * 校验运费模板存在且启用（空 / 0 表示包邮，跳过校验）
     *
     * @param array $data 商品数据
     * @throws BusinessException 模板不存在或已停用时抛出
     */
    protected function validateFreightTemplate(array $data): void
    {
        if (empty($data['freight_template_id'])) {
            return;
        }

        $template = $this->model(FreightTemplate::class)->find($data['freight_template_id']);
        if (!$template) {
            throw new BusinessException('运费模板不存在');
        }
        if ((int) $template->status !== 1) {
            throw new BusinessException('运费模板已停用');
        }
    }
}
