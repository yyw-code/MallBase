<?php
declare(strict_types=1);

namespace app\service\admin\goods;

use app\model\goods\Goods;
use app\model\goods\GoodsBrand;
use app\model\goods\GoodsCategory;
use app\model\goods\GoodsDetail;
use app\model\goods\GoodsSku;
use app\model\goods\GoodsSkuDetail;
use app\model\goods\GoodsTag;
use app\model\goods\GoodsTagRelation;
use app\model\distribution\DistributionCommissionRule;
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
    private const GOODS_POINTS_REWARD_MODES = ['global', 'disabled', 'ratio', 'fixed', 'sku'];
    private const SKU_POINTS_REWARD_MODES = ['inherit', 'disabled', 'ratio', 'fixed'];
    private const MEMBER_BENEFIT_MODES = ['global', 'disabled', 'level_discount', 'sku_price'];
    private const GOODS_DISTRIBUTION_COMMISSION_MODES = ['global', 'disabled', 'rate', 'fixed', 'sku', 'sku_rate', 'sku_fixed'];
    private const SKU_DISTRIBUTION_COMMISSION_MODES = ['inherit', 'disabled', 'rate', 'fixed'];
    private const DISTRIBUTION_COMMISSION_FIELDS = [
        'distribution_commission_mode',
        'distribution_first_rate',
        'distribution_second_rate',
        'distribution_first_fixed_amount',
        'distribution_second_fixed_amount',
    ];

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
        $result['description'] = $this->getGoodsDescription($id);
        $result['skus'] = $this->appendSkuDescriptions($skus->toArray());
        $result = $this->appendDistributionCommissionRules($result);

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
        $data = $this->normalizePointsReward($data);
        $data = $this->normalizeMemberBenefit($data);
        $data = $this->normalizeSpecType($data);
        $data = $this->normalizeSkuDetailEnabled($data);
        $data = $this->normalizeSpecMeta($data);
        $data = $this->normalizeDistributionCommission($data);
        $data = $this->normalizeSkusBySpecType($data);

        // 业务校验（事务外）
        $this->validateCategoryAndBrand($data);
        $this->validateFreightTemplate($data);
        $this->validateSkuCodes($data['skus'] ?? [], null);
        $this->validateAssetRefs($data);

        // 事务内只做写入
        $goodsId = $this->transaction(function () use ($data) {
            $goods = $this->model();
            $goods->save($this->buildGoodsSaveData($data));

            $goodsId = $goods->id;

            $this->syncGoodsDetail((int) $goodsId, (string) ($data['description'] ?? ''));

            // 同步SKU
            $savedSkus = [];
            if (!empty($data['skus']) && is_array($data['skus'])) {
                $savedSkus = $this->syncSkus($goodsId, $data['skus'], (int) ($data['sku_detail_enabled'] ?? 0) === 1);
            }
            $this->syncDistributionCommissionRules((int) $goodsId, $data, $savedSkus);

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
        $data = $this->normalizePointsReward($data);
        $data = $this->normalizeMemberBenefit($data);
        $data = $this->normalizeSpecType($data);
        $data = $this->normalizeSkuDetailEnabled($data);
        $data = $this->normalizeSpecMeta($data);
        $data = $this->normalizeDistributionCommission($data);
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
        $oldSkuIds = !empty($data['_sync_distribution_commission'])
            ? $this->skuIdsForGoods((int) $goods->id)
            : [];

        $this->transaction(function () use ($goods, $data, $oldSkuIds) {
            $goods->save($this->buildGoodsSaveData($data));

            $this->syncGoodsDetail((int) $goods->id, (string) ($data['description'] ?? ''));

            // 同步SKU
            $savedSkus = [];
            if (array_key_exists('skus', $data) && is_array($data['skus'])) {
                $savedSkus = $this->syncSkus((int) $goods->id, $data['skus'], (int) ($data['sku_detail_enabled'] ?? 0) === 1);
            }
            $this->syncDistributionCommissionRules((int) $goods->id, $data, $savedSkus, $oldSkuIds);

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
            $this->model(GoodsDetail::class)->where('goods_id', $id)->delete();
            $this->model(GoodsSkuDetail::class)->where('goods_id', $id)->delete();
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
    protected function syncSkus(int $goodsId, array $skus, bool $syncDescriptions = true): array
    {
        $this->model(GoodsSku::class)->where('goods_id', $goodsId)->delete();
        $this->model(GoodsSkuDetail::class)->where('goods_id', $goodsId)->delete();

        $savedSkus = [];
        if (!empty($skus)) {
            $data = array_map(function ($sku) use ($goodsId) {
                return [
                    'goods_id' => $goodsId,
                    'spec_values' => $sku['spec_values'] ?? '',
                    'sku_code' => $sku['sku_code'] ?? '',
                    'price' => $sku['price'] ?? 0,
                    'market_price' => $sku['market_price'] ?? 0,
                    'stock' => $sku['stock'] ?? 0,
                    'image' => $this->normalizeNullableAssetId($sku['image'] ?? ''),
                    'status' => $sku['status'] ?? 1,
                    'weight' => isset($sku['weight']) && $sku['weight'] !== '' ? (float) $sku['weight'] : null,
                    'points_reward_mode' => $this->normalizeSkuPointsRewardMode((string) ($sku['points_reward_mode'] ?? 'inherit')),
                    'points_reward_ratio' => max(0, (int) ($sku['points_reward_ratio'] ?? 0)),
                    'points_reward_fixed' => max(0, (int) ($sku['points_reward_fixed'] ?? 0)),
                    'member_price' => $this->normalizeNullablePrice($sku['member_price'] ?? null),
                ];
            }, $skus);
            $this->model(GoodsSku::class)->saveAll($data);
            $savedSkus = $this->model(GoodsSku::class)
                ->where('goods_id', $goodsId)
                ->order('id', 'asc')
                ->select()
                ->toArray();
            if ($syncDescriptions) {
                $this->syncSkuDetails($goodsId, $savedSkus, $skus);
            }
        }

        return $savedSkus;
    }

    protected function buildGoodsSaveData(array $data): array
    {
        unset(
            $data['description'],
            $data['skus'],
            $data['tag_ids'],
            $data['_sync_distribution_commission'],
            $data['distribution_commission_mode'],
            $data['distribution_first_rate'],
            $data['distribution_second_rate'],
            $data['distribution_first_fixed_amount'],
            $data['distribution_second_fixed_amount'],
        );
        return $data;
    }

    protected function syncGoodsDetail(int $goodsId, string $description): void
    {
        $description = mb_substr($description, 0, 16000);
        $this->model(GoodsDetail::class)->where('goods_id', $goodsId)->delete();

        if ($description === '') {
            return;
        }

        $detail = $this->model(GoodsDetail::class);
        $detail->save([
            'goods_id' => $goodsId,
            'description' => $description,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $savedSkus
     * @param array<int, array<string, mixed>> $inputSkus
     */
    protected function syncSkuDetails(int $goodsId, array $savedSkus, array $inputSkus): void
    {
        $rows = [];
        foreach ($savedSkus as $index => $sku) {
            $description = mb_substr((string) ($inputSkus[$index]['description'] ?? ''), 0, 16000);
            if ($description === '') {
                continue;
            }

            $rows[] = [
                'goods_id' => $goodsId,
                'sku_id' => (int) ($sku['id'] ?? 0),
                'description' => $description,
            ];
        }

        if ($rows !== []) {
            $this->model(GoodsSkuDetail::class)->saveAll($rows);
        }
    }

    protected function getGoodsDescription(int $goodsId): string
    {
        return (string) ($this->model(GoodsDetail::class)
            ->where('goods_id', $goodsId)
            ->value('description') ?? '');
    }

    /**
     * @param array<int, array<string, mixed>> $skus
     * @return array<int, array<string, mixed>>
     */
    protected function appendSkuDescriptions(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $skuIds = array_values(array_filter(array_map(static fn(array $sku): int => (int) ($sku['id'] ?? 0), $skus)));
        if ($skuIds === []) {
            return $skus;
        }

        $detailMap = $this->model(GoodsSkuDetail::class)
            ->whereIn('sku_id', $skuIds)
            ->column('description', 'sku_id');

        foreach ($skus as &$sku) {
            $skuId = (int) ($sku['id'] ?? 0);
            $sku['description'] = (string) ($detailMap[$skuId] ?? '');
        }
        unset($sku);

        return $skus;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    protected function appendDistributionCommissionRules(array $result): array
    {
        $goodsId = (int) ($result['id'] ?? 0);
        $skus = is_array($result['skus'] ?? null) ? $result['skus'] : [];
        $result = $this->fillDistributionCommissionFields($result, null, 'global');
        if ($goodsId <= 0) {
            return $result;
        }

        $goodsRule = $this->model(DistributionCommissionRule::class)
            ->where('target_type', DistributionCommissionRule::TARGET_GOODS)
            ->where('target_id', $goodsId)
            ->find();

        $skuIds = array_values(array_filter(array_map(
            static fn(array $sku): int => (int) ($sku['id'] ?? 0),
            $skus
        )));
        $skuRuleMap = [];
        if ($skuIds !== []) {
            $skuRules = $this->model(DistributionCommissionRule::class)
                ->where('target_type', DistributionCommissionRule::TARGET_SKU)
                ->whereIn('target_id', $skuIds)
                ->select()
                ->toArray();
            foreach ($skuRules as $rule) {
                $skuRuleMap[(int) ($rule['target_id'] ?? 0)] = $rule;
            }
        }

        $hasSkuRule = false;
        foreach ($skus as &$sku) {
            $rule = $skuRuleMap[(int) ($sku['id'] ?? 0)] ?? null;
            if ($rule !== null) {
                $hasSkuRule = true;
            }
            $sku = $this->fillDistributionCommissionFields($sku, $rule, 'inherit');
        }
        unset($sku);

        $result['skus'] = $skus;
        if ($hasSkuRule) {
            $result['distribution_commission_mode'] = $this->distributionSkuGoodsModeFromRules(array_values($skuRuleMap));
            return $result;
        }

        return $this->fillDistributionCommissionFields($result, $goodsRule?->toArray(), 'global');
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed>|null $rule
     * @return array<string, mixed>
     */
    private function fillDistributionCommissionFields(array $target, ?array $rule, string $fallbackMode): array
    {
        $mode = $this->distributionCommissionModeFromRule($rule, $fallbackMode);
        $target['distribution_commission_mode'] = $mode;
        $target['distribution_first_rate'] = $rule === null ? '0.00' : number_format((float) ($rule['first_rate'] ?? 0), 2, '.', '');
        $target['distribution_second_rate'] = $rule === null ? '0.00' : number_format((float) ($rule['second_rate'] ?? 0), 2, '.', '');
        $target['distribution_first_fixed_amount'] = $rule === null ? '0.00' : $this->centsToAmount((int) ($rule['first_fixed_cents'] ?? 0));
        $target['distribution_second_fixed_amount'] = $rule === null ? '0.00' : $this->centsToAmount((int) ($rule['second_fixed_cents'] ?? 0));

        return $target;
    }

    /**
     * @param array<string, mixed>|null $rule
     */
    private function distributionCommissionModeFromRule(?array $rule, string $fallbackMode): string
    {
        if ($rule === null || (int) ($rule['status'] ?? 0) !== 1) {
            return $fallbackMode;
        }

        $firstRate = (float) ($rule['first_rate'] ?? 0);
        $secondRate = (float) ($rule['second_rate'] ?? 0);
        $firstFixedCents = (int) ($rule['first_fixed_cents'] ?? 0);
        $secondFixedCents = (int) ($rule['second_fixed_cents'] ?? 0);
        if ($firstRate <= 0 && $secondRate <= 0 && $firstFixedCents <= 0 && $secondFixedCents <= 0) {
            return 'disabled';
        }

        return (string) ($rule['commission_type'] ?? DistributionCommissionRule::COMMISSION_TYPE_RATE) === DistributionCommissionRule::COMMISSION_TYPE_FIXED
            ? 'fixed'
            : 'rate';
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function distributionSkuGoodsModeFromRules(array $rules): string
    {
        foreach ($rules as $rule) {
            if ((int) ($rule['status'] ?? 0) !== 1) {
                continue;
            }

            return (string) ($rule['commission_type'] ?? DistributionCommissionRule::COMMISSION_TYPE_RATE) === DistributionCommissionRule::COMMISSION_TYPE_FIXED
                ? 'sku_fixed'
                : 'sku_rate';
        }

        return 'sku_rate';
    }

    /**
     * @param array<int, array<string, mixed>> $savedSkus
     * @param array<int, int> $oldSkuIds
     */
    protected function syncDistributionCommissionRules(int $goodsId, array $data, array $savedSkus, array $oldSkuIds = []): void
    {
        if (empty($data['_sync_distribution_commission'])) {
            return;
        }

        $this->model(DistributionCommissionRule::class)
            ->where('target_type', DistributionCommissionRule::TARGET_GOODS)
            ->where('target_id', $goodsId)
            ->delete();

        $currentSkuIds = array_values(array_filter(array_map(
            static fn(array $sku): int => (int) ($sku['id'] ?? 0),
            $savedSkus
        )));
        if ($currentSkuIds === []) {
            $currentSkuIds = $this->skuIdsForGoods($goodsId);
        }
        $skuIds = array_values(array_unique(array_filter(array_merge($oldSkuIds, $currentSkuIds))));
        if ($skuIds !== []) {
            $this->model(DistributionCommissionRule::class)
                ->where('target_type', DistributionCommissionRule::TARGET_SKU)
                ->whereIn('target_id', $skuIds)
                ->delete();
        }

        $mode = (string) ($data['distribution_commission_mode'] ?? 'global');
        $rows = [];
        if (in_array($mode, ['disabled', 'rate', 'fixed'], true)) {
            $rows[] = $this->buildDistributionCommissionRuleRow(
                DistributionCommissionRule::TARGET_GOODS,
                $goodsId,
                '商品佣金规则',
                $mode,
                $data
            );
        } elseif (in_array($mode, ['sku_rate', 'sku_fixed'], true)) {
            $skuMode = $mode === 'sku_fixed' ? 'fixed' : 'rate';
            foreach ($savedSkus as $index => $sku) {
                $inputSku = is_array($data['skus'][$index] ?? null) ? $data['skus'][$index] : [];
                $specValues = trim((string) ($sku['spec_values'] ?? ''));
                $rows[] = $this->buildDistributionCommissionRuleRow(
                    DistributionCommissionRule::TARGET_SKU,
                    (int) ($sku['id'] ?? 0),
                    mb_substr($specValues === '' ? 'SKU佣金规则' : 'SKU佣金规则-' . $specValues, 0, 100),
                    $skuMode,
                    $inputSku
                );
            }
        } elseif ($mode === 'sku') {
            foreach ($savedSkus as $index => $sku) {
                $inputSku = is_array($data['skus'][$index] ?? null) ? $data['skus'][$index] : [];
                $skuMode = (string) ($inputSku['distribution_commission_mode'] ?? 'inherit');
                if ($skuMode === 'inherit') {
                    continue;
                }
                $specValues = trim((string) ($sku['spec_values'] ?? ''));
                $rows[] = $this->buildDistributionCommissionRuleRow(
                    DistributionCommissionRule::TARGET_SKU,
                    (int) ($sku['id'] ?? 0),
                    mb_substr($specValues === '' ? 'SKU佣金规则' : 'SKU佣金规则-' . $specValues, 0, 100),
                    $skuMode,
                    $inputSku
                );
            }
        }

        if ($rows !== []) {
            $this->model(DistributionCommissionRule::class)->saveAll($rows);
        }
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function buildDistributionCommissionRuleRow(
        string $targetType,
        int $targetId,
        string $name,
        string $mode,
        array $source
    ): array {
        $commissionType = $mode === 'fixed'
            ? DistributionCommissionRule::COMMISSION_TYPE_FIXED
            : DistributionCommissionRule::COMMISSION_TYPE_RATE;

        return [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'name' => $name,
            'commission_type' => $commissionType,
            'first_rate' => $mode === 'rate' ? (string) ($source['distribution_first_rate'] ?? '0.00') : '0.00',
            'second_rate' => $mode === 'rate' ? (string) ($source['distribution_second_rate'] ?? '0.00') : '0.00',
            'first_fixed_cents' => $mode === 'fixed' ? $this->amountToCents($source['distribution_first_fixed_amount'] ?? 0) : 0,
            'second_fixed_cents' => $mode === 'fixed' ? $this->amountToCents($source['distribution_second_fixed_amount'] ?? 0) : 0,
            'status' => 1,
            'remark' => '商品编辑同步',
        ];
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
        $submittedSku = is_array($data['skus'][0] ?? null) ? $data['skus'][0] : [];

        return [
            'spec_values' => self::DEFAULT_SINGLE_SKU_SPEC_VALUES,
            'sku_code' => '',
            'price' => $data['price'] ?? 0,
            'market_price' => $data['market_price'] ?? 0,
            'stock' => $data['stock'] ?? 0,
            'image' => $this->normalizeNullableAssetId($data['main_image'] ?? null),
            'description' => '',
            'status' => $data['status'] ?? 1,
            'points_reward_mode' => $this->normalizeSkuPointsRewardMode((string) ($submittedSku['points_reward_mode'] ?? 'inherit')),
            'points_reward_ratio' => max(0, (int) ($submittedSku['points_reward_ratio'] ?? 0)),
            'points_reward_fixed' => max(0, (int) ($submittedSku['points_reward_fixed'] ?? 0)),
            'member_price' => $this->normalizeNullablePrice($submittedSku['member_price'] ?? null),
            'distribution_commission_mode' => $this->normalizeSkuDistributionCommissionMode((string) ($submittedSku['distribution_commission_mode'] ?? 'inherit')),
            'distribution_first_rate' => $this->normalizeCommissionRate($submittedSku['distribution_first_rate'] ?? 0),
            'distribution_second_rate' => $this->normalizeCommissionRate($submittedSku['distribution_second_rate'] ?? 0),
            'distribution_first_fixed_amount' => $this->normalizeCommissionAmount($submittedSku['distribution_first_fixed_amount'] ?? 0),
            'distribution_second_fixed_amount' => $this->normalizeCommissionAmount($submittedSku['distribution_second_fixed_amount'] ?? 0),
        ];
    }

    protected function normalizePointsReward(array $data): array
    {
        $data['points_reward_mode'] = $this->normalizeGoodsPointsRewardMode((string) ($data['points_reward_mode'] ?? 'global'));
        $data['points_reward_ratio'] = max(0, (int) ($data['points_reward_ratio'] ?? 0));
        $data['points_reward_fixed'] = max(0, (int) ($data['points_reward_fixed'] ?? 0));

        foreach (($data['skus'] ?? []) as &$sku) {
            if (!is_array($sku)) {
                continue;
            }
            $sku['points_reward_mode'] = $this->normalizeSkuPointsRewardMode((string) ($sku['points_reward_mode'] ?? 'inherit'));
            $sku['points_reward_ratio'] = max(0, (int) ($sku['points_reward_ratio'] ?? 0));
            $sku['points_reward_fixed'] = max(0, (int) ($sku['points_reward_fixed'] ?? 0));
        }
        unset($sku);

        return $data;
    }

    protected function normalizeMemberBenefit(array $data): array
    {
        $data['member_benefit_mode'] = $this->normalizeMemberBenefitMode((string) ($data['member_benefit_mode'] ?? 'global'));

        foreach (($data['skus'] ?? []) as &$sku) {
            if (!is_array($sku)) {
                continue;
            }
            $sku['member_price'] = $this->normalizeNullablePrice($sku['member_price'] ?? null);
        }
        unset($sku);

        return $data;
    }

    protected function normalizeDistributionCommission(array $data): array
    {
        if (!$this->hasDistributionCommissionPayload($data)) {
            return $data;
        }

        $data['_sync_distribution_commission'] = true;
        $data['distribution_commission_mode'] = $this->normalizeGoodsDistributionCommissionMode((string) ($data['distribution_commission_mode'] ?? 'global'));
        $data['distribution_first_rate'] = $this->normalizeCommissionRate($data['distribution_first_rate'] ?? 0);
        $data['distribution_second_rate'] = $this->normalizeCommissionRate($data['distribution_second_rate'] ?? 0);
        $data['distribution_first_fixed_amount'] = $this->normalizeCommissionAmount($data['distribution_first_fixed_amount'] ?? 0);
        $data['distribution_second_fixed_amount'] = $this->normalizeCommissionAmount($data['distribution_second_fixed_amount'] ?? 0);
        if ($data['distribution_commission_mode'] === 'rate') {
            $this->assertCommissionRatePair($data['distribution_first_rate'], $data['distribution_second_rate']);
        }

        foreach (($data['skus'] ?? []) as &$sku) {
            if (!is_array($sku)) {
                continue;
            }
            $sku['distribution_commission_mode'] = $this->normalizeSkuDistributionCommissionMode((string) ($sku['distribution_commission_mode'] ?? 'inherit'));
            $sku['distribution_first_rate'] = $this->normalizeCommissionRate($sku['distribution_first_rate'] ?? 0);
            $sku['distribution_second_rate'] = $this->normalizeCommissionRate($sku['distribution_second_rate'] ?? 0);
            $sku['distribution_first_fixed_amount'] = $this->normalizeCommissionAmount($sku['distribution_first_fixed_amount'] ?? 0);
            $sku['distribution_second_fixed_amount'] = $this->normalizeCommissionAmount($sku['distribution_second_fixed_amount'] ?? 0);
            if ($sku['distribution_commission_mode'] === 'rate' || $data['distribution_commission_mode'] === 'sku_rate') {
                $this->assertCommissionRatePair($sku['distribution_first_rate'], $sku['distribution_second_rate']);
            }
        }
        unset($sku);

        return $data;
    }

    protected function normalizeSkuDetailEnabled(array $data): array
    {
        $specType = (int) ($data['spec_type'] ?? Goods::SPEC_TYPE_SINGLE);
        $data['sku_detail_enabled'] = $specType === Goods::SPEC_TYPE_MULTI && !empty($data['sku_detail_enabled']) ? 1 : 0;
        return $data;
    }

    protected function normalizeNullableAssetId(mixed $value): int|string|null
    {
        $normalized = app()->make(AssetIdNormalizer::class)->normalizeSingle($value);
        return $normalized === '' ? null : $normalized;
    }

    private function normalizeGoodsPointsRewardMode(string $mode): string
    {
        $mode = trim($mode);
        if ($mode === 'inherit' || $mode === '') {
            return 'global';
        }

        return in_array($mode, self::GOODS_POINTS_REWARD_MODES, true) ? $mode : 'global';
    }

    private function normalizeSkuPointsRewardMode(string $mode): string
    {
        return in_array($mode, self::SKU_POINTS_REWARD_MODES, true) ? $mode : 'inherit';
    }

    private function normalizeMemberBenefitMode(string $mode): string
    {
        return in_array($mode, self::MEMBER_BENEFIT_MODES, true) ? $mode : 'global';
    }

    private function normalizeGoodsDistributionCommissionMode(string $mode): string
    {
        return in_array($mode, self::GOODS_DISTRIBUTION_COMMISSION_MODES, true) ? $mode : 'global';
    }

    private function normalizeSkuDistributionCommissionMode(string $mode): string
    {
        return in_array($mode, self::SKU_DISTRIBUTION_COMMISSION_MODES, true) ? $mode : 'inherit';
    }

    private function normalizeNullablePrice(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return number_format(max(0, (float) $value), 2, '.', '');
    }

    private function normalizeCommissionRate(mixed $value): string
    {
        $value = trim((string) ($value ?? '0'));
        if ($value === '') {
            $value = '0';
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new BusinessException('分销佣金比例格式不合法');
        }
        $rate = (float) $value;
        if ($rate < 0 || $rate > 100) {
            throw new BusinessException('分销佣金比例必须在0到100之间');
        }
        return number_format($rate, 2, '.', '');
    }

    private function normalizeCommissionAmount(mixed $value): string
    {
        $value = trim((string) ($value ?? '0'));
        if ($value === '') {
            $value = '0';
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new BusinessException('分销固定佣金金额格式不合法');
        }
        return number_format(max(0, (float) $value), 2, '.', '');
    }

    private function assertCommissionRatePair(string $firstRate, string $secondRate): void
    {
        if (((float) $firstRate + (float) $secondRate) > 100) {
            throw new BusinessException('一级和二级分销佣金比例合计不能超过100%');
        }
    }

    private function hasDistributionCommissionPayload(array $data): bool
    {
        foreach (self::DISTRIBUTION_COMMISSION_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }

        foreach (($data['skus'] ?? []) as $sku) {
            if (!is_array($sku)) {
                continue;
            }
            foreach (self::DISTRIBUTION_COMMISSION_FIELDS as $field) {
                if (array_key_exists($field, $sku)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    private function skuIdsForGoods(int $goodsId): array
    {
        if ($goodsId <= 0) {
            return [];
        }

        return array_values(array_map(
            'intval',
            $this->model(GoodsSku::class)
                ->where('goods_id', $goodsId)
                ->column('id')
        ));
    }

    private function amountToCents(mixed $amount): int
    {
        $amount = $this->normalizeCommissionAmount($amount);
        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $yuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
    }

    private function centsToAmount(int $cents): string
    {
        return number_format(max(0, $cents) / 100, 2, '.', '');
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
            $data['main_image'] = $this->normalizeNullableAssetId($data['main_image']);
        }
        if (array_key_exists('main_video', $data)) {
            $data['main_video'] = $this->normalizeNullableAssetId($data['main_video']);
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
        foreach ((array) ($data['skus'] ?? []) as $sku) {
            if (!is_array($sku)) {
                continue;
            }
            foreach ($this->extractAssetIdsFromHtml((string) ($sku['description'] ?? '')) as $id) {
                $values[] = $id;
            }
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
        $skuDescriptionAssetIds = [];
        foreach ((array) ($data['skus'] ?? []) as $sku) {
            if (is_array($sku)) {
                $skuDescriptionAssetIds = array_merge(
                    $skuDescriptionAssetIds,
                    $this->extractAssetIdsFromHtml((string) ($sku['description'] ?? ''))
                );
            }
        }
        $assetService->syncUsage('goods', $goodsId, 'skus.description', $skuDescriptionAssetIds);
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
