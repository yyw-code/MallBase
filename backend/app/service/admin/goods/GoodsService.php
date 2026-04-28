<?php
declare(strict_types=1);

namespace app\service\admin\goods;

use app\model\goods\Goods;
use app\model\goods\GoodsBrand;
use app\model\goods\GoodsCategory;
use app\model\goods\GoodsSku;
use app\model\goods\GoodsTag;
use app\model\goods\GoodsTagRelation;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 商品服务
 * @extends BaseService<Goods>
 */
class GoodsService extends BaseService
{
    private const DEFAULT_SINGLE_SKU_SPEC_VALUES = '';

    /**
     * 默认 Model 类名
     */
    protected string $modelClass = Goods::class;

    /**
     * 构建列表查询条件
     */
    protected function buildListQuery(array $where)
    {
        return $this->model()
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
        if (!empty($listArray)) {
            // 批量获取分类名
            $categoryIds = array_unique(array_column($listArray, 'category_id'));
            $categories = $this->model(GoodsCategory::class)
                ->whereIn('id', $categoryIds)
                ->column('name', 'id');

            // 批量获取品牌名
            $brandIds = array_filter(array_unique(array_column($listArray, 'brand_id')));
            $brands = !empty($brandIds)
                ? $this->model(GoodsBrand::class)->whereIn('id', $brandIds)->column('name', 'id')
                : [];

            $goodsIds = array_column($listArray, 'id');
            $tagMap = $this->batchGetGoodsTags($goodsIds);

            foreach ($listArray as &$item) {
                $firstImageUrl = $this->getFirstImageUrl($item['images'] ?? []);
                if (empty($item['main_image']) && $firstImageUrl !== '') {
                    $item['main_image'] = $firstImageUrl;
                    $item['main_image_full_url'] = buildUploadUrl($item['main_image']);
                }
                $item['category_name'] = $categories[$item['category_id']] ?? '';
                $item['brand_name'] = $brands[$item['brand_id']] ?? '';
                $item['tags'] = $tagMap[$item['id']] ?? [];
            }
        }

        $list = $listArray;
        return compact('total', 'list');
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
        $goods = $this->model()->find($id);

        if (!$goods) {
            throw new BusinessException('商品不存在');
        }

        $result = $goods->toArray();
        $firstImageUrl = $this->getFirstImageUrl($result['images'] ?? []);
        if (empty($result['main_image']) && $firstImageUrl !== '') {
            $result['main_image'] = $firstImageUrl;
            $result['main_image_full_url'] = buildUploadUrl($firstImageUrl);
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

        return $result;
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
        $this->validateSkuCodes($data['skus'] ?? [], null);

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
        $goods = $this->model()->find($id);

        if (!$goods) {
            throw new BusinessException('商品不存在');
        }

        $this->validateCategoryAndBrand($data);
        $this->validateSkuCodes($data['skus'] ?? [], $id);

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
        $goods = $this->model()->find($id);

        if (!$goods) {
            throw new BusinessException('商品不存在');
        }

        // 事务内删除关联数据
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
        $goods = $this->model()->find($id);

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
        $goods = $this->model()->find($id);

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
            $data = array_map(function ($sku) use ($goodsId) {
                return [
                    'goods_id' => $goodsId,
                    'spec_values' => $sku['spec_values'] ?? '',
                    'sku_code' => $sku['sku_code'] ?? '',
                    'price' => $sku['price'] ?? 0,
                    'market_price' => $sku['market_price'] ?? 0,
                    'stock' => $sku['stock'] ?? 0,
                    'image' => $sku['image'] ?? '',
                    'status' => $sku['status'] ?? 1,
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

        $data['spec_meta'] = array_values(array_map(function (array $item) {
            $values = array_values(array_map(function (array $value) {
                return [
                    'value' => (string) ($value['value'] ?? ''),
                    'pic' => (string) ($value['pic'] ?? ''),
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
            'image' => $data['main_image'] ?? '',
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
            $url = is_array($image) ? (string) ($image['url'] ?? '') : (string) $image;
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
        if (!empty($data['main_image'])) {
            return $data;
        }
        $firstImageUrl = $this->getFirstImageUrl($data['images'] ?? []);
        if ($firstImageUrl !== '') {
            $data['main_image'] = $firstImageUrl;
        }
        return $data;
    }

    protected function getFirstImageUrl(mixed $images): string
    {
        if (!is_array($images)) {
            return '';
        }

        $first = $images[0] ?? null;
        if (is_array($first)) {
            return (string) ($first['url'] ?? '');
        }

        return (string) ($first ?? '');
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
}
