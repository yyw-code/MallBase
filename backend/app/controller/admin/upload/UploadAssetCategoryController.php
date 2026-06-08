<?php
declare(strict_types=1);

namespace app\controller\admin\upload;

use app\service\admin\upload\UploadAssetCategoryAdminService;
use mall_base\base\BaseController;

/**
 * 后台素材分类控制器。
 *
 * @extends BaseController<UploadAssetCategoryAdminService>
 */
class UploadAssetCategoryController extends BaseController
{
    protected string $serviceClass = UploadAssetCategoryAdminService::class;

    public function list()
    {
        $where = $this->request->param(['name', 'pid', 'status']);
        [$page, $limit] = $this->getPagination(1, 100);

        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function tree()
    {
        $where = $this->request->param(['name', 'status']);
        return $this->success($this->service()->tree($where), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['pid', 'name', 'code', 'sort', 'status']);
        if (trim((string) ($data['name'] ?? '')) === '') {
            return $this->error('分类名称不能为空');
        }

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    public function update()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['pid', 'name', 'code', 'sort', 'status']);
        $this->service()->update($id, $data);

        return $this->success(null, '更新成功');
    }

    public function delete()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete($id);
        return $this->success(null, '删除成功');
    }
}
