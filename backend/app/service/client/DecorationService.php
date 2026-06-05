<?php
declare(strict_types=1);

namespace app\service\client;

use app\model\client\ClientDecorationScheme;
use app\model\client\ClientTheme;
use app\model\client\ClientThemePolicy;
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
            'home' => $this->normalizeSchemaByType(ClientDecorationScheme::TYPE_HOME, $home['schema']),
            'profile' => $this->normalizeSchemaByType(ClientDecorationScheme::TYPE_PROFILE, $profile['schema']),
            'tabbar' => [
                'mode' => $tabbar['tabbar_mode'],
                'schema' => $this->normalizeSchemaByType(ClientDecorationScheme::TYPE_TABBAR, $tabbar['schema']),
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
            if (!isset($schema['components']) && isset($schema['modules']) && is_array($schema['modules'])) {
                $schema['components'] = $schema['modules'];
            }
            if (!isset($schema['modules']) && isset($schema['components']) && is_array($schema['components'])) {
                $schema['modules'] = $schema['components'];
            }
        }

        if ($type === ClientDecorationScheme::TYPE_PROFILE) {
            if ($isList) {
                $schema = ['modules' => $schema];
            }
            if (!isset($schema['modules']) && isset($schema['components']) && is_array($schema['components'])) {
                $schema['modules'] = $schema['components'];
            }
        }

        if ($type === ClientDecorationScheme::TYPE_TABBAR && $isList) {
            $schema = ['items' => $schema];
        }

        return $schema;
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
                'modules' => [
                    ['id' => 'profile-user', 'type' => 'userInfo', 'props' => []],
                    ['id' => 'profile-order', 'type' => 'orderEntry', 'props' => []],
                    ['id' => 'profile-service', 'type' => 'serviceMenu', 'props' => ['items' => []]],
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
