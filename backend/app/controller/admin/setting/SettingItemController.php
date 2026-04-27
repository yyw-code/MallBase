<?php

declare (strict_types=1);

namespace app\controller\admin\setting;

use app\service\admin\setting\SettingService;
use mall_base\base\BaseController;

/**
 * 设置项控制器（对应前端设置项管理页面 + 前端配置读取/保存）
 * @extends BaseController<SettingService>
 */
class SettingItemController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = SettingService::class;

    // ==================== 表单配置 ====================

    /**
     * 获取表单配置（表单类型选项 + 按 type 索引的验证规则类型）
     * 前端动态渲染设置项表单时使用
     */
    public function formConfig()
    {
        $config = $this->service()->getFormConfig();
        return $this->success($config, '获取成功');
    }

    // ==================== 设置项管理 ====================

    /**
     * 设置项列表
     * group_id 为 0 或不传时返回所有设置项，支持关键词（name/code）和表单类型搜索
     */
    public function list()
    {
        $where = $this->request->param(['group_id', 'keyword', 'type']);
        [$page, $pageSize] = $this->getPagination();

        $result = $this->service()->getSettingList($where, $page, $pageSize);
        return $this->success($result, '获取成功');
    }

    /**
     * 创建设置项
     */
    public function create()
    {
        $data = $this->request->param(['group_id', 'name', 'code', 'value', 'type', 'options', 'rules', 'placeholder', 'remark', 'sort']);

        $this->validate($data, 'admin/setting/SettingItem.create');

        $result = $this->service()->createSetting($data);
        return $this->success($result, '创建成功');
    }

    /**
     * 更新设置项
     */
    public function update($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['group_id', 'name', 'code', 'value', 'type', 'options', 'rules', 'placeholder', 'remark', 'sort']);

        $this->validate($data, 'admin/setting/SettingItem.update');

        $result = $this->service()->updateSetting((int)$id, $data);
        return $this->success($result, '更新成功');
    }

    /**
     * 删除设置项
     */
    public function delete($id)
    {
        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $this->service()->deleteSetting((int)$id);
        return $this->success(null, '删除成功');
    }

    // ==================== 配置读取/保存（前端使用） ====================

    /**
     * 获取分组配置（前端渲染表单用）
     * GET /setting/item/config/:groupCode
     */
    public function getConfig($groupCode)
    {
        if (empty($groupCode)) {
            return $this->error('分组编码不能为空');
        }

        $config = $this->service()->getGroupConfig($groupCode);
        return $this->success($config, '获取成功');
    }

    /**
     * 保存分组配置（前端提交表单）
     * 先根据设置项的 rules 验证，通过后再保存
     * 支持 page 和 tab 两种模式
     * POST /setting/item/saveConfig/:groupCode
     */
    public function saveConfig($groupCode)
    {
        if (empty($groupCode)) {
            return $this->error('分组编码不能为空');
        }

        // 获取所有提交的值（排除 groupCode 路由参数）
        $values = $this->request->except(['groupCode'], 'param');

        if (empty($values)) {
            return $this->error('没有需要保存的配置');
        }

        $this->service()->saveGroupValuesWithValidation($groupCode, $values);
        return $this->success(null, '保存成功');
    }
}
