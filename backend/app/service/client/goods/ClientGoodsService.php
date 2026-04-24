<?php

declare(strict_types=1);

namespace app\service\client\goods;

use app\model\goods\Goods;
use app\model\goods\GoodsImage;
use app\model\goods\GoodsSku;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 客户端(C 端)商品服务
 *
 * 仅做读,不写;读取必须带"上架 + 启用 + 未删除"三重过滤,避免泄漏后台未上架商品。
 *
 * @extends BaseService<Goods>
 */
final class ClientGoodsService extends BaseService
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

        // 商品图片(轮播图)
        $data['images'] = $this->model(GoodsImage::class)
            ->where('goods_id', $goodsId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // SKU 列表(只暴露上架的 SKU)
        $data['skus'] = $this->model(GoodsSku::class)
            ->where('goods_id', $goodsId)
            ->where('status', 1)
            ->select()
            ->toArray();

        return $data;
    }

    /**
     * 推荐商品列表(首页/购物车空态等场景使用)
     *
     * @return array<int, array<string, mixed>>
     */
    public function recommend(int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));
        return $this->saleableQuery()
            ->where('is_recommend', 1)
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
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
}
