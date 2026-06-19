<?php

declare(strict_types=1);

namespace app\controller\admin\sms;

use app\service\admin\sms\SmsConfigService;
use mall_base\base\BaseController;

/**
 * 短信全局频控配置控制器.
 *
 * @extends BaseController<SmsConfigService>
 */
class ConfigController extends BaseController
{
    protected string $serviceClass = SmsConfigService::class;

    public function info()
    {
        $info = $this->service()->getConfig();
        return $this->success($info, '获取成功');
    }

    public function save()
    {
        $data = $this->request->param();
        $this->service()->save($data);
        return $this->success(null, '保存成功');
    }
}
