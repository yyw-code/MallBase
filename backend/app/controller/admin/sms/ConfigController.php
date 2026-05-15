<?php

declare(strict_types=1);

namespace app\controller\admin\sms;

use app\service\admin\sms\SmsConfigService;
use app\validate\admin\sms\SmsConfigValidate;
use mall_base\base\BaseController;

/**
 * 短信全局频控配置控制器(单行)
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
        $data = $this->request->param(['code_ttl', 'rate_mobile_daily', 'rate_ip_minute']);
        $this->validate($data, SmsConfigValidate::class . '.save');
        $this->service()->save($data);
        return $this->success(null, '保存成功');
    }
}
