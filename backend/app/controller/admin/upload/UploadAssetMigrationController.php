<?php
declare(strict_types=1);

namespace app\controller\admin\upload;

use app\service\admin\upload\UploadAssetMigrationAdminService;
use mall_base\base\BaseController;

/**
 * 后台素材迁移任务控制器。
 *
 * @extends BaseController<UploadAssetMigrationAdminService>
 */
class UploadAssetMigrationController extends BaseController
{
    protected string $serviceClass = UploadAssetMigrationAdminService::class;

    public function list()
    {
        $where = $this->request->param(['status', 'source_driver', 'target_driver']);
        [$page, $limit] = $this->getPagination(1, 20);

        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['name', 'source_driver', 'target_driver', 'options']);
        $id = $this->service()->create($data);

        return $this->success(['id' => $id], '任务已创建');
    }

    public function retry()
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->retry($id);
        return $this->success(null, '已重新入队');
    }

    public function cleanup()
    {
        $keepDays = (int) $this->request->param('keep_days', 30);
        $count = $this->service()->cleanupDone($keepDays);

        return $this->success(['count' => $count], '清理成功');
    }
}
