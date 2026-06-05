<?php

declare(strict_types=1);

namespace app\controller\client\goods;

use app\service\client\goods\ClientGoodsService;
use mall_base\base\BaseController;

/**
 * 客户端商品控制器
 *
 * 仅做读,无任何鉴权要求(浏览商品的匿名访问能力是 C 端基本面)。
 * 用户身份相关动作(收藏、足迹)请放到带 JwtAuth 中间件的另一组路由。
 *
 * @extends BaseController<ClientGoodsService>
 */
class GoodsController extends BaseController
{
    protected string $serviceClass = ClientGoodsService::class;

    /**
     * 商品列表
     *
     * 支持参数:keyword、category_id、brand_id、ids、tag_id/tag_ids、is_recommend/is_new/is_hot、sort_by
     */
    public function list()
    {
        $filter = $this->request->param([
            'keyword', 'category_id', 'brand_id',
            'ids', 'tag_id', 'tag_ids',
            'is_recommend', 'is_new', 'is_hot', 'sort_by',
        ]);

        [$page, $limit] = $this->getPagination(1, 20);
        $result = $this->service()->list($filter, $page, $limit);
        return $this->success($result, '获取成功');
    }

    /**
     * 商品详情
     */
    public function info($id)
    {
        if (empty($id)) {
            return $this->error('商品 ID 不能为空');
        }

        $info = $this->service()->detail((int) $id);
        return $this->success($info, '获取成功');
    }

    /**
     * 推荐商品(首页/购物车空态/详情页底部"为你推荐")
     */
    public function recommend()
    {
        $limit = (int) $this->request->param('limit', 10);
        $list = $this->service()->recommend($limit);
        return $this->success(['list' => $list], '获取成功');
    }
}
