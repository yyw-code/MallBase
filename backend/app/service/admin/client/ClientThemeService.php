<?php
declare(strict_types=1);

namespace app\service\admin\client;

use app\model\client\ClientTheme;
use app\model\setting\Setting;
use app\model\setting\SettingGroup;
use app\model\user\UserThemePreference;
use app\service\cache\SettingCacheService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use think\facade\Db;

/**
 * 客户端主题服务
 * @extends BaseService<ClientTheme>
 */
class ClientThemeService extends BaseService
{
    protected string $modelClass = ClientTheme::class;

    private const SETTING_ID = 1;
    private const SETTING_GROUP_CODE = 'ClientConfig';
    private const SETTING_USER_SELECT_ENABLED = 'client_theme_user_select_enabled';
    private const SETTING_ADMIN_MODE = 'client_theme_admin_mode';
    private const SETTING_ADMIN_THEME_ID = 'client_theme_admin_theme_id';

    private const MODE_SYSTEM = 'system';
    private const MODE_LIGHT = 'light';
    private const MODE_DARK = 'dark';
    private const MODE_CUSTOM = 'custom';

    private const VALID_MODES = [
        self::MODE_SYSTEM,
        self::MODE_LIGHT,
        self::MODE_DARK,
        self::MODE_CUSTOM,
    ];

    private const REQUIRED_TOKEN_KEYS = [
        'colorPrimary',
        'colorBg',
        'colorBgSurface',
        'colorText',
        'colorTextSecondary',
        'colorBorder',
        'colorPrice',
    ];

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->whereNull('delete_time')
            ->when(($where['type'] ?? null) !== null && $where['type'] !== '', function ($q) use ($where) {
                $q->where('type', $where['type']);
            })
            ->when(($where['keyword'] ?? null) !== null && $where['keyword'] !== '', function ($q) use ($where) {
                $q->whereLike('name', '%' . trim((string) $where['keyword']) . '%');
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
    }

    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)
            ->order('is_system', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $total = $this->buildListQuery($where)->count();

        return compact('total', 'list');
    }

    public function getInfo(int $id): array
    {
        $theme = $this->findValidTheme($id);

        return $theme->toArray();
    }

    public function create(array $data): int
    {
        $payload = $this->normalizePayload($data);
        if ((int) $payload['status'] === ClientTheme::STATUS_PUBLISHED) {
            $this->validateTokens($payload['tokens']);
        }

        $theme = $this->model()->create($payload);

        return (int) $theme->id;
    }

    public function update(int $id, array $data): bool
    {
        $theme = $this->findValidTheme($id);
        if ((int) $theme->is_system === 1) {
            throw new BusinessException('系统主题不能修改');
        }

        $payload = $this->normalizePayload($data, $theme->toArray());
        if ((int) $payload['status'] === ClientTheme::STATUS_PUBLISHED) {
            $this->validateTokens($payload['tokens']);
        }

        unset($payload['is_system']);
        $theme->save($payload);

        return true;
    }

    public function copy(int $id): int
    {
        $theme = $this->findValidTheme($id);
        $data = $theme->toArray();
        unset($data['id'], $data['create_time'], $data['update_time'], $data['delete_time']);
        $data['name'] = mb_substr((string) $data['name'] . '-副本', 0, 80);
        $data['type'] = ClientTheme::TYPE_CUSTOM;
        $data['is_system'] = 0;
        $data['status'] = ClientTheme::STATUS_DRAFT;

        $copy = $this->model()->create($data);

        return (int) $copy->id;
    }

    public function publish(int $id): bool
    {
        $theme = $this->findValidTheme($id);
        if ((int) $theme->is_system === 1) {
            throw new BusinessException('系统主题已内置发布');
        }

        $tokens = $this->normalizeJsonValue($theme->tokens);
        $this->validateTokens($tokens);

        $theme->save([
            'type' => ClientTheme::TYPE_CUSTOM,
            'tokens' => $tokens,
            'status' => ClientTheme::STATUS_PUBLISHED,
        ]);

        return true;
    }

