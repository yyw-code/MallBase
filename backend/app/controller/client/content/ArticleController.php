<?php
declare(strict_types=1);

namespace app\controller\client\content;

use app\service\client\content\ClientArticleService;
use mall_base\base\BaseController;

/**
 * C端文章控制器
 * @extends BaseController<ClientArticleService>
 */
class ArticleController extends BaseController
{
    protected string $serviceClass = ClientArticleService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'category_id']);
        [$page, $limit] = $this->getPagination(1, 10);

        return $this->success($this->service()->list($where, $page, $limit), '获取成功');
    }

    public function info()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('文章 ID 不能为空');
        }

        $userId = (int) ($this->request->user_id ?? 0);
        return $this->success($this->service()->detail($id, $userId), '获取成功');
    }
}
