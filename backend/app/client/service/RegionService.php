<?php
declare(strict_types=1);

namespace app\client\service;

use app\model\region\Region;
use app\service\RegionResolverService;
use mall_base\base\BaseService;

/**
 * @extends BaseService<Region>
 */
class RegionService extends BaseService
{
    protected string $modelClass = Region::class;

    public function getChildren(int $parentId): array
    {
        return app()->make(RegionResolverService::class)->getChildren($parentId);
    }

    public function getPath(int $id): array
    {
        return app()->make(RegionResolverService::class)->getPath($id);
    }
}
