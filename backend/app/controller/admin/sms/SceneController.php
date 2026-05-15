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
        $list = $this->service()->getList();
        return $this->success($list, '获取成功');
    }

    public function bind()
    {
        $data = $this->request->param(['scene_code', 'provider_id', 'template_id', 'sign_id', 'status']);
        $this->validate($data, SmsSceneValidate::class . '.bind');
        $this->service()->bind($data);
        return $this->success(null, '绑定成功');
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
