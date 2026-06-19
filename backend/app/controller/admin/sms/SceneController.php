<?php

declare(strict_types=1);

namespace app\controller\admin\sms;

use app\service\admin\sms\SmsSceneService;
use app\validate\admin\sms\SmsSceneValidate;
use mall_base\base\BaseController;

/**
 * 短信场景绑定控制器
 *
 * @extends BaseController<SmsSceneService>
 */
class SceneController extends BaseController
{
    protected string $serviceClass = SmsSceneService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'provider_id', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    public function bind()
    {
        $data = $this->request->param([
            'scene_code', 'provider_id', 'template_id', 'sign_id', 'status',
            'draft_template_name', 'draft_template_content', 'draft_template_type', 'draft_template_remark',
        ]);

        if (empty($data['provider_id']) && empty($data['template_id']) && empty($data['sign_id'])) {
            $this->validate($data, SmsSceneValidate::class . '.saveDraft');
            $this->service()->saveDraft($data);
            return $this->success(null, '保存成功');
        }

        $this->validate($data, SmsSceneValidate::class . '.bind');
        $this->service()->bind($data);
        return $this->success(null, '绑定成功');
    }

    public function saveDraft()
    {
        $data = $this->request->param([
            'scene_code', 'draft_template_name', 'draft_template_content', 'draft_template_type', 'draft_template_remark',
        ]);
        $this->validate($data, SmsSceneValidate::class . '.saveDraft');
        $this->service()->saveDraft($data);
        return $this->success(null, '保存成功');
    }

    public function createTemplateAndBind()
    {
        $data = $this->request->param([
            'scene_code', 'provider_id', 'sign_id', 'status',
            'draft_template_name', 'draft_template_content', 'draft_template_type', 'draft_template_remark',
            'submit_to_platform', 'template_code',
        ]);
        $this->validate($data, SmsSceneValidate::class . '.createTemplateAndBind');
        $result = $this->service()->createTemplateAndBind($data);
        return $this->success($result, '已创建模板并绑定场景');
    }

    public function unbind()
    {
        $sceneCode = (string) $this->request->param('scene_code', '');
        if ($sceneCode === '') {
            return $this->error('scene_code 必填');
        }
        $this->service()->unbind($sceneCode);
        return $this->success(null, '取消绑定成功');
    }
}
