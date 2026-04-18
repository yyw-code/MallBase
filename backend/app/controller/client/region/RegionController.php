<?php
declare(strict_types=1);

namespace app\controller\client\region;

use app\service\client\RegionService;
use mall_base\base\BaseController;

/**
 * @extends BaseController<RegionService>
 */
class RegionController extends BaseController
{
    protected string $serviceClass = RegionService::class;

    public function children()
    {
        $parentId = (int) $this->request->param('parent_id', 0);
        return $this->success($this->service()->getChildren($parentId), '获取成功');
    }

    public function path($id)
    {
        return $this->success($this->service()->getPath((int) $id), '获取成功');
    }
}
