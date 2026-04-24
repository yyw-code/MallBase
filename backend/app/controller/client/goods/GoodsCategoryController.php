<?php

declare(strict_types=1);

namespace app\controller\client\goods;

use app\service\client\goods\ClientGoodsCategoryService;
use mall_base\base\BaseController;

/**
 * 客户端商品分类控制器
 *
 * @extends BaseController<ClientGoodsCategoryService>
 */
class GoodsCategoryController extends BaseController
{
    protected string $serviceClass = ClientGoodsCategoryService::class;

    /**
     * 分类树(主接口,前端首页/筛选页用)
     */
    public function tree()
    {
        $tree = $this->service()->tree();
        return $this->success(['list' => $tree], '获取成功');
    }

    /**
     * 扁平分类列表(返回所有启用项,适合下拉选择器场景)
     */
    public function list()
    {
        $list = $this->service()->flatList();
        return $this->success(['list' => $list], '获取成功');
    }
}
