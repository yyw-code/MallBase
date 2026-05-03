<?php

declare(strict_types=1);

namespace app\controller\client;

use app\service\client\ConfigService;
use mall_base\base\BaseController;

/**
 * 客户端公开配置控制器
 *
 * 提供给 H5 / 小程序 / uniapp 启动时读取的非敏感配置。
 *
 * @extends BaseController<ConfigService>
 */
class ConfigController extends BaseController
{
    protected string $serviceClass = ConfigService::class;

    /**
     * 获取客户端基础配置
     * GET /client/api/setting/basic
     *
     * 返回字段白名单（参见 ConfigService 内白名单），只包含展示配置，
     * 绝不包含 AppID/AppSecret/pay_/upload_/jwt_/admin_/site_url 等敏感或管理员字段。
     *
     * 响应带 Cache-Control: public, max-age=60，减轻首屏突刺；
     * 后台保存 → SettingCacheService 清缓存 → 下个 60s 窗口生效。
     */
    public function basic()
    {
        $data = $this->service()->basic();

        return $this->success($data, '获取成功')
            ->header(['Cache-Control' => 'public, max-age=60']);
    }
}
