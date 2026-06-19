<?php

declare(strict_types=1);

namespace app\service\client\goods;

use app\common\enum\OrderStatus;
use app\model\goods\GoodsComment;
use app\model\order\OrderItem;
use app\service\upload\AssetHydrator;
use app\service\upload\AssetIdNormalizer;
use app\service\upload\AssetService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 客户端商品评论服务
 *
 * @extends BaseService<GoodsComment>
 */
class ClientGoodsCommentService extends BaseService
{
    private const MAX_REVIEW_IMAGES = 6;
    private const REVIEWABLE_ORDER_STATUSES = [
        OrderStatus::RECEIVED,
        OrderStatus::COMPLETED,
    ];

    protected string $modelClass = GoodsComment::class;

    /**
     * 发布商品评价。
     *
     * @param array{
     *   order_item_id?: int,
     *   order_id?: int,
     *   goods_id?: int,
     *   sku_id?: int,
     *   rating?: int,
     *   content?: string,
     *   images?: array<int, mixed>|string,
     *   is_anonymous?: int
     * } $data
     */
    public function create(int $userId, array $data): int
    {
        $this->assertUserId($userId);

        $orderItemId = (int) ($data['order_item_id'] ?? 0);
        if ($orderItemId <= 0) {
            throw new BusinessException('请选择要评价的商品');
        }

        $orderItem = $this->findReviewableOrderItem($userId, $orderItemId);
        $this->assertNotReviewed($orderItemId);

        $content = mb_substr(trim((string) ($data['content'] ?? '')), 0, 500);
        $rating = max(1, min(5, (int) ($data['rating'] ?? 5)));
        $images = $this->normalizeReviewImages($data['images'] ?? []);
        $isAnonymous = (int) ($data['is_anonymous'] ?? 0) === 1 ? 1 : 0;

        return (int) $this->transaction(function () use ($userId, $orderItem, $rating, $content, $images, $isAnonymous) {
            $comment = $this->model();
            $comment->save([
                'goods_id' => (int) $orderItem['goods_id'],
                'user_id' => $userId,
                'order_id' => (int) $orderItem['order_id'],
                'order_item_id' => (int) $orderItem['id'],
                'sku_id' => (int) $orderItem['sku_id'],
                'sku_spec' => mb_substr((string) ($orderItem['sku_spec'] ?? ''), 0, 500),
                'content' => $content,
                'images' => $images,
                'rating' => $rating,
                'is_anonymous' => $isAnonymous,
                'status' => 1,
            ]);

            app()->make(AssetService::class)->syncUsage('goods_comment', (int) $comment->id, 'images', $images);

            return (int) $comment->id;
        });
    }

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
            ->order('c.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $list = array_map([$this, 'normalizeComment'], $list);
        $list = app()->make(AssetHydrator::class)->hydrateComments($list);
        foreach ($list as &$comment) {
            unset($comment['user_nickname_raw'], $comment['user_avatar_raw']);
        }
        unset($comment);

        return compact('total', 'list');
    }

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->alias('c')
            ->leftJoin('mb_user u', 'u.id = c.user_id')
            ->where('c.goods_id', (int) $where['goods_id'])
            ->where('c.status', (int) $where['status'])
            ->whereNull('c.delete_time')
            ->field([
                'c.*',
                'u.nickname' => 'user_nickname_raw',
                'u.avatar'   => 'user_avatar_raw',
            ]);
    }

    /**
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    private function normalizeComment(array $comment): array
    {
        $comment['images'] = app()->make(AssetIdNormalizer::class)->normalizeMany($comment['images'] ?? null);
        $comment['append_images'] = app()->make(AssetIdNormalizer::class)->normalizeMany($comment['append_images'] ?? null);

        $comment['sku_spec_text'] = (string) ($comment['sku_spec'] ?? '');

        $isAnonymous = (int) ($comment['is_anonymous'] ?? 0) === 1;
        $nicknameRaw = trim((string) ($comment['user_nickname_raw'] ?? ''));

        $comment['user_nickname'] = $isAnonymous
            ? '匿名用户'
            : ($nicknameRaw !== '' ? $nicknameRaw : '用户');
        if ($isAnonymous) {
            $comment['user_avatar_raw'] = '';
            $comment['user_avatar_full_url'] = '';
        }

        return $comment;
    }

    private function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new BusinessException('未登录');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findReviewableOrderItem(int $userId, int $orderItemId): array
    {
        $row = $this->model(OrderItem::class)
            ->alias('oi')
            ->leftJoin('mb_order o', 'o.id = oi.order_id')
            ->where('oi.id', $orderItemId)
            ->where('o.user_id', $userId)
            ->whereNull('o.delete_time')
            ->field([
                'oi.*',
                'o.status' => 'order_status',
            ])
            ->find();

        if ($row === null) {
            throw new BusinessException('订单商品不存在');
        }

        $data = $row->toArray();
        if (!in_array((int) ($data['order_status'] ?? -1), self::REVIEWABLE_ORDER_STATUSES, true)) {
            throw new BusinessException('订单未完成收货，暂不能评价');
        }

        return $data;
    }

    private function assertNotReviewed(int $orderItemId): void
    {
        $exists = $this->model()
            ->where('order_item_id', $orderItemId)
            ->whereNull('delete_time')
            ->find();
        if ($exists !== null) {
            throw new BusinessException('该商品已评价');
        }
    }

    /**
     * @return array<int, int|string>
     */
    private function normalizeReviewImages(mixed $images): array
    {
        $items = (new AssetIdNormalizer())->normalizeMany($images);
        if (count($items) > self::MAX_REVIEW_IMAGES) {
            throw new BusinessException('评价图片最多上传 6 张');
        }

        $assetIds = [];
        foreach ($items as $image) {
            if (is_int($image)) {
                $assetIds[] = $image;
                continue;
            }
            $this->assertUploadedImagePath((string) $image);
        }
        if ($assetIds !== []) {
            app()->make(AssetService::class)->assertUsableImageAssets($assetIds);
        }

        return $items;
    }

    private function assertUploadedImagePath(string $image): void
    {
        $image = trim($image);
        if ($image === '') {
            return;
        }

        if (preg_match('#^(data|blob|file|wxfile):#i', $image)) {
            throw new BusinessException('评价图片请先上传后再提交');
        }

        if (str_starts_with($image, '_doc/') || str_starts_with($image, '_downloads/')) {
            throw new BusinessException('评价图片请先上传后再提交');
        }

        $scheme = (string) (parse_url($image, PHP_URL_SCHEME) ?: '');
        if ($scheme !== '' && !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new BusinessException('评价图片地址格式不正确');
        }

        if ($scheme === '' && !str_starts_with($image, '/uploads/') && !str_starts_with($image, 'uploads/')
            && !str_starts_with($image, '/static/') && !str_starts_with($image, 'static/')) {
            throw new BusinessException('评价图片请先上传后再提交');
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeImages(mixed $images): array
    {
        if (is_array($images)) {
            return array_values(array_filter(
                array_map(static fn($image): string => is_string($image) ? trim($image) : '', $images),
                static fn(string $image): bool => $image !== ''
            ));
        }

        if (!is_string($images) || trim($images) === '') {
            return [];
        }

        $decoded = json_decode($images, true);
        if (is_array($decoded)) {
            return array_values(array_filter(
                array_map(static fn($image): string => is_string($image) ? trim($image) : '', $decoded),
                static fn(string $image): bool => $image !== ''
            ));
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $images)),
            static fn($image) => $image !== ''
        ));
    }
}
