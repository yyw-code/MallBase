<?php

declare(strict_types=1);

namespace app\service\client\goods;

use app\model\goods\GoodsComment;
use mall_base\base\BaseService;

/**
 * 客户端商品评论服务
 *
 * @extends BaseService<GoodsComment>
 */
class ClientGoodsCommentService extends BaseService
{
    protected string $modelClass = GoodsComment::class;

    /**
     * 商品评论列表
     *
     * @return array{total:int, list:array<int, array<string, mixed>>}
     */
    public function listByGoods(int $goodsId, int $page = 1, int $limit = 10): array
    {
        $where = ['goods_id' => $goodsId, 'status' => 1];
        $total = $this->buildListQuery($where)->count();
        $list = $this->buildListQuery($where)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $list = array_map([$this, 'normalizeComment'], $list);

        return compact('total', 'list');
    }

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->where('goods_id', (int) $where['goods_id'])
            ->where('status', (int) $where['status'])
            ->whereNull('delete_time');
    }

    /**
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    private function normalizeComment(array $comment): array
    {
        $imagePaths = $this->normalizeImages($comment['images'] ?? null);
        $comment['images'] = $imagePaths;
        $comment['images_full_urls'] = buildUploadUrls($imagePaths);
        $comment['user_nickname'] = (int) ($comment['is_anonymous'] ?? 0) === 1 ? '匿名用户' : '用户';
        $comment['user_avatar_full_url'] = '';

        return $comment;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeImages(mixed $images): array
    {
        if (is_array($images)) {
            return array_values(array_filter($images, static fn($image) => is_string($image) && $image !== ''));
        }

        if (!is_string($images) || trim($images) === '') {
            return [];
        }

        $decoded = json_decode($images, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, static fn($image) => is_string($image) && $image !== ''));
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $images)),
            static fn($image) => $image !== ''
        ));
    }
}
