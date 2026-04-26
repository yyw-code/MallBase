<?php

declare(strict_types=1);

namespace app\service\client;

use mall_base\base\BaseModel;
use mall_base\base\BaseService;

/**
 * 客户端公开配置服务
 *
 * 职责：
 * - 聚合 Client 启动所需的非敏感配置（Logo、Banner、分享、协议、版权等）
 * - 显式白名单过滤，禁止任何含 wechat_/pay_/upload_/jwt_/admin_/site_url 前缀的字段外泄
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
     * 获取客户端基础配置
     *
     * @return array<string, mixed>
     */
    public function basic(): array
    {
        $merged = [];
        foreach (self::PUBLIC_GROUPS as $groupCode) {
            $merged = array_merge($merged, getSystemSettingGroup($groupCode));
        }

        // SystemBasic 组走字段级白名单
        foreach (self::SYSTEM_BASIC_PUBLIC_FIELDS as $code) {
            $value = getSystemSetting($code);
            if ($value !== null) {
                $merged[$code] = $value;
            }
        }

        // 版权 {year} 占位替换
        if (!empty($merged['copyright_date']) && is_string($merged['copyright_date'])) {
            $merged['copyright_date'] = str_replace('{year}', (string) date('Y'), $merged['copyright_date']);
        }

        return $merged;
    }
}
