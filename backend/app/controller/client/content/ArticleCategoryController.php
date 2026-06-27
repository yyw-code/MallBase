<?php
declare(strict_types=1);

namespace app\controller\client\content;

use app\service\client\content\ClientArticleCategoryService;
use mall_base\base\BaseController;

/**
 * C端文章分类控制器
 * @extends BaseController<ClientArticleCategoryService>
 */
class ArticleCategoryController extends BaseController
{
    protected string $serviceClass = ClientArticleCategoryService::class;

    public function list()
    {
        return $this->success($this->service()->list(), '获取成功');
    }
}
