<?php
declare(strict_types=1);

namespace app\service\client;

use app\model\client\ClientDecorationScheme;
use app\model\client\ClientTheme;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;
use think\facade\Db;

/**
 * 客户端装修读取服务
 * @extends BaseService<ClientDecorationScheme>
 */
class DecorationService extends BaseService
{
    protected string $modelClass = ClientDecorationScheme::class;

    private const THEME_SETTING_ID = 1;
    private const SETTING_USER_SELECT_ENABLED = 'client_theme_user_select_enabled';
    private const SETTING_ADMIN_MODE = 'client_theme_admin_mode';
    private const SETTING_ADMIN_THEME_ID = 'client_theme_admin_theme_id';
    private const THEME_ACTION_PATH = 'mb-action://theme';
    private const THEME_PAGE_PATH = '/pages-sub/user/theme';

    private const MODE_SYSTEM = 'system';
    private const MODE_LIGHT = 'light';
    private const MODE_DARK = 'dark';
    private const MODE_CUSTOM = 'custom';

    private const VALID_THEME_MODES = [
        self::MODE_SYSTEM,
        self::MODE_LIGHT,
        self::MODE_DARK,
        self::MODE_CUSTOM,
    ];

    /**
     * 获取客户端装修总配置。
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $home = $this->getActiveOrSystemScheme(ClientDecorationScheme::TYPE_HOME);
        $floating = $this->getActiveOrSystemScheme(ClientDecorationScheme::TYPE_FLOATING);
        $profile = $this->getActiveOrSystemScheme(ClientDecorationScheme::TYPE_PROFILE);
        $tabbar = $this->getActiveOrSystemScheme(ClientDecorationScheme::TYPE_TABBAR);

        return [
            'home' => $this->normalizeClientSchema(ClientDecorationScheme::TYPE_HOME, $home['schema']),
            'floating' => $this->normalizeClientSchema(ClientDecorationScheme::TYPE_FLOATING, $floating['schema']),
            'profile' => $this->normalizeClientSchema(ClientDecorationScheme::TYPE_PROFILE, $profile['schema']),
            'tabbar' => [
                'mode' => $tabbar['tabbar_mode'],
                'schema' => $this->normalizeClientSchema(ClientDecorationScheme::TYPE_TABBAR, $tabbar['schema']),
            ],
            'theme' => $this->themes(),
        ];
    }

    /**
     * 获取客户端主题配置。
     *
     * @return array{policy: array<string, mixed>, setting: array<string, mixed>, themes: array<int, array<string, mixed>>}
     */
    public function themes(): array
    {
        $setting = $this->getThemeSetting();
        $policy = $this->themeSettingToLegacyPolicy($setting);

        $themes = $this->model(ClientTheme::class)
            ->where('status', ClientTheme::STATUS_PUBLISHED)
            ->whereNull('delete_time')
            ->order('is_system', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if ($themes === []) {
            $themes = $this->fallbackThemes();
        }

        $tokens = $this->firstThemeTokens($themes, ClientTheme::TYPE_LIGHT);

        return compact('policy', 'setting', 'themes', 'tokens');
    }

    protected function getThemeSetting(): array
    {
        $setting = [
            'user_select_enabled' => getSystemSetting(self::SETTING_USER_SELECT_ENABLED),
            'admin_theme_mode' => getSystemSetting(self::SETTING_ADMIN_MODE),
            'admin_theme_id' => getSystemSetting(self::SETTING_ADMIN_THEME_ID),
        ];

        if (
            $setting['user_select_enabled'] !== null
            || $setting['admin_theme_mode'] !== null
            || $setting['admin_theme_id'] !== null
        ) {
            return $this->normalizeThemeSetting($setting);
        }

        $legacy = $this->findLegacyClientThemeSettingArray() ?? $this->findLegacyPolicySettingArray();
        if ($legacy !== null) {
            return $this->normalizeThemeSetting($legacy);
        }

        return $this->normalizeThemeSetting([]);
    }

    protected function themeSettingToLegacyPolicy(array $setting): array
    {
        return [
            'id' => self::THEME_SETTING_ID,
            'allow_user_select' => (int) ($setting['user_select_enabled'] ?? 1),
            'default_mode' => (string) ($setting['admin_theme_mode'] ?? self::MODE_SYSTEM),
            'default_theme_id' => $setting['admin_theme_id'] ?? null,
        ];
    }

    protected function normalizeThemeSetting(array $setting): array
    {
        $mode = (string) ($setting['admin_theme_mode'] ?? $setting['default_mode'] ?? self::MODE_SYSTEM);
        if (!in_array($mode, self::VALID_THEME_MODES, true)) {
            $mode = self::MODE_SYSTEM;
        }
        $themeId = $setting['admin_theme_id'] ?? $setting['default_theme_id'] ?? null;
        $themeId = $themeId === null || $themeId === '' ? null : (int) $themeId;
        if ($mode !== self::MODE_CUSTOM) {
            $themeId = null;
        }

        return [
            'id' => self::THEME_SETTING_ID,
            'user_select_enabled' => (int) ($setting['user_select_enabled'] ?? $setting['allow_user_select'] ?? 1) === 1 ? 1 : 0,
            'admin_theme_mode' => $mode,
            'admin_theme_id' => $themeId,
        ];
    }

    protected function findLegacyClientThemeSettingArray(): ?array
    {
        $row = $this->findLegacyTableRow('client_theme_setting');
        if ($row === null) {
            return null;
        }

        return [
            'user_select_enabled' => $row['user_select_enabled'] ?? 1,
            'admin_theme_mode' => $row['admin_theme_mode'] ?? self::MODE_SYSTEM,
            'admin_theme_id' => $row['admin_theme_id'] ?? null,
        ];
    }

    protected function findLegacyPolicySettingArray(): ?array
    {
        $row = $this->findLegacyTableRow('client_theme_policy');
        if ($row === null) {
            return null;
        }

        return [
            'allow_user_select' => $row['allow_user_select'] ?? 1,
            'default_mode' => $row['default_mode'] ?? self::MODE_SYSTEM,
            'default_theme_id' => $row['default_theme_id'] ?? null,
        ];
    }

    protected function findLegacyTableRow(string $table): ?array
    {
        try {
            if (!$this->tableExists($table)) {
                return null;
            }
            $row = Db::name($table)->where('id', self::THEME_SETTING_ID)->find();
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

    /**
     * @return array{id:int,type:string,name:string,schema:array,tabbar_mode:string}
     */
    protected function getActiveOrSystemScheme(string $type): array
    {
        $scheme = $this->model()
            ->where('type', $type)
            ->where('is_active', 1)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->order('is_system', 'asc')
            ->order('id', 'desc')
            ->find();

        if (!$scheme) {
            $scheme = $this->model()
                ->where('type', $type)
                ->where('is_system', 1)
                ->where('status', 1)
                ->whereNull('delete_time')
                ->order('id', 'asc')
                ->find();
        }

        if (!$scheme) {
            return $this->fallbackScheme($type);
        }

        $data = $scheme->toArray();
        $data['schema'] = $this->normalizeJsonValue($data['schema'] ?? []);
        $data['tabbar_mode'] = (string) ($data['tabbar_mode'] ?? ClientDecorationScheme::TABBAR_MODE_NATIVE);

        return $data;
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

    protected function normalizeSchemaByType(string $type, array $schema): array
    {
        $isList = array_is_list($schema);

        if ($type === ClientDecorationScheme::TYPE_HOME) {
            if ($isList) {
                $schema = ['components' => $schema, 'modules' => $schema];
            }
            $schema['pageStyle'] = $this->normalizeHomePageStyle($schema['pageStyle'] ?? []);
            if (!isset($schema['components']) && isset($schema['modules']) && is_array($schema['modules'])) {
                $schema['components'] = $schema['modules'];
            }
            if (!isset($schema['modules']) && isset($schema['components']) && is_array($schema['components'])) {
                $schema['modules'] = $schema['components'];
            }
            $schema['components'] = $this->normalizeModuleListForClient($schema['components'] ?? []);
            $schema['modules'] = $this->normalizeModuleListForClient($schema['modules'] ?? []);
        }

        if ($type === ClientDecorationScheme::TYPE_PROFILE) {
            if ($isList) {
                $schema = ['modules' => $schema];
            }
            $schema['pageStyle'] = $this->normalizeProfilePageStyle($schema['pageStyle'] ?? []);
            if (!isset($schema['modules']) && isset($schema['components']) && is_array($schema['components'])) {
                $schema['modules'] = $schema['components'];
            }
            $schema['modules'] = $this->normalizeModuleListForClient($schema['modules'] ?? []);
            $schema['modules'] = $this->normalizeProfileServiceMenuModules($schema['modules']);
        }

        if ($type === ClientDecorationScheme::TYPE_TABBAR && $isList) {
            $schema = ['items' => $schema];
        }

        if ($type === ClientDecorationScheme::TYPE_FLOATING) {
            $schema = $this->normalizeFloatingSchema($schema);
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultFloatingSchema(): array
    {
        return [
            'enabled' => true,
            'hiddenPages' => ['/pages-sub/user/login', '/pages-sub/user/agreement'],
            'items' => [
                ['enabled' => true, 'icon' => 'static/client/floating/service.png', 'id' => 'floating-service', 'text' => '客服', 'type' => 'customerService'],
                ['enabled' => true, 'icon' => 'static/client/floating/cart.png', 'id' => 'floating-cart', 'path' => '/pages/cart/index', 'text' => '购物车', 'type' => 'page'],
                ['enabled' => true, 'icon' => 'static/client/floating/home.png', 'id' => 'floating-home', 'path' => '/pages/index/index', 'text' => '首页', 'type' => 'page'],
            ],
            'mode' => 'expand',
            'offsetBottom' => 160,
            'offsetX' => 24,
            'position' => 'right-bottom',
            'style' => [
                'backgroundColor' => '',
                'color' => '',
                'radius' => 44,
                'shadowBlur' => 30,
                'shadowColor' => '#0f172a',
                'shadowEnabled' => true,
                'shadowOffsetX' => 0,
                'shadowOffsetY' => 12,
                'shadowOpacity' => 14,
                'shadowSpread' => 0,
                'size' => 88,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function normalizeFloatingSchema(array $schema): array
    {
        $defaults = $this->defaultFloatingSchema();
        $mode = (string) ($schema['mode'] ?? $defaults['mode']);
        if (!in_array($mode, ['expand', 'single', 'vertical'], true)) {
            $mode = $defaults['mode'];
        }
        $position = (string) ($schema['position'] ?? $defaults['position']);
        if (!in_array($position, ['left-bottom', 'right-bottom'], true)) {
            $position = $defaults['position'];
        }

        $style = is_array($schema['style'] ?? null) ? $schema['style'] : [];
        $items = is_array($schema['items'] ?? null) ? $schema['items'] : $defaults['items'];
        $normalizedItems = $this->normalizeFloatingItems($items);
        $hiddenPages = is_array($schema['hiddenPages'] ?? null)
            ? $schema['hiddenPages']
            : (is_array($schema['hidden_pages'] ?? null) ? $schema['hidden_pages'] : $defaults['hiddenPages']);

        return [
            'enabled' => (bool) ($schema['enabled'] ?? $defaults['enabled']),
            'hiddenPages' => $this->normalizeFloatingHiddenPages($hiddenPages),
            'items' => $normalizedItems,
            'mode' => $mode,
            'offsetBottom' => $this->normalizeInteger($schema['offsetBottom'] ?? $schema['offset_bottom'] ?? $defaults['offsetBottom'], 0, 360, $defaults['offsetBottom']),
            'offsetX' => $this->normalizeInteger($schema['offsetX'] ?? $schema['offset_x'] ?? $defaults['offsetX'], 0, 160, $defaults['offsetX']),
            'position' => $position,
            'singleItemId' => $this->normalizeFloatingSingleItemId($schema['singleItemId'] ?? $schema['single_item_id'] ?? '', $normalizedItems),
            'style' => [
                'backgroundColor' => (string) ($style['backgroundColor'] ?? $style['background_color'] ?? $defaults['style']['backgroundColor']),
                'color' => (string) ($style['color'] ?? $defaults['style']['color']),
                'radius' => $this->normalizeInteger($style['radius'] ?? $defaults['style']['radius'], 0, 120, $defaults['style']['radius']),
                'shadowBlur' => $this->normalizeInteger($style['shadowBlur'] ?? $style['shadow_blur'] ?? $defaults['style']['shadowBlur'], 0, 160, $defaults['style']['shadowBlur']),
                'shadowColor' => (string) ($style['shadowColor'] ?? $style['shadow_color'] ?? $defaults['style']['shadowColor']),
                'shadowEnabled' => (bool) ($style['shadowEnabled'] ?? $style['shadow_enabled'] ?? $defaults['style']['shadowEnabled']),
                'shadowOffsetX' => $this->normalizeInteger($style['shadowOffsetX'] ?? $style['shadow_offset_x'] ?? $defaults['style']['shadowOffsetX'], -80, 80, $defaults['style']['shadowOffsetX']),
                'shadowOffsetY' => $this->normalizeInteger($style['shadowOffsetY'] ?? $style['shadow_offset_y'] ?? $defaults['style']['shadowOffsetY'], -80, 80, $defaults['style']['shadowOffsetY']),
                'shadowOpacity' => $this->normalizeInteger($style['shadowOpacity'] ?? $style['shadow_opacity'] ?? $defaults['style']['shadowOpacity'], 0, 100, $defaults['style']['shadowOpacity']),
                'shadowSpread' => $this->normalizeInteger($style['shadowSpread'] ?? $style['shadow_spread'] ?? $defaults['style']['shadowSpread'], -80, 80, $defaults['style']['shadowSpread']),
                'size' => $this->normalizeInteger($style['size'] ?? $defaults['style']['size'], 56, 128, $defaults['style']['size']),
            ],
        ];
    }

    /**
     * @param array<int, mixed> $hiddenPages
     * @return array<int, string>
     */
    protected function normalizeFloatingHiddenPages(array $hiddenPages): array
    {
        $list = [];
        foreach ($hiddenPages as $path) {
            $normalized = $this->normalizeFloatingRoutePath($path);
            if ($normalized !== '') {
                $list[$normalized] = $normalized;
            }
        }

        return array_values($list);
    }

    protected function normalizeFloatingRoutePath(mixed $path): string
    {
        if (!is_scalar($path)) {
            return '';
        }

        $value = trim((string) $path);
        if ($value === '' || str_contains($value, '://')) {
            return '';
        }

        $value = trim(explode('?', explode('#', $value, 2)[0], 2)[0]);
        if ($value === '') {
            return '';
        }

        $value = '/' . ltrim($value, '/');
        $value = (string) preg_replace('#/+#', '/', $value);
        if ($value !== '/') {
            $value = rtrim($value, '/');
        }

        return $value === '/' ? '' : $value;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeFloatingItems(array $items): array
    {
        $list = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? 'page');
            if (!in_array($type, ['customerService', 'page'], true)) {
                $type = 'page';
            }
            $text = trim((string) ($item['text'] ?? $item['title'] ?? '入口'));
            $path = trim((string) ($item['path'] ?? ''));
            $id = trim((string) ($item['id'] ?? $item['key'] ?? ''));
            $normalized = [
                'enabled' => (bool) ($item['enabled'] ?? true),
                'icon' => $item['icon'] ?? '',
                'path' => $type === 'page' ? $path : '',
                'text' => $text === '' ? '入口' : $text,
                'type' => $type,
            ];
            if ($id !== '') {
                $normalized['id'] = $id;
            }
            $preset = $this->getDefaultFloatingPresetType($normalized);
            if ($preset !== '') {
                $default = $this->defaultFloatingPresetItem($preset);
                if ($default === []) {
                    $list[] = $normalized;
                    continue;
                }
                if (!isset($normalized['id'])) {
                    $normalized['id'] = $default['id'];
                }
                if (
                    !$this->hasFloatingItemIcon($normalized['icon'] ?? '')
                    || $this->isLegacyFloatingItemIcon($normalized['icon'] ?? '')
                ) {
                    $normalized['icon'] = $default['icon'];
                }
            }
            $list[] = $normalized;
        }

        return $list === [] ? $this->defaultFloatingSchema()['items'] : $list;
    }

    protected function getDefaultFloatingPresetType(array $item): string
    {
        $id = (string) ($item['id'] ?? $item['key'] ?? '');
        $idMap = [
            'floating-cart' => 'cart',
            'floating-home' => 'home',
            'floating-service' => 'service',
        ];
        if (isset($idMap[$id])) {
            return $idMap[$id];
        }

        $text = trim((string) ($item['text'] ?? ''));
        $type = (string) ($item['type'] ?? '');
        $path = preg_replace('/[?#].*$/', '', trim((string) ($item['path'] ?? '')));
        $path = rtrim((string) $path, '/');
        if ($type === 'customerService' && $text === '客服') {
            return 'service';
        }
        if ($type === 'page' && $text === '购物车' && $path === '/pages/cart/index') {
            return 'cart';
        }
        if ($type === 'page' && $text === '首页' && $path === '/pages/index/index') {
            return 'home';
        }

        return '';
    }

    protected function defaultFloatingPresetItem(string $preset): array
    {
        foreach ($this->defaultFloatingSchema()['items'] as $item) {
            if ($this->getDefaultFloatingPresetType($item) === $preset) {
                return $item;
            }
        }

        return [];
    }

    protected function hasFloatingItemIcon(mixed $icon): bool
    {
        if (is_array($icon)) {
            foreach (['full_url', 'fullUrl', 'url', 'path', 'src'] as $field) {
                if (isset($icon[$field]) && trim((string) $icon[$field]) !== '') {
                    return true;
                }
            }
            return false;
        }

        return is_scalar($icon) && trim((string) $icon) !== '';
    }

    protected function isLegacyFloatingItemIcon(mixed $icon): bool
    {
        if (!is_scalar($icon)) {
            return false;
        }

        $value = ltrim(trim((string) $icon), '/');
        return str_starts_with($value, 'static/images/floating/')
            && str_ends_with(strtolower($value), '.svg');
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    protected function normalizeFloatingSingleItemId(mixed $value, array $items): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $id = trim((string) $value);
        if ($id === '') {
            return '';
        }

        foreach ($items as $item) {
            if (($item['enabled'] ?? true) !== false && (string) ($item['id'] ?? '') === $id) {
                return $id;
            }
        }

        return '';
    }

    protected function normalizeInteger(mixed $value, int $min, int $max, int $fallback): int
    {
        $number = is_numeric($value) ? (int) round((float) $value) : $fallback;
        return max($min, min($max, $number));
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultHomePageStyle(): array
    {
        return [
            'backgroundColorEnd' => '',
            'backgroundColorStart' => '',
            'backgroundGradientDirection' => 'horizontal',
            'backgroundMode' => 'color',
            'background_image' => '',
            'padding' => 14,
            'paddingBottom' => 0,
            'paddingLeft' => 28,
            'paddingRight' => 28,
            'paddingTop' => 0,
            'paddingX' => 28,
            'paddingY' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultProfilePageStyle(): array
    {
        return [
            'backgroundColorEnd' => '',
            'backgroundColorStart' => '',
            'backgroundGradientDirection' => 'horizontal',
            'backgroundMode' => 'color',
            'background_image' => '',
            'padding' => 23,
            'paddingBottom' => 24,
            'paddingLeft' => 28,
            'paddingRight' => 28,
            'paddingTop' => 10,
            'paddingX' => 28,
            'paddingY' => 17,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeHomePageStyle(mixed $pageStyle): array
    {
        $style = is_array($pageStyle) ? $pageStyle : [];
        $defaults = $this->defaultHomePageStyle();
        $paddingX = (int) ($style['paddingX'] ?? $style['padding_x'] ?? $defaults['paddingX']);
        $paddingY = (int) ($style['paddingY'] ?? $style['padding_y'] ?? $defaults['paddingY']);
        $paddingTop = (int) ($style['paddingTop'] ?? $style['padding_top'] ?? $style['paddingY'] ?? $style['padding_y'] ?? $defaults['paddingTop']);
        $paddingRight = (int) ($style['paddingRight'] ?? $style['padding_right'] ?? $style['paddingX'] ?? $style['padding_x'] ?? $defaults['paddingRight']);
        $paddingBottom = (int) ($style['paddingBottom'] ?? $style['padding_bottom'] ?? $style['paddingY'] ?? $style['padding_y'] ?? $defaults['paddingBottom']);
        $paddingLeft = (int) ($style['paddingLeft'] ?? $style['padding_left'] ?? $style['paddingX'] ?? $style['padding_x'] ?? $defaults['paddingLeft']);

        return [
            'backgroundColorEnd' => (string) ($style['backgroundColorEnd'] ?? $style['background_color_end'] ?? $defaults['backgroundColorEnd']),
            'backgroundColorStart' => (string) ($style['backgroundColorStart'] ?? $style['background_color_start'] ?? $defaults['backgroundColorStart']),
            'backgroundGradientDirection' => (string) ($style['backgroundGradientDirection'] ?? $style['background_gradient_direction'] ?? $defaults['backgroundGradientDirection']),
            'backgroundMode' => (string) ($style['backgroundMode'] ?? $style['background_mode'] ?? $defaults['backgroundMode']),
            'background_image' => $style['background_image'] ?? $style['backgroundImage'] ?? $defaults['background_image'],
            'padding' => $paddingTop === $paddingRight && $paddingRight === $paddingBottom && $paddingBottom === $paddingLeft
                ? $paddingTop
                : (int) round(($paddingTop + $paddingRight + $paddingBottom + $paddingLeft) / 4),
            'paddingBottom' => $paddingBottom,
            'paddingLeft' => $paddingLeft,
            'paddingRight' => $paddingRight,
            'paddingTop' => $paddingTop,
            'paddingX' => $paddingLeft === $paddingRight ? $paddingLeft : (int) round(($paddingLeft + $paddingRight) / 2),
            'paddingY' => $paddingTop === $paddingBottom ? $paddingTop : (int) round(($paddingTop + $paddingBottom) / 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeProfilePageStyle(mixed $pageStyle): array
    {
        $style = is_array($pageStyle) ? $pageStyle : [];
        $defaults = $this->defaultProfilePageStyle();
        $paddingX = (int) ($style['paddingX'] ?? $style['padding_x'] ?? $defaults['paddingX']);
        $paddingY = (int) ($style['paddingY'] ?? $style['padding_y'] ?? $defaults['paddingY']);
        $paddingTop = (int) ($style['paddingTop'] ?? $style['padding_top'] ?? $style['paddingY'] ?? $style['padding_y'] ?? $defaults['paddingTop']);
        $paddingRight = (int) ($style['paddingRight'] ?? $style['padding_right'] ?? $style['paddingX'] ?? $style['padding_x'] ?? $defaults['paddingRight']);
        $paddingBottom = (int) ($style['paddingBottom'] ?? $style['padding_bottom'] ?? $style['paddingY'] ?? $style['padding_y'] ?? $defaults['paddingBottom']);
        $paddingLeft = (int) ($style['paddingLeft'] ?? $style['padding_left'] ?? $style['paddingX'] ?? $style['padding_x'] ?? $defaults['paddingLeft']);

        return [
            'backgroundColorEnd' => (string) ($style['backgroundColorEnd'] ?? $style['background_color_end'] ?? $defaults['backgroundColorEnd']),
            'backgroundColorStart' => (string) ($style['backgroundColorStart'] ?? $style['background_color_start'] ?? $defaults['backgroundColorStart']),
            'backgroundGradientDirection' => (string) ($style['backgroundGradientDirection'] ?? $style['background_gradient_direction'] ?? $defaults['backgroundGradientDirection']),
            'backgroundMode' => (string) ($style['backgroundMode'] ?? $style['background_mode'] ?? $defaults['backgroundMode']),
            'background_image' => $style['background_image'] ?? $style['backgroundImage'] ?? $defaults['background_image'],
            'padding' => $paddingTop === $paddingRight && $paddingRight === $paddingBottom && $paddingBottom === $paddingLeft
                ? $paddingTop
                : (int) round(($paddingTop + $paddingRight + $paddingBottom + $paddingLeft) / 4),
            'paddingBottom' => $paddingBottom,
            'paddingLeft' => $paddingLeft,
            'paddingRight' => $paddingRight,
            'paddingTop' => $paddingTop,
            'paddingX' => $paddingLeft === $paddingRight ? $paddingLeft : (int) round(($paddingLeft + $paddingRight) / 2),
            'paddingY' => $paddingTop === $paddingBottom ? $paddingTop : (int) round(($paddingTop + $paddingBottom) / 2),
        ];
    }

    protected function normalizeClientSchema(string $type, array $schema): array
    {
        /** @var AssetHydrator $hydrator */
        $hydrator = app()->make(AssetHydrator::class);

        return $hydrator->hydrateDecorationSchema(
            $this->normalizeSchemaByType($type, $schema)
        );
    }

    /**
     * @param mixed $modules
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeModuleListForClient($modules): array
    {
        if (!is_array($modules)) {
            return [];
        }

        $list = [];
        foreach ($modules as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $props = [];
            foreach (['props', 'config', 'data'] as $key) {
                if (isset($item[$key]) && is_array($item[$key])) {
                    $props = array_merge($props, $item[$key]);
                }
            }
            $props = $this->stripRuntimePreviewFields($props);
            $type = (string) ($item['type'] ?? $item['component'] ?? '');
            $props = $this->applyDefaultClientProps($type, $props);

            $list[] = [
                'id' => (string) ($item['id'] ?? $item['key'] ?? ('module-' . $index)),
                'type' => $type,
                'title' => (string) ($item['title'] ?? $item['label'] ?? ''),
                'enabled' => ($item['enabled'] ?? true) !== false && ($item['visible'] ?? true) !== false,
                'sort' => (int) ($item['sort'] ?? $item['order'] ?? $index),
                'props' => $props,
            ];
        }

        return array_values(array_filter($list, fn (array $item): bool => $item['type'] !== ''));
    }

    /**
     * 将历史 customMenu 收敛到 serviceMenu，避免客户端重复渲染入口菜单。
     *
     * @param array<int, array<string, mixed>> $modules
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeProfileServiceMenuModules(array $modules): array
    {
        $hasServiceMenu = false;
        foreach ($modules as $module) {
            if (($module['type'] ?? '') === 'serviceMenu') {
                $hasServiceMenu = true;
                break;
            }
        }

        $result = [];
        $serviceIndex = null;
        $customItems = [];

        foreach ($modules as $module) {
            $type = (string) ($module['type'] ?? '');
            if ($type === 'customMenu') {
                if (!$hasServiceMenu && $serviceIndex === null) {
                    $module['type'] = 'serviceMenu';
                    $module['title'] = (string) ($module['title'] ?? '我的服务');
                    $result[] = $module;
                    $serviceIndex = count($result) - 1;
                    continue;
                }

                $customItems = array_merge($customItems, $this->profileModuleEntryItems($module));
                continue;
            }

            $result[] = $module;
            if ($type === 'serviceMenu' && $serviceIndex === null) {
                $serviceIndex = count($result) - 1;
            }
        }

        if ($serviceIndex !== null && $customItems !== []) {
            $props = is_array($result[$serviceIndex]['props'] ?? null)
                ? $result[$serviceIndex]['props']
                : [];
            $items = $this->mergeProfileEntryItems(
                $this->profileModuleEntryItems($result[$serviceIndex]),
                $customItems
            );
            $props['items'] = $items;
            $props['list'] = $items;
            $result[$serviceIndex]['props'] = $props;
        }

        return array_values($result);
    }

    /**
     * @param array<string, mixed> $module
     * @return array<int, array<string, mixed>>
     */
    protected function profileModuleEntryItems(array $module): array
    {
        $props = is_array($module['props'] ?? null) ? $module['props'] : [];
        $items = $props['items'] ?? $props['list'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, static fn ($item): bool => is_array($item)));
    }

    /**
     * @param array<int, array<string, mixed>> $base
     * @param array<int, array<string, mixed>> $extra
     * @return array<int, array<string, mixed>>
     */
    protected function mergeProfileEntryItems(array $base, array $extra): array
    {
        $items = [];
        $seen = [];
        foreach (array_merge($base, $extra) as $item) {
            $key = $this->profileEntryUniqueKey($item);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function profileEntryUniqueKey(array $item): string
    {
        foreach (['action', 'key', 'path', 'url', 'link', 'target_path', 'label', 'title', 'text'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                return $field . ':' . mb_strtolower($value);
            }
        }

        return sha1(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    protected function applyDefaultClientProps(string $type, array $props): array
    {
        $profileType = $type === 'customMenu' ? 'serviceMenu' : $type;
        $props = array_merge($this->defaultProfileStyleProps($profileType), $props);
        $props = $this->normalizeProfileStyleAliases($props);
        unset($props['textVisibility']);
        unset($props['text_visibility']);

        if ($type === 'banner') {
            $items = $props['items'] ?? $props['list'] ?? $props['images'] ?? [];
            if (!is_array($items) || $items === []) {
                $items = $this->defaultBannerItems();
            }
            $items = $this->normalizeBannerItems($items);
            $props['items'] = $items;
            $props['list'] = $items;
            $props['images'] = $items;
        }

        if ($type === 'navGrid') {
            $defaults = $this->defaultNavItems();
            $items = $props['items'] ?? [];
            if (!is_array($items) || $items === []) {
                $items = $defaults;
            }

            $props['columns'] = max(3, min((int) ($props['columns'] ?? 6), 6));
            $normalizedItems = [];
            foreach (array_values($items) as $index => $item) {
                $default = $defaults[$index % count($defaults)];
                $item = is_array($item) ? $item : [];
                $image = $item['image']
                    ?? $item['image_url']
                    ?? $item['imageUrl']
                    ?? $item['full_url']
                    ?? $item['fullUrl']
                    ?? '';
                if ($this->isLegacySvgImage($image) || $this->isLegacyDefaultNavImage($image)) {
                    $image = '';
                }

                $normalizedItems[] = array_merge($default, $item, [
                    'image' => $image !== '' && $image !== null ? $image : $this->defaultNavImageByItem($item, (string) $default['image']),
                    'path' => (string) ($item['path'] ?? $item['url'] ?? $default['path']),
                    'title' => (string) ($item['title'] ?? $item['label'] ?? $item['text'] ?? $default['title']),
                ]);
            }
            $props['items'] = $normalizedItems;
        }

        if ($type === 'imageCube') {
            $items = $props['items'] ?? $props['images'] ?? $props['list'] ?? [];
            if (!is_array($items) || $items === []) {
                $items = $this->defaultCubeItems();
            }
            $props['items'] = $items;
            $props['images'] = $items;
            $props['list'] = $items;
        }

        if ($type === 'entryCard') {
            if (empty($props['icon_image']) && empty($props['iconImage'])) {
                $props['icon_image'] = '61';
            }
            if (empty($props['background_image']) && empty($props['backgroundImage'])) {
                $props['background_image'] = '61';
            }
            $props['icon_mode'] = $props['icon_mode'] ?? $props['iconMode'] ?? 'image';
        }

        if (in_array($type, ['orderEntry', 'orderShortcut'], true)) {
            $items = $props['items'] ?? $props['list'] ?? [];
            if (!is_array($items) || $items === []) {
                $items = $this->defaultProfileOrderItems();
            }
            $items = $this->normalizeProfileEntryItems($items, 'orderShortcut');
            $props['title'] = (string) ($props['title'] ?? '我的订单');
            $props['display'] = 'grid';
            $props['items'] = $items;
            $props['list'] = $items;
        }

        if ($profileType === 'serviceMenu') {
            $items = $props['items'] ?? $props['list'] ?? [];
            if (!is_array($items) || $items === []) {
                $items = $this->defaultProfileServiceItems();
            }
            $items = $this->normalizeProfileEntryItems($items, 'serviceMenu');
            $props['columns'] = max(3, min((int) ($props['columns'] ?? 4), 5));
            $props['title'] = (string) ($props['title'] ?? '我的服务');
            $props['display'] = in_array(($props['display'] ?? ''), ['grid', 'list'], true)
                ? (string) $props['display']
                : 'list';
            $props['items'] = $items;
            $props['list'] = $items;
        }

        if (in_array($type, ['profileHeader', 'userCard', 'userInfo'], true)) {
            unset($props['show_level']);
            $props['show_mobile'] = ($props['show_mobile'] ?? true) !== false;
        }

        if (in_array($type, ['wallet', 'walletCard', 'walletEntry'], true)) {
            $props['title'] = (string) ($props['title'] ?? '我的余额');
            $props['show_balance'] = ($props['show_balance'] ?? true) !== false;
            unset($props['show_points']);
            $props['show_records'] = ($props['show_records'] ?? true) !== false;
            $props['show_view_button'] = ($props['show_view_button'] ?? true) !== false;
        }

        return $props;
    }

    /**
     * @return array<int, array{image:string,path:string,title:string}>
     */
    protected function defaultBannerItems(): array
    {
        return [
            [
                'image' => '48',
                'path' => '/pages-sub/goods/list?is_recommend=1',
                'title' => '夏日好物限时满减',
            ],
            [
                'image' => '49',
                'path' => '/pages-sub/goods/list?sort=sales',
                'title' => '会员精选 每日上新',
            ],
        ];
    }

    /**
     * @return array<int, array{image:string,path:string,title:string}>
     */
    protected function defaultCubeItems(): array
    {
        return [
            [
                'image' => '57',
                'path' => '/pages-sub/goods/list?sort=newest',
                'title' => '新品上架',
            ],
            [
                'image' => '58',
                'path' => '/pages-sub/goods/list?is_recommend=1',
                'title' => '精选榜单',
            ],
            [
                'image' => '59',
                'path' => '/pages-sub/goods/list?sort=sales',
                'title' => '会员专享',
            ],
            [
                'image' => '60',
                'path' => '/pages-sub/goods/list?is_hot=1',
                'title' => '限时满减',
            ],
        ];
    }

    /**
     * @return array<int, array{title:string,label:string,image:string,path:string}>
     */
    protected function defaultProfileOrderItems(): array
    {
        return [
            ['title' => '待付款', 'label' => '待付款', 'image' => 'static/demo/profile-order-pay.svg', 'path' => '/pages-sub/order/list?status=10'],
            ['title' => '待发货', 'label' => '待发货', 'image' => 'static/demo/profile-order-ship.svg', 'path' => '/pages-sub/order/list?status=20'],
            ['title' => '待收货', 'label' => '待收货', 'image' => 'static/demo/profile-order-receive.svg', 'path' => '/pages-sub/order/list?status=30'],
            ['title' => '退款售后', 'label' => '退款售后', 'image' => 'static/demo/profile-order-refund.svg', 'path' => '/pages-sub/refund/list'],
        ];
    }

    /**
     * @return array<int, array{title:string,label:string,image:string,path:string}>
     */
    protected function defaultProfileServiceItems(): array
    {
        return [
            ['title' => '地址管理', 'label' => '地址管理', 'image' => 'static/demo/profile-service-address.svg', 'path' => '/pages-sub/address/list'],
            ['title' => '系统设置', 'label' => '系统设置', 'image' => 'static/demo/profile-service-settings.svg', 'path' => '/pages-sub/user/settings'],
            ['title' => '联系客服', 'label' => '联系客服', 'image' => 'static/demo/profile-service-support.svg', 'path' => ''],
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeProfileEntryItems(array $items, string $type): array
    {
        $normalized = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = (string) ($item['label'] ?? $item['title'] ?? $item['text'] ?? '入口');
            $imageRemoved = ($item['imageRemoved'] ?? false) === true
                || ($item['image_removed'] ?? false) === true;
            $image = $imageRemoved
                ? ''
                : ($item['image']
                    ?? $item['image_url']
                    ?? $item['imageUrl']
                    ?? $item['icon_image']
                    ?? $item['iconImage']
                    ?? '');
            $path = (string) ($item['path'] ?? $item['url'] ?? $item['link'] ?? $item['target_path'] ?? $item['targetPath'] ?? '');
            if (
                ($item['action'] ?? '') === 'theme'
                || ($item['key'] ?? '') === 'theme'
                || $this->normalizeTargetPath($path) === self::THEME_ACTION_PATH
                || $this->normalizeTargetPath($path) === self::THEME_PAGE_PATH
            ) {
                $item['key'] = 'theme';
                $path = '';
                unset($item['url'], $item['link'], $item['target_path'], $item['targetPath']);
            }
            if (($item['action'] ?? '') === 'theme' && empty($item['key'])) {
                $item['key'] = 'theme';
            }

            unset($item['action'], $item['icon']);
            if ($imageRemoved) {
                unset(
                    $item['image_url'],
                    $item['imageUrl'],
                    $item['icon_image'],
                    $item['iconImage'],
                    $item['full_url'],
                    $item['fullUrl'],
                    $item['preview_url'],
                    $item['previewUrl']
                );
            } else {
                unset($item['imageRemoved'], $item['image_removed']);
            }

            $normalizedItem = array_merge($item, [
                'image' => $imageRemoved
                    ? ''
                    : ($image !== '' && $image !== null ? $image : $this->defaultProfileEntryImage($type, $index)),
                'label' => $label,
                'path' => $path,
                'title' => $label,
            ]);
            if ($imageRemoved) {
                $normalizedItem['imageRemoved'] = true;
                $normalizedItem['image_removed'] = true;
            }

            $normalized[] = $normalizedItem;
        }

        return $normalized;
    }

    protected function normalizeTargetPath(string $path): string
    {
        $path = strtok($path, '?') ?: $path;
        if ($path === '') {
            return '';
        }
        if (str_contains($path, '://')) {
            return $path;
        }

        return '/' . ltrim($path, '/');
    }

    protected function defaultProfileEntryImage(string $type, int $index): string
    {
        $images = $type === 'orderShortcut'
            ? [
                'static/demo/profile-order-pay.svg',
                'static/demo/profile-order-ship.svg',
                'static/demo/profile-order-receive.svg',
                'static/demo/profile-order-refund.svg',
            ]
            : [
                'static/demo/profile-service-address.svg',
                'static/demo/profile-service-settings.svg',
                'static/demo/profile-service-support.svg',
            ];

        return $images[$index % count($images)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultProfileStyleProps(string $type): array
    {
        $base = [
            'background' => '',
            'backgroundColorEnd' => '',
            'backgroundColorStart' => '',
            'backgroundGradientDirection' => 'horizontal',
            'backgroundMode' => 'color',
            'background_image' => '',
            'borderColor' => '',
            'borderEnabled' => true,
            'borderStyle' => 'solid',
            'borderWidth' => 1,
            'marginBottom' => 0,
            'marginLeft' => 0,
            'marginRight' => 0,
            'marginTop' => 0,
            'padding' => 0,
            'paddingX' => 10,
            'paddingY' => 0,
            'radius' => 20,
            'shadowEnabled' => false,
            'shadowBlur' => 30,
            'shadowColor' => '#0f172a',
            'shadowOffsetX' => 0,
            'shadowOffsetY' => 12,
            'shadowOpacity' => 14,
            'shadowSpread' => 0,
            'widthPercent' => 100,
        ];
        $map = [
            'orderEntry' => array_merge($base, ['paddingX' => 28, 'paddingY' => 28]),
            'orderShortcut' => array_merge($base, ['paddingX' => 28, 'paddingY' => 28]),
            'profileHeader' => array_merge($base, ['paddingX' => 28, 'paddingY' => 28, 'radius' => 0]),
            'serviceMenu' => $base,
            'userCard' => array_merge($base, ['paddingX' => 28, 'paddingY' => 28, 'radius' => 0]),
            'userInfo' => array_merge($base, ['paddingX' => 28, 'paddingY' => 28, 'radius' => 0]),
            'wallet' => array_merge($base, ['paddingX' => 28, 'paddingY' => 28]),
            'walletCard' => array_merge($base, ['paddingX' => 28, 'paddingY' => 28]),
            'walletEntry' => array_merge($base, ['paddingX' => 28, 'paddingY' => 28]),
        ];

        return $map[$type] ?? [];
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    protected function normalizeProfileStyleAliases(array $props): array
    {
        $aliases = [
            'backgroundColorEnd' => 'background_color_end',
            'backgroundColorStart' => 'background_color_start',
            'backgroundGradientDirection' => 'background_gradient_direction',
            'backgroundMode' => 'background_mode',
            'borderColor' => 'border_color',
            'borderEnabled' => 'border_enabled',
            'borderStyle' => 'border_style',
            'borderWidth' => 'border_width',
            'bottomBackground' => 'bottom_background',
            'componentBackgroundEnd' => 'component_background_end',
            'componentBackgroundStart' => 'component_background_start',
            'marginBottom' => 'margin_bottom',
            'marginLeft' => 'margin_left',
            'marginRight' => 'margin_right',
            'marginTop' => 'margin_top',
            'paddingBottom' => 'padding_bottom',
            'paddingLeft' => 'padding_left',
            'paddingRight' => 'padding_right',
            'paddingTop' => 'padding_top',
            'paddingX' => 'padding_x',
            'paddingY' => 'padding_y',
            'radius' => 'border_radius',
            'shadowBlur' => 'shadow_blur',
            'shadowColor' => 'shadow_color',
            'shadowEnabled' => 'shadow_enabled',
            'shadowOffsetX' => 'shadow_offset_x',
            'shadowOffsetY' => 'shadow_offset_y',
            'shadowOpacity' => 'shadow_opacity',
            'shadowSpread' => 'shadow_spread',
            'textColor' => 'text_color',
            'textVisibility' => 'text_visibility',
            'widthPercent' => 'width_percent',
        ];

        foreach ($aliases as $target => $source) {
            if (array_key_exists($source, $props)) {
                $props[$target] = $props[$source];
            }
        }

        return $props;
    }

    /**
     * @return array<string, false>
     */
    protected function normalizeProfileTextVisibility(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $roles = ['action', 'amount', 'itemLabel', 'meta', 'more', 'primaryAction', 'subtitle', 'title'];
        $visibility = [];
        foreach ($roles as $role) {
            if (!array_key_exists($role, $value)) {
                continue;
            }
            $rawValue = $value[$role];
            $hidden = $rawValue === false || $rawValue === 0 || $rawValue === '0';
            if (is_string($rawValue) && strtolower(trim($rawValue)) === 'false') {
                $hidden = true;
            }
            if ($hidden) {
                $visibility[$role] = false;
            }
        }

        return $visibility;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, mixed>
     */
    protected function normalizeBannerItems(array $items): array
    {
        $normalized = [];
        foreach (array_values($items) as $index => $item) {
            if (is_string($item)) {
                $normalized[] = $this->isLegacySvgImage($item) || $this->isLegacyDefaultBannerImage($item)
                    ? [
                        'image' => $this->defaultBannerImageByIndex($index),
                        'path' => '',
                        'title' => '轮播图' . ($index + 1),
                    ]
                    : $item;
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $image = $item['image'] ?? $item['url'] ?? '';
            if ($this->isLegacySvgImage($image) || $this->isLegacyDefaultBannerImage($image)) {
                $item['image'] = $this->defaultBannerImageByIndex($index);
            }
            $normalized[] = $item;
        }

        return $normalized;
    }

    protected function defaultBannerImageByIndex(int $index): string
    {
        $ids = ['48', '49', '50'];
        return $ids[$index % count($ids)];
    }

    /**
     * @return array<int, array{icon:string,image:string,path:string,title:string}>
     */
    protected function defaultNavItems(): array
    {
        return [
            [
                'icon' => 'lucide:smartphone',
                'image' => '51',
                'path' => '/pages/category/index',
                'title' => '数码',
            ],
            [
                'icon' => 'lucide:sparkles',
                'image' => '52',
                'path' => '/pages/category/index',
                'title' => '美妆',
            ],
            [
                'icon' => 'lucide:shirt',
                'image' => '53',
                'path' => '/pages/category/index',
                'title' => '服饰',
            ],
            [
                'icon' => 'lucide:sofa',
                'image' => '54',
                'path' => '/pages/category/index',
                'title' => '家居',
            ],
            [
                'icon' => 'lucide:utensils',
                'image' => '55',
                'path' => '/pages/category/index',
                'title' => '美食',
            ],
            [
                'icon' => 'lucide:dumbbell',
                'image' => '56',
                'path' => '/pages/category/index',
                'title' => '运动',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function defaultNavImageByItem(array $item, string $fallback): string
    {
        $key = (string) ($item['icon'] ?? $item['key'] ?? '');
        $key = str_replace('lucide:', '', $key);
        $title = (string) ($item['title'] ?? $item['label'] ?? $item['text'] ?? '');

        if (str_contains($key, 'sparkles') || str_contains($key, 'beauty') || $title === '美妆') {
            return '52';
        }
        if (str_contains($key, 'shirt') || str_contains($key, 'clothes') || str_contains($key, 'menswear') || $title === '服饰') {
            return '53';
        }
        if (str_contains($key, 'sofa') || str_contains($key, 'home') || str_contains($key, 'furniture') || $title === '家居') {
            return '54';
        }
        if (str_contains($key, 'utensils') || str_contains($key, 'food') || $title === '美食') {
            return '55';
        }
        if (str_contains($key, 'dumbbell') || str_contains($key, 'sport') || $title === '运动') {
            return '56';
        }
        if (str_contains($key, 'smartphone') || str_contains($key, 'phone') || $title === '数码') {
            return '51';
        }

        return $fallback;
    }

    protected function isLegacySvgImage(mixed $image): bool
    {
        return is_string($image) && str_starts_with($image, 'data:image/svg');
    }

    protected function isLegacyDefaultBannerImage(mixed $image): bool
    {
        $id = is_array($image) ? (string) ($image['url'] ?? $image['asset_id'] ?? '') : (string) $image;
        return in_array($id, ['6', '7', '8', '41'], true);
    }

    protected function isLegacyDefaultNavImage(mixed $image): bool
    {
        $id = is_array($image) ? (string) ($image['url'] ?? $image['asset_id'] ?? '') : (string) $image;
        return in_array($id, ['15', '16', '20', '23', '40', '46', '47'], true);
    }

    protected function stripRuntimePreviewFields(array $value): array
    {
        unset($value['preview_goods'], $value['previewGoods']);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->stripRuntimePreviewFields($item);
            }
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $themes
     */
    protected function firstThemeTokens(array $themes, string $type): array
    {
        foreach ($themes as $theme) {
            if (($theme['type'] ?? '') !== $type) {
                continue;
            }

            return $this->normalizeJsonValue($theme['tokens'] ?? []);
        }

        return [];
    }

    protected function fallbackScheme(string $type): array
    {
        $schema = match ($type) {
            ClientDecorationScheme::TYPE_FLOATING => $this->defaultFloatingSchema(),
            ClientDecorationScheme::TYPE_PROFILE => [
                'pageStyle' => $this->defaultProfilePageStyle(),
                'modules' => [
                    ['id' => 'profile-user', 'type' => 'userInfo', 'props' => array_merge($this->defaultProfileStyleProps('userInfo'), ['show_mobile' => true])],
                    ['id' => 'profile-order', 'type' => 'orderEntry', 'props' => array_merge($this->defaultProfileStyleProps('orderEntry'), [
                        'title' => '我的订单',
                        'display' => 'grid',
                        'items' => $this->defaultProfileOrderItems(),
                    ])],
                    ['id' => 'profile-wallet', 'type' => 'walletEntry', 'props' => array_merge($this->defaultProfileStyleProps('walletEntry'), ['title' => '我的余额', 'show_balance' => true, 'show_records' => true, 'show_view_button' => true])],
                    ['id' => 'profile-service', 'type' => 'serviceMenu', 'props' => array_merge($this->defaultProfileStyleProps('serviceMenu'), [
                        'title' => '我的服务',
                        'columns' => 4,
                        'display' => 'list',
                        'items' => $this->defaultProfileServiceItems(),
                    ])],
                ],
            ],
            ClientDecorationScheme::TYPE_TABBAR => [
                'items' => [
                    ['text' => '首页', 'path' => '/pages/index/index'],
                    ['text' => '分类', 'path' => '/pages/category/index'],
                    ['text' => '购物车', 'path' => '/pages/cart/index'],
                    ['text' => '订单', 'path' => '/pages/order/index'],
                    ['text' => '我的', 'path' => '/pages/profile/index'],
                ],
            ],
            default => [
                'pageStyle' => $this->defaultHomePageStyle(),
                'components' => [
                    ['id' => 'home-search', 'type' => 'search', 'props' => ['placeholder' => '搜索你心仪的商品...']],
                    ['id' => 'home-products', 'type' => 'productGroup', 'props' => ['title' => '猜你喜欢', 'source' => ['mode' => 'filter', 'filters' => ['is_recommend' => 1]], 'layout' => 'grid']],
                ],
                'modules' => [
                    ['id' => 'home-search', 'type' => 'search', 'props' => ['placeholder' => '搜索你心仪的商品...']],
                    ['id' => 'home-products', 'type' => 'productGroup', 'props' => ['title' => '猜你喜欢', 'source' => ['mode' => 'filter', 'filters' => ['is_recommend' => 1]], 'layout' => 'grid']],
                ],
            ],
        };

        return [
            'id' => 0,
            'type' => $type,
            'name' => '本地默认方案',
            'schema' => $schema,
            'tabbar_mode' => ClientDecorationScheme::TABBAR_MODE_NATIVE,
        ];
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
                    'colorPrimaryLight' => '#386bef',
                    'colorBg' => '#ffffff',
                    'colorBgSecondary' => '#faf8ff',
                    'colorBgSurface' => '#f3f3fe',
                    'colorText' => '#191b23',
                    'colorTextSecondary' => '#434654',
                    'colorTextTertiary' => '#737686',
                    'colorBorder' => '#e0e4e8',
                    'colorDivider' => '#f0f2f5',
                    'colorPrice' => '#ff5a1f',
                    'colorError' => '#ba1a1a',
                    'colorSuccess' => '#34c759',
                    'colorWarning' => '#f0ad4e',
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
                    'colorPrimaryLight' => '#6f97ff',
                    'colorBg' => '#10131a',
                    'colorBgSecondary' => '#151923',
                    'colorBgSurface' => '#1b202a',
                    'colorText' => '#f2f5fa',
                    'colorTextSecondary' => '#c9d1df',
                    'colorTextTertiary' => '#9aa4b5',
                    'colorBorder' => '#303746',
                    'colorDivider' => '#262c38',
                    'colorPrice' => '#ff7a45',
                    'colorError' => '#ff6b6b',
                    'colorSuccess' => '#4ade80',
                    'colorWarning' => '#fbbf24',
                ],
                'is_system' => 1,
                'status' => ClientTheme::STATUS_PUBLISHED,
            ],
        ];
    }
}
