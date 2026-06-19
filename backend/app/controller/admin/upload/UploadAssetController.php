<?php
declare(strict_types=1);

namespace app\controller\admin\upload;

use app\service\admin\upload\UploadAssetAdminService;
use mall_base\base\BaseController;

/**
 * 后台素材控制器。
 *
 * @extends BaseController<UploadAssetAdminService>
 */
class UploadAssetController extends BaseController
{
    protected string $serviceClass = UploadAssetAdminService::class;

    public function list()
    {
        $where = $this->request->param([
            'keyword', 'category_id', 'type', 'ext', 'driver', 'module', 'uploader_type', 'uploader_id', 'status',
        ]);
        [$page, $limit] = $this->getPagination(1, 20);

        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function select()
    {
        $where = $this->request->param(['keyword', 'category_id', 'type', 'ext', 'driver', 'module']);
        [$page, $limit] = $this->getPagination(1, 20);

        return $this->success($this->service()->select($where, $page, $limit), '获取成功');
    }

    public function info()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        return $this->success($this->service()->getInfo($id), '获取成功');
    }

    public function update()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['name', 'category_id', 'visibility', 'status', 'meta']);
        $this->service()->update($id, $data);

        return $this->success(null, '更新成功');
    }

    public function move()
    {
        $id = (int) $this->request->param('id', 0);
        $categoryId = (int) $this->request->param('category_id', 0);
        if ($id <= 0 || $categoryId <= 0) {
            return $this->error('参数不能为空');
        }

        $this->service()->move($id, $categoryId);
        return $this->success(null, '移动成功');
    }

    public function delete()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete($id);
        return $this->success(null, '已移入回收站');
    }

    public function restore()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->restore($id);
        return $this->success(null, '恢复成功');
    }

    public function purge()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->purge($id);
        return $this->success(null, '永久删除成功');
    }

    public function clearRecycle()
    {
        $count = $this->service()->clearRecycleBin();
        return $this->success(['count' => $count], '清空成功');
    }

    public function usage()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        return $this->success($this->service()->getUsage($id), '获取成功');
    }
}
