<?php
declare(strict_types=1);

namespace app\service\client;

use app\model\client\ClientDecorationScheme;
use app\model\client\ClientTheme;
use app\model\client\ClientThemePolicy;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;

/**
 * 客户端装修读取服务
 * @extends BaseService<ClientDecorationScheme>
 */
class DecorationService extends BaseService
{
    protected string $modelClass = ClientDecorationScheme::class;

    /**
     * 获取客户端装修总配置。
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $home = $this->getActiveOrSystemScheme(ClientDecorationScheme::TYPE_HOME);
        $profile = $this->getActiveOrSystemScheme(ClientDecorationScheme::TYPE_PROFILE);
        $tabbar = $this->getActiveOrSystemScheme(ClientDecorationScheme::TYPE_TABBAR);

        return [
            'home' => $this->normalizeClientSchema(ClientDecorationScheme::TYPE_HOME, $home['schema']),
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
     * @return array{policy: array<string, mixed>, themes: array<int, array<string, mixed>>}
     */
    public function themes(): array
    {
        $policy = $this->model(ClientThemePolicy::class)->find(ClientThemePolicy::POLICY_ID);
        $policy = $policy ? $policy->toArray() : [
            'id' => ClientThemePolicy::POLICY_ID,
            'allow_user_select' => 1,
            'default_mode' => ClientThemePolicy::MODE_SYSTEM,
            'default_theme_id' => null,
        ];

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

        return compact('policy', 'themes', 'tokens');
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
            if (!isset($schema['pageStyle']) || !is_array($schema['pageStyle'])) {
                $schema['pageStyle'] = ['paddingY' => 0, 'paddingX' => 28];
            }
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
        }

        if ($type === ClientDecorationScheme::TYPE_TABBAR && $isList) {
            $schema = ['items' => $schema];
        }

        return $schema;
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
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    protected function applyDefaultClientProps(string $type, array $props): array
    {
        $props = array_merge($this->defaultProfileStyleProps($type), $props);
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

        if (in_array($type, ['customMenu', 'serviceMenu'], true)) {
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
            ['title' => '我的收藏', 'label' => '我的收藏', 'image' => 'static/demo/profile-service-favorite.svg', 'path' => ''],
            ['title' => '主题设置', 'label' => '主题设置', 'image' => 'static/demo/profile-service-settings.svg', 'path' => '/pages-sub/user/settings'],
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
                'path' => (string) ($item['path'] ?? $item['url'] ?? $item['link'] ?? ''),
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
                'static/demo/profile-service-favorite.svg',
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
            'backgroundColorEnd' => '#ffffff',
            'backgroundColorStart' => '#ffffff',
            'backgroundGradientDirection' => 'horizontal',
            'backgroundMode' => 'color',
            'background_image' => '',
            'borderColor' => '#e5e5e5',
            'borderEnabled' => true,
            'borderStyle' => 'dashed',
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
            'customMenu' => $base,
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
                'pageStyle' => ['paddingY' => 0, 'paddingX' => 28],
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
