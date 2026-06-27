<?php
declare(strict_types=1);

namespace app\service\client\content;

use app\model\content\ArticleCategory;
use mall_base\base\BaseService;

/**
 * C端文章分类服务
 * @extends BaseService<ArticleCategory>
 */
class ClientArticleCategoryService extends BaseService
{
    protected string $modelClass = ArticleCategory::class;

    public function list(): array
    {
        return $this->model()
            ->where('status', 1)
            ->whereNull('delete_time')
            ->field('id,name,description,sort')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }
}
