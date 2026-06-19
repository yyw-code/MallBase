<?php

declare(strict_types=1);

namespace app\service\admin\sms;

use app\model\setting\Setting;
use app\model\setting\SettingGroup;
use app\service\cache\SettingCacheService;
use app\service\sms\SmsConfig;
use app\service\upload\AssetHydrator;
use app\validate\admin\setting\SettingValueValidate;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 短信全局频控配置 Service.
 *
 * 数据归档在系统表单,但入口与权限仍归短信模块。
 *
 * @extends BaseService<SettingGroup>
 */
class SmsConfigService extends BaseService
{
    public const GROUP_CODE = 'SmsRateLimit';

    public const CODE_TTL = SmsConfig::CODE_TTL;

    public const RATE_MOBILE_DAILY = SmsConfig::RATE_MOBILE_DAILY;

    public const RATE_IP_MINUTE = SmsConfig::RATE_IP_MINUTE;

    public const DEFAULT_CODE_TTL = SmsConfig::DEFAULT_CODE_TTL;

    public const DEFAULT_RATE_MOBILE_DAILY = SmsConfig::DEFAULT_RATE_MOBILE_DAILY;

    public const DEFAULT_RATE_IP_MINUTE = SmsConfig::DEFAULT_RATE_IP_MINUTE;

    protected string $modelClass = SettingGroup::class;

    public function __construct(private readonly SettingCacheService $cacheService)
    {
    }

    public function getConfig(): array
    {
        $group = $this->getGroup();
        $settings = $this->getSettings((int) $group->id);

        return [
            'group' => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'code' => (string) $group->code,
                'icon' => $group->icon,
                'display_type' => SettingGroup::DISPLAY_TYPE_PAGE,
            ],
            'display_type' => SettingGroup::DISPLAY_TYPE_PAGE,
            'settings' => app()->make(AssetHydrator::class)->hydrateSettings($settings),
        ];
    }

    public function save(array $data): void
    {
        $group = $this->getGroup();
        $settings = $this->getSettings((int) $group->id);

        $errors = (new SettingValueValidate())->validateGroupValues($settings, $data);
        if (!empty($errors)) {
            $firstError = is_array($errors) ? (string) reset($errors) : '';
            throw new BusinessException($firstError !== '' ? $firstError : '配置验证失败');
        }

        $updatedCodes = [];
        foreach ($this->model(Setting::class)->where('group_id', $group->id)->select() as $setting) {
            $code = (string) $setting->code;
            if (!array_key_exists($code, $data)) {
                continue;
            }
            $setting->save(['value' => (string) $data[$code]]);
            $updatedCodes[] = $code;
        }

        $this->cacheService->clearSettingValues($updatedCodes);
        $this->cacheService->clearGroup(self::GROUP_CODE);
    }

    private function getGroup(): SettingGroup
    {
        $group = $this->model()->where('code', self::GROUP_CODE)->find();
        if (!$group) {
            throw new BusinessException('短信频控设置分组不存在');
        }

        return $group;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSettings(int $groupId): array
    {
        return $this->model(Setting::class)
            ->where('group_id', $groupId)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
    }
}
