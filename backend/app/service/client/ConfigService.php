<?php

declare(strict_types=1);

namespace app\service\client;

use app\common\enum\PayMethod;
use app\service\SystemSettingService;
use app\service\UploadService;
use mall_base\base\BaseModel;
use mall_base\base\BaseService;

/**
 * 客户端公开配置服务
 *
 * 职责：
 * - 聚合 Client 启动所需的非敏感配置（Logo、Banner、分享、协议、版权等）
 * - 显式白名单过滤，禁止 AppID/AppSecret/pay_/upload_/jwt_/admin_/site_url 等敏感或管理字段外泄
 *
 * 数据源：SystemSettingService → mb_setting
 *
 * @extends BaseService<BaseModel>
 */
class ConfigService extends BaseService
{
    protected string $modelClass = BaseModel::class;

    /**
     * 完整组直接输出的 group code 白名单
     */
    private const PUBLIC_GROUPS = [
        'ClientConfig',     // client_site_name / client_logo / client_launch_image / client_home_banners / client_share_* / client_agreement / client_privacy
        'SystemCopyright',  // copyright_* （后台与 Client 共用版权）
    ];

    /**
     * SystemBasic 组内允许输出到 Client 的字段白名单
     * 显式列出，杜绝管理员/敏感字段泄露（site_url / admin_* / default_avatar 等）
     */
    private const SYSTEM_BASIC_PUBLIC_FIELDS = [
        'site_name',
        'site_slogan',
    ];

    /**
     * 微信小程序组内允许输出给 Client 的展示字段白名单。
     * 注意：禁止把 AppID/AppSecret/token/aes_key 等凭证字段加入这里。
     */
    private const WECHAT_MINI_PUBLIC_FIELDS = [
        'wechat_mini_name' => 'client_auth_name',
        'wechat_mini_auth_logo' => 'client_auth_logo',
    ];

    /**
     * 获取客户端基础配置
     *
     * @return array<string, mixed>
     */
    public function basic(): array
    {
        /** @var SystemSettingService $settingsService */
        $settingsService = app()->make(SystemSettingService::class);
        $merged = $settingsService->getSystemSettingGroups(self::PUBLIC_GROUPS);

        // SystemBasic 组走字段级白名单
        foreach (self::SYSTEM_BASIC_PUBLIC_FIELDS as $code) {
            $value = getSystemSetting($code);
            if ($value !== null) {
                $merged[$code] = $value;
            }
        }

        $systemBasic = $settingsService->getSystemSettingGroupWithMeta('SystemBasic');
        if (isset($systemBasic['admin_favicon'])) {
            $favicon = $systemBasic['admin_favicon'];
            $value = !empty($favicon['full_url']) ? $favicon['full_url'] : ($favicon['value'] ?? null);
            if ($value !== null && $value !== '') {
                $merged['site_favicon'] = $value;
            }
        }

        $wechatMini = $settingsService->getSystemSettingGroupWithMeta('WechatMiniProgram');
        foreach (self::WECHAT_MINI_PUBLIC_FIELDS as $code => $publicCode) {
            if (!isset($wechatMini[$code])) {
                continue;
            }
            $item = $wechatMini[$code];
            $value = !empty($item['full_url']) ? $item['full_url'] : ($item['value'] ?? null);
            if ($value !== null && $value !== '') {
                $merged[$publicCode] = $value;
            }
        }

        // 版权 {year} 占位替换
        if (!empty($merged['copyright_date']) && is_string($merged['copyright_date'])) {
            $merged['copyright_date'] = str_replace('{year}', (string) date('Y'), $merged['copyright_date']);
        }

        return $merged;
    }

    /**
     * 已启用的客户端支付方式
     *
     * 仅依据 mb_setting 中的 payment_*_enabled 开关返回，前端据此渲染支付方式 sheet。
     * 后端最终准入由 OrderController::pay 同步校验同一组开关，避免 sheet 被绕过。
     *
     * @return array<int, array{code:int, name:string, icon:string}>
     */
    public function getPayMethods(): array
    {
        $list = [];
        if ((string) getSystemSetting('payment_balance_enabled', '0') === '1') {
            $list[] = [
                'code' => PayMethod::BALANCE,
                'name' => '余额支付',
                'icon' => 'wallet',
            ];
        }
        if ((string) getSystemSetting('payment_wechat_enabled', '0') === '1') {
            $list[] = [
                'code' => PayMethod::WECHAT,
                'name' => '微信支付',
                'icon' => 'wechat',
            ];
        }
        return $list;
    }

    /**
     * 已启用的余额充值方式
     *
     * @return array<int, array{code:int, name:string, icon:string}>
     */
    public function getRechargeMethods(): array
    {
        $list = [];
        if ((string) getSystemSetting('payment_wechat_enabled', '0') === '1') {
            $list[] = [
                'code' => PayMethod::WECHAT,
                'name' => '微信支付',
                'icon' => 'wechat',
            ];
        }
        if ((string) getSystemSetting('payment_alipay_enabled', '0') === '1') {
            $list[] = [
                'code' => PayMethod::ALIPAY,
                'name' => '支付宝',
                'icon' => 'alipay',
            ];
        }
        return $list;
    }

    /**
     * 获取客户端安全上传配置。
     *
     * @return array{max_size: float, max_count: int, accept_types: string[], tips: string[]}
     */
    public function uploadConfig(string $type): array
    {
        return app()->make(UploadService::class)->getClientUploadConfig($type);
    }
}
