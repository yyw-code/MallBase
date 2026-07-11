<?php

declare (strict_types=1);

namespace app\controller\admin\setting;

use app\service\admin\setting\SettingService;
use mall_base\base\BaseController;

/**
 * 设置页内分组控制器
 *
 * @extends BaseController<SettingService>
 */
class SectionController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = SettingService::class;

    /**
     * 分组下的页内分组列表
     */
    public function list($groupId)
    {
        if (empty($groupId)) {
            return $this->error('分组ID不能为空');
        }

        $list = $this->service()->getSectionList((int)$groupId);
        return $this->success($list, '获取成功');
    }

    /**
     * 创建页内分组
     */
    public function create()
    {
        $data = $this->request->param(['group_id', 'name', 'code', 'sort']);

        $this->validate($data, 'admin/setting/SettingSection.create');

        $id = $this->service()->createSection($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新页内分组
     */
    public function update($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['name', 'code', 'sort']);

        $this->validate($data, 'admin/setting/SettingSection.update');

        $this->service()->updateSection((int)$id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除页内分组
     */
    public function delete($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $this->service()->deleteSection((int)$id);
        return $this->success(null, '删除成功');
    }
}
