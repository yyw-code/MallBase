<?php
declare(strict_types=1);

namespace app\admin\service\goods;

use app\admin\model\goods\Goods;
use app\admin\model\goods\GoodsBrand;
use app\admin\model\goods\GoodsCategory;
use app\admin\model\goods\GoodsImage;
use app\admin\model\goods\GoodsSku;
use app\admin\model\goods\GoodsTag;
use app\admin\model\goods\GoodsTagRelation;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 商品服务
 * @extends BaseService<Goods>
 */
class GoodsService extends BaseService
{
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

            // 批量获取标签
            $goodsIds = array_column($listArray, 'id');
            $tagMap = $this->batchGetGoodsTags($goodsIds);
            $firstImageMap = $this->batchGetFirstImageMap($goodsIds);

            foreach ($listArray as &$item) {
                if (empty($item['main_image']) && !empty($firstImageMap[$item['id']])) {
                    $item['main_image'] = $firstImageMap[$item['id']];
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
        $result['spec_meta'] = $this->hydrateSpecMeta($result['spec_meta'] ?? []);

        // 获取商品图片
        $images = $this->model(GoodsImage::class)
            ->where('goods_id', $id)
            ->order('sort', 'asc')
            ->select();
        $result['images'] = $images->toArray();
        if (empty($result['main_image']) && !empty($result['images'][0]['url'])) {
            $result['main_image'] = $result['images'][0]['url'];
            $result['main_image_full_url'] = $result['images'][0]['full_url'] ?? '';
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
        $data = $this->normalizeMainImage($data);
        $data = $this->normalizeSpecMeta($data);

        // 业务校验（事务外）
        $this->validateCategoryAndBrand($data);
        $this->validateSkuCodes($data['skus'] ?? [], null);

        // 事务内只做写入
        $goodsId = $this->transaction(function () use ($data) {
            $goods = $this->model();
            $goods->save($data);

            $goodsId = $goods->id;
            $this->persistSpecMeta((int) $goodsId, $data['spec_meta'] ?? null);

            // 同步图片
            if (!empty($data['images']) && is_array($data['images'])) {
                $this->syncImages($goodsId, $data['images']);
            }

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
        $data = $this->normalizeMainImage($data);
        $data = $this->normalizeSpecMeta($data);

        // 业务校验（事务外）
        $goods = $this->model()->find($id);

        if (!$goods) {
            throw new BusinessException('商品不存在');
        }

        $this->validateCategoryAndBrand($data);
        $this->validateSkuCodes($data['skus'] ?? [], $id);

        // 事务内只做写入
        $this->transaction(function () use ($id, $goods, $data) {
            $goods->save($data);
            $this->persistSpecMeta($id, $data['spec_meta'] ?? null);

            // 同步图片
            if (array_key_exists('images', $data) && is_array($data['images'])) {
                $this->syncImages($id, $data['images']);
            }

            // 同步SKU
            if (array_key_exists('skus', $data) && is_array($data['skus'])) {
                $this->syncSkus($id, $data['skus']);
            }

            // 同步标签
            if (array_key_exists('tag_ids', $data) && is_array($data['tag_ids'])) {
                $this->syncTags($id, $data['tag_ids']);
            }

            // 从SKU汇总价格和库存
            $this->updatePriceAndStock($id);

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
            $this->model(GoodsImage::class)->where('goods_id', $id)->delete();
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
     * 同步商品图片（先删后增）
     *
     * @param int $goodsId 商品 ID
     * @param array $images 图片数据
     */
    protected function syncImages(int $goodsId, array $images): void
    {
        $this->model(GoodsImage::class)->where('goods_id', $goodsId)->delete();

        if (!empty($images)) {
            $data = array_map(function ($image, $index) use ($goodsId) {
                return [
                    'goods_id' => $goodsId,
                    'url' => $image['url'] ?? '',
                    'sort' => $image['sort'] ?? $index,
                ];
            }, $images, array_keys($images));
            $this->model(GoodsImage::class)->saveAll($data);
        }
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

    /**
     * 规范化规格元数据
     */
    protected function normalizeSpecMeta(array $data): array
    {
        if (!array_key_exists('spec_meta', $data)) {
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

    /**
     * 为规格元数据补充完整图片地址
     *
     * @param mixed $specMeta
     * @return array
     */
    protected function hydrateSpecMeta($specMeta): array
    {
        if (is_string($specMeta) && $specMeta !== '') {
            $decoded = json_decode($specMeta, true);
            $specMeta = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($specMeta)) {
            return [];
        }

        return array_values(array_map(function (array $item) {
            $values = array_values(array_map(function (array $value) {
                $pic = (string) ($value['pic'] ?? '');
                return [
                    'value' => (string) ($value['value'] ?? ''),
                    'pic' => $pic,
                    'pic_full_url' => buildUploadUrl($pic),
                ];
            }, array_filter($item['values'] ?? [], 'is_array')));

            return [
                'name' => (string) ($item['name'] ?? ''),
                'add_pic' => (int) (($item['add_pic'] ?? 0) ? 1 : 0),
                'values' => $values,
            ];
        }, array_filter($specMeta, 'is_array')));
    }

    /**
     * 通过模型持久化规格元数据。
     *
     * 规格元数据是商品正式字段，仍然必须走模型层，避免绕过项目约定。
     *
     * @param int $goodsId
     * @param mixed $specMeta
     */
    protected function persistSpecMeta(int $goodsId, $specMeta): void
    {
        $this->model()->updateById($goodsId, [
            'spec_meta' => is_array($specMeta) ? $specMeta : null,
        ]);
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
        $skus = $this->model(GoodsSku::class)
            ->where('goods_id', $goodsId)
            ->select();

        if ($skus->isEmpty()) {
            return;
        }

        $minPrice = null;
        $totalStock = 0;

        foreach ($skus->toArray() as $sku) {
            $price = (float) ($sku['price'] ?? 0);
            if ($minPrice === null || $price < $minPrice) {
                $minPrice = $price;
            }
            $totalStock += (int) ($sku['stock'] ?? 0);
        }

        $goods = $this->model()->find($goodsId);
        if ($goods) {
            $goods->save([
                'price' => $minPrice ?? 0,
                'stock' => $totalStock,
            ]);
        }
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
     * 批量获取商品首图（按 sort ASC, id ASC）
     *
     * @param array<int> $goodsIds 商品 ID 数组
     * @return array<int, string> key=goods_id, value=url
     */
    protected function batchGetFirstImageMap(array $goodsIds): array
    {
        if (empty($goodsIds)) {
            return [];
        }

        $images = $this->model(GoodsImage::class)
            ->whereIn('goods_id', $goodsIds)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $result = [];
        foreach ($images as $image) {
            $goodsId = (int)($image['goods_id'] ?? 0);
            if ($goodsId <= 0 || isset($result[$goodsId])) {
                continue;
            }
            $result[$goodsId] = (string)($image['url'] ?? '');
        }
        return $result;
    }

    /**
     * 规范化主图字段：main_image 为空时，优先使用 images[0].url
     */
    protected function normalizeMainImage(array $data): array
    {
        if (!empty($data['main_image'])) {
            return $data;
        }
        if (!empty($data['images']) && is_array($data['images'])) {
            $first = $data['images'][0] ?? null;
            if (is_array($first) && !empty($first['url'])) {
                $data['main_image'] = $first['url'];
            }
        }
        return $data;
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
