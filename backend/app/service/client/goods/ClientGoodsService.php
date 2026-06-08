<?php

declare(strict_types=1);

namespace app\service\client\goods;

use app\model\goods\Goods;
use app\model\goods\GoodsSku;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 客户端(C 端)商品服务
 *
 * 仅做读,不写;读取必须带"上架 + 启用 + 未删除"三重过滤,避免泄漏后台未上架商品。
 *
 * @extends BaseService<Goods>
 */
class ClientGoodsService extends BaseService
{
    protected string $modelClass = Goods::class;

    /**
     * 商品列表(分页 + 多维筛选)
     *
     * @param array{
     *     keyword?: string,
     *     category_id?: int|null,
     *     brand_id?: int|null,
     *     is_recommend?: int|null,
     *     is_new?: int|null,
     *     is_hot?: int|null,
     *     sort_by?: string,
     * } $filter
     * @return array{total:int, list:array<int, array<string, mixed>>}
     */
    public function list(array $filter = [], int $page = 1, int $pageSize = 20): array
    {
        $query = $this->saleableQuery();

        if (!empty($filter['keyword'])) {
            $query->where('name', 'like', '%' . trim((string) $filter['keyword']) . '%');
        }
        if (!empty($filter['category_id'])) {
            $query->where('category_id', (int) $filter['category_id']);
        }
        if (!empty($filter['brand_id'])) {
            $query->where('brand_id', (int) $filter['brand_id']);
        }
        foreach (['is_recommend', 'is_new', 'is_hot'] as $flag) {
            if (isset($filter[$flag]) && (int) $filter[$flag] === 1) {
                $query->where($flag, 1);
            }
        }

        $sortBy = (string) ($filter['sort_by'] ?? 'default');
        $query = $this->applySort($query, $sortBy);

        $total = (clone $query)->count();
        $list = $query
            ->page($page, $pageSize)
            ->select()
            ->toArray();
        $list = app()->make(AssetHydrator::class)->hydrateGoodsList($list);

        return compact('total', 'list');
    }

    /**
     * 商品详情(含图片、SKU、规格)
     *
     * @return array<string, mixed>
     */
    public function detail(int $goodsId): array
    {
        $goods = $this->saleableQuery()->where('id', $goodsId)->find();
        if ($goods === null) {
            throw new BusinessException('商品不存在或已下架');
        }

        $data = $goods->toArray();
        // SKU 列表(只暴露上架的 SKU)
        $data['skus'] = $this->model(GoodsSku::class)
            ->where('goods_id', $goodsId)
            ->where('status', 1)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $data['guarantees'] = $this->goodsGuarantees();

        return app()->make(AssetHydrator::class)->hydrateGoodsDetail($data);
    }

    /**
     * 推荐商品列表(首页/购物车空态等场景使用)
     *
     * @return array<int, array<string, mixed>>
     */
    public function recommend(int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));
        $list = $this->saleableQuery()
            ->where('is_recommend', 1)
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return app()->make(AssetHydrator::class)->hydrateGoodsList($list);
    }

    /**
     * 共享的"上架可售"过滤
     */
    private function saleableQuery()
    {
        return $this->model()
            ->where('status', 1)
            ->where('is_on_sale', 1)
            ->whereNull('delete_time');
    }

    private function applySort($query, string $sortBy)
    {
        return match ($sortBy) {
            'sales_desc' => $query->order('sales', 'desc')->order('id', 'desc'),
            'price_asc'  => $query->order('price', 'asc')->order('id', 'desc'),
            'price_desc' => $query->order('price', 'desc')->order('id', 'desc'),
            'newest'     => $query->order('id', 'desc'),
            default      => $query->order('sort', 'asc')->order('id', 'desc'),
        };
    }

    /**
     * 商品详情页保障说明。
     *
     * @return array<int, array{title:string, desc:string, icon:string}>
     */
    private function goodsGuarantees(): array
    {
        $default = [
            ['title' => '正品保障', 'desc' => '平台严选商品来源', 'icon' => 'shield'],
            ['title' => '极速发货', 'desc' => '现货商品优先出库', 'icon' => 'truck'],
            ['title' => '七天无理由', 'desc' => '符合条件可无理由退货', 'icon' => 'refresh'],
            ['title' => '售后无忧', 'desc' => '订单售后进度可追踪', 'icon' => 'service'],
        ];

        $raw = getSystemSetting('client_goods_guarantees');
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $default;
        }

        $items = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $items[] = [
                'title' => mb_substr($title, 0, 20),
                'desc' => mb_substr(trim((string) ($item['desc'] ?? '')), 0, 60),
                'icon' => mb_substr(trim((string) ($item['icon'] ?? 'shield')), 0, 30),
            ];
        }

        return $items !== [] ? $items : $default;
    }
}
