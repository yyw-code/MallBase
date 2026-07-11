<?php
declare(strict_types=1);

namespace app\controller\client;

use app\service\client\DecorationService;
use mall_base\base\BaseController;

/**
 * 客户端装修配置控制器
 * @extends BaseController<DecorationService>
 */
class DecorationController extends BaseController
{
    protected string $serviceClass = DecorationService::class;

    public function config()
    {
        $data = $this->service()->config();
        return $this->success($data, '获取成功')
            ->header(['Cache-Control' => 'public, max-age=60']);
    }

    public function themes()
    {
        $data = $this->service()->themes();
        return $this->success($data, '获取成功')
            ->header(['Cache-Control' => 'public, max-age=60']);
    }
}
