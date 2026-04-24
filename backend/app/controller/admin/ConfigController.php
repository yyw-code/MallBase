<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\service\SystemSettingService;
use app\service\UploadService;
use mall_base\base\BaseController;
use mall_base\log\Logger;

/**
 * 系统配置控制器
 * @extends BaseController<UploadService>
 */
class ConfigController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = UploadService::class;

    /**
     * 获取颜色选项
     */
    public function colorOptions()
    {
        $options = [
            ['value' => 'gold', 'label' => '金色', 'color' => 'gold'],
            ['value' => 'blue', 'label' => '蓝色', 'color' => 'blue'],
            ['value' => 'green', 'label' => '绿色', 'color' => 'green'],
            ['value' => 'red', 'label' => '红色', 'color' => 'red'],
            ['value' => 'orange', 'label' => '橙色', 'color' => 'orange'],
            ['value' => 'purple', 'label' => '紫色', 'color' => 'purple'],
            ['value' => 'cyan', 'label' => '青色', 'color' => 'cyan'],
            ['value' => 'volcano', 'label' => '火山红', 'color' => 'volcano'],
            ['value' => 'magenta', 'label' => '洋红', 'color' => 'magenta'],
            ['value' => 'lime', 'label' => '青柠', 'color' => 'lime'],
        ];

        return $this->success(['options' => $options], '获取成功');
    }

    /**
     * 获取上传配置（前端 Upload 组件使用）
     * GET /config/uploadConfig?type=image
     */
    public function uploadConfig()
    {
        $type = $this->request->param('type', 'image');

        Logger::instance()->info('获取上传配置', ['type' => $type]);

        // Service 层会验证 type 参数的有效性
        $config = $this->service()->getUploadConfig($type);
        return $this->success($config, '获取成功');
    }

    /**
     * 获取后台应用元数据（公开接口，无需登录）
     * GET /config/appMeta
     *
     * 前端 bootstrap 阶段拉取，用于覆盖 vben preferences：
     *   - 应用名 / Logo / Favicon / 登录页文案 / 登录页装饰图
     *   - 版权信息（公司名、年份、ICP / 公安备案）
     *
     * 所有字段走 mb_setting 数据库，后台「系统设置」改动后缓存自动失效。
     */
    public function appMeta()
    {
        /** @var SystemSettingService $service */
        $service = app()->make(SystemSettingService::class);

        $meta = $service->getSystemSettingGroups([
            'SystemBasic',      // 站点信息 + 后台 Logo/Favicon/Slogan + 登录页文字
            'SystemCopyright',  // 版权信息（后台与 Client 共用）
        ]);

        // 版权 {year} 占位替换
        if (!empty($meta['copyright_date']) && is_string($meta['copyright_date'])) {
            $meta['copyright_date'] = str_replace('{year}', (string) date('Y'), $meta['copyright_date']);
        }

        return $this->success($meta, '获取成功');
    }
}
