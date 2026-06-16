<?php
declare(strict_types=1);

namespace app\controller\admin\logistics;

use app\service\admin\logistics\LogisticsPlatformService;
use mall_base\base\BaseController;

/**
 * 物流平台配置控制器
 *
 * @extends BaseController<LogisticsPlatformService>
 */
class PlatformController extends BaseController
{
    protected string $serviceClass = LogisticsPlatformService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'driver', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);

        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function save()
    {
        $data = $this->request->param([
            'id', 'code', 'name', 'driver', 'status', 'is_default', 'cache_minutes', 'config', 'sort',
        ]);
        $id = $this->service()->savePlatform($data);

        return $this->success(['id' => $id], '保存成功');
    }

    public function clearCache()
    {
        $ids = $this->request->param('ids', []);
        $count = $this->service()->clearCache(is_array($ids) ? $ids : []);

        return $this->success(['count' => $count], '清理成功');
    }
}
