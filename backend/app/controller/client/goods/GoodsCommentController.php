<?php

declare(strict_types=1);

namespace app\controller\client\goods;

use app\service\client\goods\ClientGoodsCommentService;
use app\validate\client\goods\GoodsCommentValidate;
use mall_base\base\BaseController;

/**
 * 客户端商品评论控制器
 *
 * @extends BaseController<ClientGoodsCommentService>
 */
class GoodsCommentController extends BaseController
{
    protected string $serviceClass = ClientGoodsCommentService::class;

    /**
     * 商品评论列表
     */
    public function list()
    {
        $goodsId = (int) $this->request->param('goods_id', 0);
        if ($goodsId <= 0) {
            return $this->error('商品 ID 不能为空');
        }

        [$page, $limit] = $this->getPagination(1, 10);
        $result = $this->service()->listByGoods($goodsId, $page, $limit);

        return $this->success($result, '获取成功');
    }

    /**
     * 发布商品评价
     */
    public function create()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $data = $this->request->param([
            'order_item_id',
            'rating',
            'content',
            'images',
            'is_anonymous',
        ]);
        $this->validate($data, GoodsCommentValidate::class . '.create');

        $id = $this->service()->create($userId, $data);

        return $this->success(['id' => $id], '评价发布成功');
    }
}