    public function delete(int $id): bool
    {
        $theme = $this->findValidTheme($id);
        if ((int) $theme->is_system === 1) {
            throw new BusinessException('系统主题不能删除');
        }

        $setting = $this->getSetting();
        $shouldResetSetting = (int) ($setting['admin_theme_id'] ?? 0) === $id;

        return (bool) $this->transaction(function () use ($theme, $setting, $shouldResetSetting) {
            $theme->save([
                'status' => ClientTheme::STATUS_DRAFT,
                'delete_time' => time(),
            ]);

            if ($shouldResetSetting) {
                $this->saveThemeSettingValues([
                    'user_select_enabled' => (int) ($setting['user_select_enabled'] ?? 1),
                    'admin_theme_mode' => self::MODE_SYSTEM,
                    'admin_theme_id' => null,
                ]);
            }
            $this->model(UserThemePreference::class)
                ->where('theme_mode', self::MODE_CUSTOM)
                ->where('theme_id', (int) $theme->id)
                ->delete();

            return true;
        });
    }

    public function getSetting(): array
    {
        $setting = $this->readThemeSettingValues();
        if ($setting !== null) {
            return $this->normalizeSettingPayload($setting, false);
        }

        $payload = $this->normalizeSettingPayload($this->findLegacyThemeSettingArray() ?? [], false);
        $this->saveThemeSettingValues($payload);

        return $payload;
    }

    public function saveSetting(array $data): array
    {
        $payload = $this->normalizeSettingPayload($data);
        return $this->transaction(function () use ($payload) {
            $this->saveThemeSettingValues($payload);

            return $payload;
        });
    }

    public function getPolicy(): array
    {
        return $this->settingToLegacyPolicy($this->getSetting());
    }

