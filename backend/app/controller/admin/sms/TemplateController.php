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
        $data = $this->request->param(['provider_id', 'template_name', 'template_type', 'template_content', 'remark']);
        $this->validate($data, SmsTemplateValidate::class . '.create');
        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建已提交,等待审核');
    }

    public function update($id)
    {
        $data = $this->request->param(['provider_id', 'template_name', 'template_type', 'template_content', 'remark']);
        $this->validate($data, SmsTemplateValidate::class . '.update');
        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功,等待重新审核');
    }

    public function delete($id)
    {
        $this->service()->delete((int) $id);
        return $this->success(null, '删除成功');
    }

    public function syncStatus($id)
    {
        $info = $this->service()->syncStatus((int) $id);
        return $this->success($info, '同步成功');
    }

    public function syncAll()
    {
        $providerId = (int) $this->request->param('provider_id', 0);
        if ($providerId <= 0) {
            return $this->error('服务商ID必填');
        }
        $stat = $this->service()->syncAll($providerId);
        return $this->success($stat, "同步完成: 成功 {$stat['success']} 个,失败 {$stat['failed']} 个");
    }
}
