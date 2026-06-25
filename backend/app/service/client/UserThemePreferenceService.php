<?php
declare(strict_types=1);

namespace app\service\client;

use app\model\client\ClientTheme;
use app\model\user\UserThemePreference;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 用户客户端主题偏好服务
 * @extends BaseService<UserThemePreference>
 */
class UserThemePreferenceService extends BaseService
{
    protected string $modelClass = UserThemePreference::class;

    private const SETTING_USER_SELECT_ENABLED = 'client_theme_user_select_enabled';
    private const SETTING_ADMIN_MODE = 'client_theme_admin_mode';
    private const SETTING_ADMIN_THEME_ID = 'client_theme_admin_theme_id';

    private const MODE_SYSTEM = 'system';
    private const MODE_LIGHT = 'light';
    private const MODE_DARK = 'dark';
    private const MODE_CUSTOM = 'custom';

    private const SOURCE_ADMIN = 'admin';
    private const SOURCE_USER = 'user';

    private const VALID_MODES = [
        self::MODE_SYSTEM,
        self::MODE_LIGHT,
        self::MODE_DARK,
        self::MODE_CUSTOM,
    ];

    public function getCurrent(int $userId): array
    {
        $setting = $this->getThemeSetting();
        $preference = $this->findPreference($userId);
        $effective = $this->resolveEffectiveSelection($setting, $preference);

        return compact('setting', 'preference', 'effective');
    }

    public function saveCurrent(int $userId, array $data): array
    {
        $setting = $this->getThemeSetting();
        if ((int) ($setting['user_select_enabled'] ?? 1) !== 1) {
            throw new BusinessException('主题由管理员统一设置');
        }

        $payload = $this->normalizePreferencePayload($data, true);
        $this->transaction(function () use ($userId, $payload): void {
            $preference = $this->model()->where('user_id', $userId)->find();
            $saveData = [
                'user_id' => $userId,
                'theme_mode' => $payload['theme_mode'],
                'theme_id' => $payload['theme_id'],
            ];

            if ($preference) {
                $preference->save($saveData);
                return;
            }

            $this->model()->create($saveData);
        });

        return $this->getCurrent($userId);
    }

    protected function getThemeSetting(): array
    {
        return $this->normalizeThemeSetting([
            'user_select_enabled' => getSystemSetting(self::SETTING_USER_SELECT_ENABLED),
            'admin_theme_mode' => getSystemSetting(self::SETTING_ADMIN_MODE),
            'admin_theme_id' => getSystemSetting(self::SETTING_ADMIN_THEME_ID),
        ]);
    }

    protected function normalizeThemeSetting(array $setting): array
    {
        $mode = (string) ($setting['admin_theme_mode'] ?? self::MODE_SYSTEM);
        if (!in_array($mode, self::VALID_MODES, true)) {
            $mode = self::MODE_SYSTEM;
        }
        $themeId = $this->normalizeThemeId($setting['admin_theme_id'] ?? null);
        if ($mode !== self::MODE_CUSTOM || !$this->publishedCustomThemeExists($themeId)) {
            $themeId = null;
            if ($mode === self::MODE_CUSTOM) {
                $mode = self::MODE_SYSTEM;
            }
        }

        return [
            'user_select_enabled' => (int) ($setting['user_select_enabled'] ?? 1) === 1 ? 1 : 0,
            'admin_theme_mode' => $mode,
            'admin_theme_id' => $themeId,
        ];
    }

    protected function findPreference(int $userId): ?array
    {
        $row = $this->model()->where('user_id', $userId)->find();
        if (!$row) {
            return null;
        }

        $preference = $this->normalizePreferencePayload($row->toArray(), false);
        return $preference['theme_mode'] === '' ? null : $preference;
    }

    protected function resolveEffectiveSelection(array $setting, ?array $preference): array
    {
        if ((int) ($setting['user_select_enabled'] ?? 1) !== 1 || $preference === null) {
            return [
                'theme_mode' => (string) ($setting['admin_theme_mode'] ?? self::MODE_SYSTEM),
                'theme_id' => $setting['admin_theme_id'] ?? null,
                'source' => self::SOURCE_ADMIN,
            ];
        }

        return [
            'theme_mode' => $preference['theme_mode'],
            'theme_id' => $preference['theme_id'],
            'source' => self::SOURCE_USER,
        ];
    }

    protected function normalizePreferencePayload(array $data, bool $strict): array
    {
        $mode = (string) ($data['theme_mode'] ?? $data['mode'] ?? self::MODE_SYSTEM);
        if (!in_array($mode, self::VALID_MODES, true)) {
            if ($strict) {
                throw new BusinessException('主题模式不正确');
            }

            return ['theme_mode' => '', 'theme_id' => null];
        }

        $themeId = $this->normalizeThemeId($data['theme_id'] ?? $data['themeId'] ?? null);
        if ($mode !== self::MODE_CUSTOM) {
            $themeId = null;
        } elseif (!$this->publishedCustomThemeExists($themeId)) {
            if ($strict) {
                throw new BusinessException('自定义主题不存在或未发布');
            }

            return ['theme_mode' => '', 'theme_id' => null];
        }

        return [
            'theme_mode' => $mode,
            'theme_id' => $themeId,
        ];
    }

    protected function normalizeThemeId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    protected function publishedCustomThemeExists(?int $themeId): bool
    {
        if ($themeId === null || $themeId <= 0) {
            return false;
        }

        return (bool) $this->model(ClientTheme::class)
            ->where('id', $themeId)
            ->where('type', ClientTheme::TYPE_CUSTOM)
            ->where('status', ClientTheme::STATUS_PUBLISHED)
            ->whereNull('delete_time')
            ->find();
    }
}
