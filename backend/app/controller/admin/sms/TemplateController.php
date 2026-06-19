<?php

declare(strict_types=1);

namespace app\controller\admin\sms;

use app\service\admin\sms\SmsTemplateService;
use app\validate\admin\sms\SmsTemplateValidate;
use mall_base\base\BaseController;

/**
 * 短信模板控制器
 *
 * @extends BaseController<SmsTemplateService>
 */
class TemplateController extends BaseController
{
    protected string $serviceClass = SmsTemplateService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'provider_id', 'audit_status']);
        [$page, $limit] = $this->getPagination(1, 15);
        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    public function info($id)
    {
        $info = $this->service()->getInfo((int) $id);
        return $this->success($info, '获取成功');
    }

    public function create()
    {
        $data = $this->request->param([
            'provider_id', 'sign_id', 'template_name', 'template_type', 'template_content',
            'template_code', 'submit_to_platform', 'remark',
        ]);
        $this->validate($data, SmsTemplateValidate::class . '.create');
        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '保存成功');
    }

    public function update($id)
    {
        $data = $this->request->param([
            'provider_id', 'sign_id', 'template_name', 'template_type', 'template_content',
            'template_code', 'submit_to_platform', 'remark',
        ]);
        $this->validate($data, SmsTemplateValidate::class . '.update');
        $this->service()->update((int) $id, $data);
        return $this->success(null, '保存成功');
    }

    public function delete($id)
    {
        $this->service()->delete((int) $id);
        return $this->success(null, '删除成功');
    }

    public function syncStatus($id)
    {
        $info = $this->service()->syncStatus((int) $id);
        return $this->success($info, '已加入后台同步队列,稍后刷新查看');
    }

    public function syncAll()
    {
        $providerId = (int) $this->request->param('provider_id', 0);
        if ($providerId <= 0) {
            return $this->error('服务商ID必填');
        }
        $stat = $this->service()->syncAll($providerId);
        return $this->success($stat, "已派发 {$stat['dispatched']} 条同步任务");
    }

    /**
     * 按勾选的 id 数组批量同步模板状态
     * body: { ids: [1, 2, 3] }
     */
    public function syncBatch()
    {
        $ids = (array) $this->request->param('ids', []);
        if (empty($ids)) {
            return $this->error('请至少选择一条模板');
        }
        $stat = $this->service()->syncBatch($ids);
        return $this->success($stat, "已派发 {$stat['dispatched']} 条同步任务");
    }

    /**
     * 按内置场景批量创建模板(仅支持普通阿里云驱动)
     * body: { provider_id: 1, sign_id: 2, submit_to_platform: 0/1, items: [{ scene_code, template_name, template_content, template_type, template_code }] }
     */
    public function createByScenes()
    {
        $providerId = (int) $this->request->param('provider_id', 0);
        $signId = (int) $this->request->param('sign_id', 0);
        $submitToPlatform = $this->request->param('submit_to_platform', 1);
        $items = (array) $this->request->param('items', []);
        if ($providerId <= 0) {
            return $this->error('服务商必填');
        }
        if (empty($items)) {
            return $this->error('请至少选择一个场景');
        }
        $stat = $this->service()->createByScenes(
            $providerId,
            $signId,
            $items,
            in_array($submitToPlatform, [true, 1, '1', 'true', 'on'], true)
        );
        return $this->success($stat, "已创建 {$stat['created']} 个模板;失败 {$stat['failed']} 个");
    }
}
