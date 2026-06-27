<?php
declare(strict_types=1);

namespace app\controller\admin\content;

use app\service\admin\content\ArticleCategoryService;
use app\validate\admin\content\ArticleCategoryValidate;
use mall_base\base\BaseController;

/**
 * 文章分类控制器
 * @extends BaseController<ArticleCategoryService>
 */
class ArticleCategoryController extends BaseController
{
    protected string $serviceClass = ArticleCategoryService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);

        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function all()
    {
        return $this->success($this->service()->getAllCategories(), '获取成功');
    }

    public function info()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        return $this->success($this->service()->getInfo($id), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['name', 'description', 'sort', 'status']);
        $this->validate($data, ArticleCategoryValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    public function update()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['name', 'description', 'sort', 'status']);
        $this->validate($data, ArticleCategoryValidate::class . '.update');

        $this->service()->update($id, $data);
        return $this->success(null, '更新成功');
    }

    public function delete()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete($id);
        return $this->success(null, '删除成功');
    }

    public function updateStatus()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $status = $this->request->param('status', null);
        if ($status === null || $status === '') {
            return $this->error('状态不能为空');
        }

        $this->service()->updateStatus($id, (int) $status);
        return $this->success(null, '更新成功');
    }
}