    public function savePolicy(array $data): array
    {
        $setting = $this->saveSetting($this->legacyPolicyToSettingPayload($data));

        return $this->settingToLegacyPolicy($setting);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPublishedThemes(): array
    {
        $list = $this->model()
            ->where('status', ClientTheme::STATUS_PUBLISHED)
            ->whereNull('delete_time')
            ->order('is_system', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $list !== [] ? $list : $this->fallbackThemes();
    }

    protected function findValidTheme(int $id)
    {
        $theme = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$theme) {
            throw new BusinessException('主题不存在');
        }

        return $theme;
    }

    protected function readThemeSettingValues(): ?array
    {
        $query = $this->model(Setting::class)
            ->whereIn('code', [
                self::SETTING_USER_SELECT_ENABLED,
                self::SETTING_ADMIN_MODE,
                self::SETTING_ADMIN_THEME_ID,
            ]);
        $groupId = $this->findClientConfigGroupId();
        if ($groupId > 0) {
            $query->where('group_id', $groupId);
        }

        $settings = $query->select()->toArray();

        $values = [];
        foreach ($settings as $setting) {
            $values[(string) $setting['code']] = $setting['value'] ?? null;
        }

        if (
            !array_key_exists(self::SETTING_USER_SELECT_ENABLED, $values)
            && !array_key_exists(self::SETTING_ADMIN_MODE, $values)
            && !array_key_exists(self::SETTING_ADMIN_THEME_ID, $values)
        ) {
            return null;
        }

        return [
            'id' => self::SETTING_ID,
            'user_select_enabled' => $values[self::SETTING_USER_SELECT_ENABLED] ?? 1,
            'admin_theme_mode' => $values[self::SETTING_ADMIN_MODE] ?? self::MODE_SYSTEM,
            'admin_theme_id' => $values[self::SETTING_ADMIN_THEME_ID] ?? null,
        ];
    }

    protected function legacyPolicyToSettingPayload(array $data): array
    {
        return [
            'id' => self::SETTING_ID,
            'user_select_enabled' => (int) ($data['user_select_enabled'] ?? $data['allow_user_select'] ?? 1),
            'admin_theme_mode' => (string) ($data['admin_theme_mode'] ?? $data['default_mode'] ?? self::MODE_SYSTEM),
            'admin_theme_id' => $data['admin_theme_id'] ?? $data['default_theme_id'] ?? null,
        ];
    }

    protected function settingToLegacyPolicy(array $setting): array
    {
        return [
            'id' => self::SETTING_ID,
            'allow_user_select' => (int) ($setting['user_select_enabled'] ?? 1),
            'default_mode' => (string) ($setting['admin_theme_mode'] ?? self::MODE_SYSTEM),
            'default_theme_id' => $setting['admin_theme_id'] ?? null,
        ];
    }

    protected function saveThemeSettingValues(array $payload): void
    {
        $groupId = $this->findClientConfigGroupId();
        if ($groupId <= 0) {
            throw new BusinessException('客户端配置分组不存在');
        }

        $this->saveSettingValue(
            $groupId,
            '允许用户自选主题',
            self::SETTING_USER_SELECT_ENABLED,
            (string) (int) ($payload['user_select_enabled'] ?? 1),
            Setting::TYPE_SWITCH,
            null,
            '开启后用户选择优先；关闭后管理员指定主题强制生效',
            130
        );
        $this->saveSettingValue(
            $groupId,
            '管理员指定主题模式',
            self::SETTING_ADMIN_MODE,
            (string) ($payload['admin_theme_mode'] ?? self::MODE_SYSTEM),
            Setting::TYPE_SELECT,
            [
                ['label' => '跟随系统', 'value' => self::MODE_SYSTEM],
                ['label' => '浅色', 'value' => self::MODE_LIGHT],
                ['label' => '深色', 'value' => self::MODE_DARK],
                ['label' => '自定义', 'value' => self::MODE_CUSTOM],
            ],
            '管理员统一指定的客户端主题模式',
            140
        );
        $this->saveSettingValue(
            $groupId,
            '管理员指定自定义主题ID',
            self::SETTING_ADMIN_THEME_ID,
            $payload['admin_theme_id'] === null ? '' : (string) $payload['admin_theme_id'],
            Setting::TYPE_INPUT,
            null,
            '仅管理员指定主题模式为自定义时有效',
            150
        );

        app()->make(SettingCacheService::class)->clearSettingValues([
            self::SETTING_USER_SELECT_ENABLED,
            self::SETTING_ADMIN_MODE,
            self::SETTING_ADMIN_THEME_ID,
        ]);
        app()->make(SettingCacheService::class)->clearGroup(self::SETTING_GROUP_CODE);
    }

    protected function saveSettingValue(int $groupId, string $name, string $code, string $value, string $type, ?array $options, string $remark, int $sort): void
    {
        $setting = $this->model(Setting::class)
            ->where('group_id', $groupId)
            ->where('code', $code)
            ->find();
        $data = [
            'group_id' => $groupId,
            'name' => $name,
            'code' => $code,
            'value' => $value,
            'type' => $type,
            'options' => $options,
            'rules' => null,
            'placeholder' => null,
            'remark' => $remark,
            'sort' => $sort,
        ];

        if ($setting) {
            $setting->save($data);
            return;
        }

        $this->model(Setting::class)->create($data);
    }

    protected function findClientConfigGroupId(): int
    {
        $group = $this->model(SettingGroup::class)
            ->where('code', self::SETTING_GROUP_CODE)
            ->find();

        return $group ? (int) $group->id : 0;
    }

    protected function findLegacyThemeSettingArray(): ?array
    {
        $setting = $this->findLegacyClientThemeSettingArray();
        if ($setting !== null) {
            return $setting;
        }

        return $this->findLegacyPolicyArray();
    }

    protected function findLegacyClientThemeSettingArray(): ?array
    {
        $row = $this->findLegacyTableRow('client_theme_setting');
        if ($row === null) {
            return null;
        }

        return [
            'user_select_enabled' => (int) ($row['user_select_enabled'] ?? 1),
            'admin_theme_mode' => (string) ($row['admin_theme_mode'] ?? self::MODE_SYSTEM),
            'admin_theme_id' => $row['admin_theme_id'] ?? null,
        ];
    }

    protected function findLegacyPolicyArray(): ?array
    {
        $row = $this->findLegacyTableRow('client_theme_policy');
        if ($row === null) {
            return null;
        }

        return [
            'user_select_enabled' => (int) ($row['allow_user_select'] ?? 1),
            'admin_theme_mode' => (string) ($row['default_mode'] ?? self::MODE_SYSTEM),
            'admin_theme_id' => $row['default_theme_id'] ?? null,
        ];
    }

    protected function findLegacyTableRow(string $table): ?array
    {
        try {
            if (!$this->tableExists($table)) {
                return null;
            }
            $row = Db::name($table)->where('id', self::SETTING_ID)->find();
            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function tableExists(string $logicalName): bool
    {
        $tableName = (string) config('database.connections.mysql.prefix', '') . $logicalName;
        $result = Db::query(
            'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$tableName]
        );

        return ((int) ($result[0]['c'] ?? 0)) > 0;
    }

    protected function normalizeSettingPayload(array $data, bool $strict = true): array
    {
        $userSelectEnabled = (int) ($data['user_select_enabled'] ?? 1) === 1 ? 1 : 0;
        $adminThemeMode = (string) ($data['admin_theme_mode'] ?? self::MODE_SYSTEM);
        $adminThemeId = isset($data['admin_theme_id']) && $data['admin_theme_id'] !== ''
            ? (int) $data['admin_theme_id']
            : null;

        if (!in_array($adminThemeMode, self::VALID_MODES, true)) {
            if (!$strict) {
                $adminThemeMode = self::MODE_SYSTEM;
            } else {
                throw new BusinessException('管理员指定主题模式不正确');
            }
        }

        if ($adminThemeMode === self::MODE_CUSTOM) {
            if ($adminThemeId === null || $adminThemeId <= 0) {
                if (!$strict) {
                    $adminThemeMode = self::MODE_SYSTEM;
                    $adminThemeId = null;
                } else {
                    throw new BusinessException('请选择管理员指定自定义主题');
                }
            }
            if ($adminThemeMode === self::MODE_CUSTOM) {
                $theme = $this->model()
                    ->where('id', $adminThemeId)
                    ->where('type', ClientTheme::TYPE_CUSTOM)
                    ->where('status', ClientTheme::STATUS_PUBLISHED)
                    ->whereNull('delete_time')
                    ->find();
                if (!$theme) {
                    if (!$strict) {
                        $adminThemeMode = self::MODE_SYSTEM;
                        $adminThemeId = null;
                    } else {
                        throw new BusinessException('管理员指定自定义主题不存在或未发布');
                    }
                }
            }
        } else {
            $adminThemeId = null;
        }

        return [
            'id' => self::SETTING_ID,
            'user_select_enabled' => $userSelectEnabled,
            'admin_theme_mode' => $adminThemeMode,
            'admin_theme_id' => $adminThemeId,
        ];
    }

    protected function normalizePayload(array $data, array $base = []): array
    {
        $tokens = $data['tokens'] ?? $base['tokens'] ?? [];
        $payload = [
            'name' => trim((string) ($data['name'] ?? $base['name'] ?? '')),
            'type' => ClientTheme::TYPE_CUSTOM,
            'tokens' => $this->normalizeJsonValue($tokens),
            'is_system' => 0,
            'status' => (int) ($data['status'] ?? $base['status'] ?? ClientTheme::STATUS_DRAFT),
            'sort' => (int) ($data['sort'] ?? $base['sort'] ?? 0),
        ];

        if ($payload['name'] === '') {
            throw new BusinessException('主题名称不能为空');
        }

        return $payload;
    }

    protected function normalizeJsonValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_object($value) && method_exists($value, 'value')) {
            $raw = $value->value();
            return is_array($raw) ? $raw : [];
        }

        return [];
    }

    protected function validateTokens(array $tokens): void
    {
        foreach (self::REQUIRED_TOKEN_KEYS as $key) {
            if (!isset($tokens[$key]) || trim((string) $tokens[$key]) === '') {
                throw new BusinessException("主题变量 {$key} 不能为空");
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fallbackThemes(): array
    {
        return [
            [
                'id' => 1,
                'name' => '系统浅色主题',
                'type' => ClientTheme::TYPE_LIGHT,
                'tokens' => [
                    'colorPrimary' => '#0d50d5',
                    'colorBg' => '#ffffff',
                    'colorBgSurface' => '#f3f3fe',
                    'colorText' => '#191b23',
                    'colorTextSecondary' => '#434654',
                    'colorBorder' => '#e0e4e8',
                    'colorPrice' => '#ff5a1f',
                ],
                'is_system' => 1,
                'status' => ClientTheme::STATUS_PUBLISHED,
            ],
            [
                'id' => 2,
                'name' => '系统深色主题',
                'type' => ClientTheme::TYPE_DARK,
                'tokens' => [
                    'colorPrimary' => '#386bef',
                    'colorBg' => '#10131a',
                    'colorBgSurface' => '#1b202a',
                    'colorText' => '#f2f5fa',
                    'colorTextSecondary' => '#c9d1df',
                    'colorBorder' => '#303746',
                    'colorPrice' => '#ff7a45',
                ],
                'is_system' => 1,
                'status' => ClientTheme::STATUS_PUBLISHED,
            ],
        ];
    }
}
