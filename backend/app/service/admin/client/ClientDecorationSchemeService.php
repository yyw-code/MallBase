<?php
declare(strict_types=1);

namespace app\service\admin\client;

use app\model\client\ClientDecorationScheme;
use app\model\client\ClientDecorationSnapshot;
use app\service\admin\content\ArticleCategoryService;
use app\service\admin\content\ArticleService;
use app\model\goods\Goods;
use app\model\goods\GoodsBrand;
use app\model\goods\GoodsCategory;
use app\model\goods\GoodsTag;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 客户端装修方案服务
 * @extends BaseService<ClientDecorationScheme>
 */
class ClientDecorationSchemeService extends BaseService
{
    protected string $modelClass = ClientDecorationScheme::class;

    private const LEGACY_DECORATION_ASSET_MAP = [
        '48' => 'static/decorate/decorate-banner-market.png',
        '49' => 'static/decorate/decorate-banner-member.png',
        '50' => 'static/decorate/decorate-banner-home.png',
        '51' => 'static/decorate/decorate-nav-digital.png',
        '52' => 'static/decorate/decorate-nav-beauty.png',
        '53' => 'static/decorate/decorate-nav-fashion.png',
        '54' => 'static/decorate/decorate-nav-home.png',
        '55' => 'static/decorate/decorate-nav-food.png',
        '56' => 'static/decorate/decorate-nav-sport.png',
        '57' => 'static/decorate/decorate-cube-new.png',
        '58' => 'static/decorate/decorate-cube-picks.png',
        '59' => 'static/decorate/decorate-cube-member.png',
        '60' => 'static/decorate/decorate-cube-sale.png',
        '61' => 'static/decorate/decorate-entry-category.png',
    ];

    private const LEGACY_DECORATION_DEMO_FILE_MAP = [
        'decorate-banner-market.png' => 'static/decorate/decorate-banner-market.png',
        'decorate-banner-member.png' => 'static/decorate/decorate-banner-member.png',
        'decorate-banner-home.png' => 'static/decorate/decorate-banner-home.png',
        'decorate-nav-digital.png' => 'static/decorate/decorate-nav-digital.png',
        'decorate-nav-beauty.png' => 'static/decorate/decorate-nav-beauty.png',
        'decorate-nav-fashion.png' => 'static/decorate/decorate-nav-fashion.png',
        'decorate-nav-home.png' => 'static/decorate/decorate-nav-home.png',
        'decorate-nav-food.png' => 'static/decorate/decorate-nav-food.png',
        'decorate-nav-sport.png' => 'static/decorate/decorate-nav-sport.png',
        'decorate-cube-new.png' => 'static/decorate/decorate-cube-new.png',
        'decorate-cube-picks.png' => 'static/decorate/decorate-cube-picks.png',
        'decorate-cube-member.png' => 'static/decorate/decorate-cube-member.png',
        'decorate-cube-sale.png' => 'static/decorate/decorate-cube-sale.png',
        'decorate-entry-category.png' => 'static/decorate/decorate-entry-category.png',
        'profile-order-pay.svg' => 'static/decorate/profile-order-pay.svg',
        'profile-order-ship.svg' => 'static/decorate/profile-order-ship.svg',
        'profile-order-receive.svg' => 'static/decorate/profile-order-receive.svg',
        'profile-order-refund.svg' => 'static/decorate/profile-order-refund.svg',
        'profile-service-address.svg' => 'static/decorate/profile-service-address.svg',
        'profile-service-settings.svg' => 'static/decorate/profile-service-settings.svg',
        'profile-service-support.svg' => 'static/decorate/profile-service-support.svg',
    ];

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->whereNull('delete_time')
            ->when(($where['type'] ?? null) !== null && $where['type'] !== '', function ($q) use ($where) {
                $q->where('type', $where['type']);
            })
            ->when(($where['keyword'] ?? null) !== null && $where['keyword'] !== '', function ($q) use ($where) {
                $keyword = trim((string) $where['keyword']);
                $q->whereLike('name|description', '%' . $keyword . '%');
            })
            ->when(($where['is_active'] ?? null) !== null && $where['is_active'] !== '', function ($q) use ($where) {
                $q->where('is_active', (int) $where['is_active']);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
    }

    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $list = $this->hydrateSchemeListSchemaAssets($list);

        $total = $this->buildListQuery($where)->count();

        return compact('total', 'list');
    }

    public function getInfo(int $id): array
    {
        $scheme = $this->findValidScheme($id);
        $data = $scheme->toArray();
        $data['schema'] = $this->hydrateSchemeSchemaAssets(
            (string) ($data['type'] ?? ''),
            $this->normalizeJsonValue($data['schema'] ?? [])
        );

        return $data;
    }

    /**
     * 首页装修商品来源选择数据。
     *
     * 权限挂在客户端装修接口下，避免页面装修人员必须额外拥有商品管理菜单权限。
     *
     * @return array{goods: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>, brands: array<int, array<string, mixed>>, tags: array<int, array<string, mixed>>}
     */
    public function getProductSourcePicker(array $where): array
    {
        $keyword = trim((string) ($where['keyword'] ?? ''));

        return [
            'goods' => $this->getPickerGoods($keyword),
            'categories' => $this->getPickerCategories($keyword),
            'brands' => $this->getPickerBrands($keyword),
            'tags' => $this->getPickerTags($keyword),
        ];
    }

    /**
     * 装修跳转目标选择数据。
     *
     * 页面、商品详情和商品列表都收口到装修权限下，避免装修配置依赖多个后台菜单权限。
     *
     * @return array{
     *     pages: array{total:int, groups:array<int, array<string, mixed>>},
     *     goods: array<int, array<string, mixed>>,
     *     categories: array<int, array<string, mixed>>,
     *     brands: array<int, array<string, mixed>>,
     *     tags: array<int, array<string, mixed>>,
     *     sections: array<int, array<string, mixed>>
     * }
     */
    public function getTargetPicker(array $where): array
    {
        $productSources = $this->getProductSourcePicker($where);
        $pages = app()->make(ClientPageService::class)->getPickerGroups($where);

        return [
            'pages' => $pages,
            'sections' => [$this->getArticleTargetSection($where)],
            ...$productSources,
        ];
    }

    /**
     * @return array{key:string,label:string,count:int,groups:array<int, array<string, mixed>>}
     */
    protected function getArticleTargetSection(array $where): array
    {
        $articleListPath = '/pages-sub/article/list';
        $articleDetailPath = '/pages-sub/article/detail';
        $keyword = trim((string) ($where['keyword'] ?? ''));
        $articles = app()->make(ArticleService::class)->getPickerArticles($where);
        $categories = app()->make(ArticleCategoryService::class)->getAllCategories();
        if ($keyword !== '') {
            $keywordLower = mb_strtolower($keyword);
            $categories = array_values(array_filter(
                $categories,
                static fn (array $item): bool => str_contains(mb_strtolower((string) ($item['name'] ?? '')), $keywordLower)
                    || str_contains(mb_strtolower((string) ($item['description'] ?? '')), $keywordLower)
            ));
        }

        $groups = [
            [
                'key' => 'article:system',
                'label' => '系统列表',
                'count' => 1,
                'items' => [
                    [
                        'key' => 'article-all',
                        'title' => '全部文章',
                        'desc' => '默认排序',
                        'path' => $articleListPath,
                    ],
                ],
            ],
            [
                'key' => 'article:category',
                'label' => '分类列表',
                'count' => count($categories),
                'items' => array_map(static fn (array $item): array => [
                    'key' => 'article-category-' . (int) ($item['id'] ?? 0),
                    'title' => (string) ($item['name'] ?? ''),
                    'desc' => 'ID ' . (int) ($item['id'] ?? 0),
                    'path' => $articleListPath . '?category_id=' . (int) ($item['id'] ?? 0),
                ], $categories),
            ],
            [
                'key' => 'article:detail',
                'label' => '文章详情',
                'count' => count($articles),
                'items' => array_map(static fn (array $item): array => [
                    'key' => 'article-' . (int) ($item['id'] ?? 0),
                    'title' => (string) ($item['title'] ?? ''),
                    'desc' => implode(' · ', array_values(array_filter([
                        (string) ($item['category_name'] ?? ''),
                        '阅读 ' . (int) ($item['read_count'] ?? 0),
                    ]))),
                    'image' => (string) ($item['cover_full_url'] ?? ''),
                    'path' => $articleDetailPath . '?id=' . (int) ($item['id'] ?? 0),
                    'tags' => array_values(array_filter([(string) ($item['category_name'] ?? '')])),
                ], $articles),
            ],
        ];

        return [
            'key' => 'article',
            'label' => '文章',
            'count' => array_sum(array_map(static fn (array $group): int => (int) ($group['count'] ?? 0), $groups)),
            'groups' => $groups,
        ];
    }

    /**
     * 批量收敛历史个人中心 customMenu 数据。
     *
     * @return array{scanned:int, updated:int, skipped:int}
     */
    public function migrateLegacyProfileCustomMenu(bool $dryRun = true): array
    {
        $schemes = $this->model()
            ->where('type', ClientDecorationScheme::TYPE_PROFILE)
            ->whereRaw('CAST(`schema` AS CHAR) LIKE ?', ['%customMenu%'])
            ->whereNull('delete_time')
            ->select();

        $scanned = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($schemes as $scheme) {
            $scanned++;
            $schema = $this->normalizeSchemaByType(
                ClientDecorationScheme::TYPE_PROFILE,
                $scheme->schema ?? []
            );
            $encoded = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false && str_contains($encoded, 'customMenu')) {
                $skipped++;
                continue;
            }

            if (!$dryRun) {
                $scheme->save(['schema' => $schema]);
            }
            $updated++;
        }

        return compact('scanned', 'updated', 'skipped');
    }

    public function create(array $data): int
    {
        $payload = $this->normalizePayload($data);
        $this->validateSchemaByType($payload['type'], $payload['schema'], (string) $payload['tabbar_mode']);

        $scheme = $this->model()->create($payload);

        return (int) $scheme->id;
    }

    public function update(int $id, array $data): bool
    {
        $scheme = $this->findValidScheme($id);
        if ((int) $scheme->is_system === 1) {
            throw new BusinessException('系统默认方案不能修改');
        }

        $payload = $this->normalizePayload($data, $scheme->toArray());
        $this->validateSchemaByType($payload['type'], $payload['schema'], (string) $payload['tabbar_mode']);

        unset($payload['is_system'], $payload['is_active']);
        $scheme->save($payload);

        return true;
    }

    public function copy(int $id): int
    {
        $scheme = $this->findValidScheme($id);
        $data = $scheme->toArray();
        unset($data['id'], $data['create_time'], $data['update_time'], $data['delete_time']);
        $data['name'] = mb_substr((string) $data['name'] . '-副本', 0, 80);
        $data['is_system'] = 0;
        $data['is_active'] = 0;
        $data['status'] = 1;
        $data['schema'] = $this->normalizeSchemaByType((string) $data['type'], $data['schema'] ?? []);

        $copy = $this->model()->create($data);

        return (int) $copy->id;
    }

    public function activate(int $id): bool
    {
        $scheme = $this->findValidScheme($id);
        if ((int) $scheme->status !== 1) {
            throw new BusinessException('禁用方案不能启用');
        }

        $schema = $this->normalizeSchemaByType((string) $scheme->type, $scheme->schema);
        $this->validateSchemaByType((string) $scheme->type, $schema, (string) $scheme->tabbar_mode, true);

        return $this->transaction(function () use ($scheme, $schema) {
            $this->model()
                ->where('type', (string) $scheme->type)
                ->whereNull('delete_time')
                ->update(['is_active' => 0]);

            $scheme->save(['is_active' => 1]);

            $this->model(ClientDecorationSnapshot::class)->create([
                'scheme_id' => (int) $scheme->id,
                'type' => (string) $scheme->type,
                'name' => (string) $scheme->name,
                'schema' => $schema,
                'tabbar_mode' => (string) $scheme->tabbar_mode,
            ]);

            return true;
        });
    }

    public function delete(int $id): bool
    {
        $scheme = $this->findValidScheme($id);
        if ((int) $scheme->is_system === 1) {
            throw new BusinessException('系统默认方案不能删除');
        }
        if ((int) $scheme->is_active === 1) {
            throw new BusinessException('当前启用方案不能删除');
        }

        $scheme->save([
            'status' => 0,
            'delete_time' => time(),
        ]);

        return true;
    }

    public function getActiveOrSystem(string $type): array
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

        return $data;
    }

    protected function findValidScheme(int $id)
    {
        $scheme = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$scheme) {
            throw new BusinessException('装修方案不存在');
        }

        return $scheme;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getPickerGoods(string $keyword): array
    {
        $list = $this->model(Goods::class)
            ->where('status', 1)
            ->where('is_on_sale', 1)
            ->whereNull('delete_time')
            ->when($keyword !== '', function ($q) use ($keyword) {
                $q->whereLike('name|subtitle', '%' . $keyword . '%');
            })
            ->field('id,category_id,brand_id,name,subtitle,main_image,images,price,market_price,sales,is_recommend,is_new,is_hot,sort')
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->limit(50)
            ->select()
            ->toArray();

        if ($list === []) {
            return [];
        }

        $categoryIds = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'category_id')))));
        $brandIds = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'brand_id')))));
        $categories = $categoryIds !== []
            ? $this->model(GoodsCategory::class)->whereIn('id', $categoryIds)->column('name', 'id')
            : [];
        $brands = $brandIds !== []
            ? $this->model(GoodsBrand::class)->whereIn('id', $brandIds)->column('name', 'id')
            : [];

        foreach ($list as &$item) {
            if (empty($item['main_image']) && !empty($item['images'])) {
                $firstImageValue = app()->make(AssetHydrator::class)->firstImageValue($item['images']);
                if ($firstImageValue !== '') {
                    $item['main_image'] = $firstImageValue;
                }
            }
            $item['category_name'] = $categories[(int) ($item['category_id'] ?? 0)] ?? '';
            $item['brand_name'] = $brands[(int) ($item['brand_id'] ?? 0)] ?? '';
        }
        unset($item);

        return app()->make(AssetHydrator::class)->hydrateGoodsList($list);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getPickerCategories(string $keyword): array
    {
        return $this->model(GoodsCategory::class)
            ->where('status', 1)
            ->when($keyword !== '', function ($q) use ($keyword) {
                $q->whereLike('name', '%' . $keyword . '%');
            })
            ->field('id,pid,name,icon,image,sort,status')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getPickerBrands(string $keyword): array
    {
        return $this->model(GoodsBrand::class)
            ->where('status', 1)
            ->when($keyword !== '', function ($q) use ($keyword) {
                $q->whereLike('name', '%' . $keyword . '%');
            })
            ->field('id,name,logo,sort,status')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getPickerTags(string $keyword): array
    {
        return $this->model(GoodsTag::class)
            ->where('status', 1)
            ->when($keyword !== '', function ($q) use ($keyword) {
                $q->whereLike('name', '%' . $keyword . '%');
            })
            ->field('id,name,color,sort,status')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    protected function normalizePayload(array $data, array $base = []): array
    {
        $type = (string) ($data['type'] ?? $base['type'] ?? ClientDecorationScheme::TYPE_HOME);
        if (!in_array($type, ClientDecorationScheme::validTypes(), true)) {
            throw new BusinessException('方案类型不正确');
        }

        $schema = $this->normalizeSchemaByType(
            $type,
            $data['schema'] ?? $base['schema'] ?? $this->blankSchema($type)
        );
        $payload = [
            'type' => $type,
            'name' => trim((string) ($data['name'] ?? $base['name'] ?? '')),
            'description' => trim((string) ($data['description'] ?? $base['description'] ?? '')) ?: null,
            'schema' => $schema,
            'tabbar_mode' => (string) ($data['tabbar_mode'] ?? $base['tabbar_mode'] ?? ClientDecorationScheme::TABBAR_MODE_NATIVE),
            'is_system' => 0,
            'is_active' => 0,
            'sort' => (int) ($data['sort'] ?? $base['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? $base['status'] ?? 1),
        ];

        if ($payload['name'] === '') {
            throw new BusinessException('方案名称不能为空');
        }

        return $payload;
    }

    protected function blankSchema(string $type): array
    {
        return match ($type) {
            ClientDecorationScheme::TYPE_FLOATING => $this->defaultFloatingSchema(),
            ClientDecorationScheme::TYPE_PROFILE => [
                'modules' => [],
                'pageStyle' => $this->defaultProfilePageStyle(),
            ],
            ClientDecorationScheme::TYPE_TABBAR => ['items' => []],
            default => ['components' => [], 'modules' => [], 'pageStyle' => $this->defaultHomePageStyle()],
        };
    }

    protected function normalizeSchemaByType(string $type, $schema): array
    {
        $schema = $this->normalizeJsonValue($schema);
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
                ['enabled' => true, 'icon' => 'static/decorate/floating/service.png', 'id' => 'floating-service', 'text' => '客服', 'type' => 'customerService'],
                ['enabled' => true, 'icon' => 'static/decorate/floating/cart.png', 'id' => 'floating-cart', 'path' => '/pages/cart/index', 'text' => '购物车', 'type' => 'page'],
                ['enabled' => true, 'icon' => 'static/decorate/floating/home.png', 'id' => 'floating-home', 'path' => '/pages/index/index', 'text' => '首页', 'type' => 'page'],
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
            $props = $this->normalizeModuleStyleAliases($props);

            $type = (string) ($item['type'] ?? $item['component'] ?? '');
            if ($type === 'banner') {
                $props = $this->normalizeBannerProps($props);
            }
            if ($type === 'navGrid') {
                $props = $this->normalizeNavGridProps($props);
            }
            if ($type === 'imageCube') {
                $props = $this->normalizeImageCubeProps($props);
            }
            if ($type === 'entryCard') {
                $props = $this->normalizeEntryCardProps($props);
            }
            if (in_array($type, ['orderEntry', 'orderShortcut', 'serviceMenu', 'customMenu'], true)) {
                $props = $this->normalizeProfileEntryProps($props);
            }

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
     * 将历史 customMenu 收敛到 serviceMenu，避免保存结果继续生产重复入口组件。
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

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    protected function normalizeBannerProps(array $props): array
    {
        $items = $props['items'] ?? $props['list'] ?? $props['images'] ?? [];
        if (!is_array($items)) {
            return $props;
        }

        $normalized = [];
        foreach (array_values($items) as $index => $item) {
            if (is_string($item)) {
                $image = $this->normalizeLegacyDecorationAssetValue($item);
                if ($image !== $item) {
                    $normalized[] = [
                        'image' => $image,
                        'path' => '',
                        'title' => '轮播图' . ($index + 1),
                    ];
                    continue;
                }
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
            $originalImage = $image;
            $image = $this->normalizeLegacyDecorationAssetValue($image);
            if ($this->isLegacySvgImage($image) || $this->isLegacyDefaultBannerImage($image)) {
                $item['image'] = $this->defaultBannerImageByIndex($index);
            } elseif ($image !== $originalImage) {
                $item['image'] = $image;
            }
            $normalized[] = $item;
        }

        $props['items'] = $normalized;
        $props['list'] = $normalized;
        $props['images'] = $normalized;

        return $props;
    }

    protected function defaultBannerImageByIndex(int $index): string
    {
        $ids = ['1001', '1002', '1003'];
        return $ids[$index % count($ids)];
    }

    protected function defaultCubeImageByIndex(int $index): string
    {
        $ids = ['1010', '1011', '1012', '1013'];
        return $ids[$index % count($ids)];
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    protected function normalizeNavGridProps(array $props): array
    {
        $items = $props['items'] ?? [];
        if (!is_array($items)) {
            return $props;
        }

        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $image = $item['image']
                ?? $item['image_url']
                ?? $item['imageUrl']
                ?? $item['full_url']
                ?? $item['fullUrl']
                ?? '';
            $image = $this->normalizeLegacyDecorationAssetValue($image);
            if ($image === '' || $image === null || $this->isLegacySvgImage($image) || $this->isLegacyDefaultNavImage($image)) {
                $item['image'] = $this->defaultNavImageByItem($item, '');
            } else {
                $item['image'] = $image;
            }
        }
        unset($item);

        $props['items'] = $items;

        return $props;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    protected function normalizeImageCubeProps(array $props): array
    {
        $items = $props['items'] ?? $props['images'] ?? $props['list'] ?? [];
        if (!is_array($items)) {
            return $props;
        }

        $normalized = [];
        foreach (array_values($items) as $index => $item) {
            if (is_string($item)) {
                $image = $this->normalizeLegacyDecorationAssetValue($item);
                if ($image !== $item) {
                    $normalized[] = [
                        'image' => $image,
                        'path' => '',
                        'title' => '图片' . ($index + 1),
                    ];
                    continue;
                }
                $normalized[] = $item;
                continue;
            }
            if (!is_array($item)) {
                continue;
            }

            $originalImage = $item['image']
                ?? $item['url']
                ?? $item['image_url']
                ?? $item['imageUrl']
                ?? $item['full_url']
                ?? $item['fullUrl']
                ?? '';
            $image = $this->normalizeLegacyDecorationAssetValue($originalImage);
            if ($this->isLegacySvgImage($image)) {
                $item['image'] = $this->defaultCubeImageByIndex($index);
            } elseif ($image !== $originalImage) {
                $item['image'] = $image;
            }

            $normalized[] = $item;
        }

        $props['items'] = $normalized;
        $props['images'] = $normalized;
        $props['list'] = $normalized;

        return $props;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    protected function normalizeEntryCardProps(array $props): array
    {
        foreach (['icon_image', 'iconImage', 'background_image', 'backgroundImage'] as $field) {
            if (array_key_exists($field, $props)) {
                $props[$field] = $this->normalizeLegacyDecorationAssetValue($props[$field]);
            }
        }

        return $props;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    protected function normalizeProfileEntryProps(array $props): array
    {
        foreach (['items', 'list'] as $listField) {
            if (!isset($props[$listField]) || !is_array($props[$listField])) {
                continue;
            }
            $items = [];
            foreach ($props[$listField] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach (['image', 'image_url', 'imageUrl', 'icon_image', 'iconImage', 'full_url', 'fullUrl'] as $field) {
                    if (array_key_exists($field, $item)) {
                        $item[$field] = $this->normalizeLegacyDecorationAssetValue($item[$field]);
                    }
                }
                $items[] = $item;
            }
            $props[$listField] = $items;
        }

        return $props;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    protected function normalizeModuleStyleAliases(array $props): array
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
     * @param array<string, mixed> $item
     */
    protected function defaultNavImageByItem(array $item, string $fallback): string
    {
        $key = (string) ($item['icon'] ?? $item['key'] ?? '');
        $key = str_replace('lucide:', '', $key);
        $title = (string) ($item['title'] ?? $item['label'] ?? $item['text'] ?? '');

        if (str_contains($key, 'sparkles') || str_contains($key, 'beauty') || $title === '美妆') {
            return '1005';
        }
        if (str_contains($key, 'shirt') || str_contains($key, 'clothes') || str_contains($key, 'menswear') || $title === '服饰') {
            return '1006';
        }
        if (str_contains($key, 'sofa') || str_contains($key, 'home') || str_contains($key, 'furniture') || $title === '家居') {
            return '1007';
        }
        if (str_contains($key, 'utensils') || str_contains($key, 'food') || $title === '美食') {
            return '1008';
        }
        if (str_contains($key, 'dumbbell') || str_contains($key, 'sport') || $title === '运动') {
            return '1009';
        }
        if (str_contains($key, 'smartphone') || str_contains($key, 'phone') || $title === '数码') {
            return '1004';
        }

        return $fallback;
    }

    protected function normalizeLegacyDecorationAssetValue(mixed $value): mixed
    {
        foreach ($this->legacyDecorationAssetCandidates($value) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            if (isset(self::LEGACY_DECORATION_ASSET_MAP[$candidate])) {
                return self::LEGACY_DECORATION_ASSET_MAP[$candidate];
            }

            $path = str_replace('\\', '/', $candidate);
            foreach (self::LEGACY_DECORATION_DEMO_FILE_MAP as $filename => $target) {
                if (str_contains($path, 'static/demo/' . $filename)) {
                    return $target;
                }
            }
        }

        return $value;
    }

    /**
     * @return array<int, mixed>
     */
    protected function legacyDecorationAssetCandidates(mixed $value): array
    {
        if (is_array($value)) {
            $candidates = [];
            foreach ([
                'url',
                'asset_id',
                'id',
                'path',
                'src',
                'image',
                'image_url',
                'imageUrl',
                'icon_image',
                'iconImage',
                'background_image',
                'backgroundImage',
                'full_url',
                'fullUrl',
                'preview_url',
                'previewUrl',
            ] as $field) {
                if (isset($value[$field]) && is_scalar($value[$field])) {
                    $candidates[] = $value[$field];
                }
            }

            return $candidates;
        }

        return is_scalar($value) ? [$value] : [];
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

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    protected function hydrateSchemeListSchemaAssets(array $list): array
    {
        foreach ($list as &$item) {
            $item['schema'] = $this->hydrateSchemeSchemaAssets(
                (string) ($item['type'] ?? ''),
                $this->normalizeJsonValue($item['schema'] ?? [])
            );
        }
        unset($item);

        return $list;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function hydrateSchemeSchemaAssets(string $type, array $schema): array
    {
        /** @var AssetHydrator $hydrator */
        $hydrator = app()->make(AssetHydrator::class);

        return $hydrator->hydrateDecorationSchema(
            $this->normalizeSchemaByType($type, $schema)
        );
    }

    protected function validateSchemaByType(string $type, array $schema, string $tabbarMode, bool $strict = false): void
    {
        $schema = $this->normalizeSchemaByType($type, $schema);

        if (!in_array($tabbarMode, ClientDecorationScheme::validTabbarModes(), true)) {
            throw new BusinessException('底部导航模式不正确');
        }

        if ($type === ClientDecorationScheme::TYPE_HOME) {
            if (!isset($schema['components']) || !is_array($schema['components'])) {
                throw new BusinessException('首页方案必须包含 components 数组');
            }
            return;
        }

        if ($type === ClientDecorationScheme::TYPE_PROFILE) {
            if (!isset($schema['modules']) || !is_array($schema['modules'])) {
                throw new BusinessException('个人中心方案必须包含 modules 数组');
            }
            return;
        }

        if ($type === ClientDecorationScheme::TYPE_FLOATING) {
            if (!isset($schema['items']) || !is_array($schema['items'])) {
                throw new BusinessException('悬浮按钮方案必须包含 items 数组');
            }
            foreach ($schema['items'] as $item) {
                if (!is_array($item)) {
                    throw new BusinessException('悬浮按钮入口格式不正确');
                }
                $text = trim((string) ($item['text'] ?? ''));
                $itemType = trim((string) ($item['type'] ?? ''));
                $path = trim((string) ($item['path'] ?? ''));
                if ($text === '' || !in_array($itemType, ['customerService', 'page'], true)) {
                    throw new BusinessException('悬浮按钮入口必须包含名称和类型');
                }
                if ($itemType === 'page' && ($path === '' || !str_starts_with($path, '/'))) {
                    throw new BusinessException('悬浮按钮页面入口路径必须以 / 开头');
                }
                if (!$this->isDefaultFloatingItem($item) && !$this->hasFloatingItemIcon($item['icon'] ?? '')) {
                    throw new BusinessException('自定义悬浮按钮入口请上传图标');
                }
            }
            return;
        }

        if ($type !== ClientDecorationScheme::TYPE_TABBAR) {
            throw new BusinessException('方案类型不正确');
        }

        if (!isset($schema['items']) || !is_array($schema['items'])) {
            throw new BusinessException('底部导航方案必须包含 items 数组');
        }

        if (!$strict && $schema['items'] === []) {
            return;
        }

        $count = count($schema['items']);
        if ($count < 2 || $count > 5) {
            throw new BusinessException('底部导航必须配置2到5个入口');
        }

        foreach ($schema['items'] as $index => $item) {
            if (!is_array($item)) {
                throw new BusinessException('底部导航入口格式不正确');
            }
            $text = trim((string) ($item['text'] ?? ''));
            $path = trim((string) ($item['path'] ?? ''));
            if ($text === '' || $path === '') {
                throw new BusinessException('底部导航入口必须包含名称和路径');
            }
            if (!str_starts_with($path, '/')) {
                throw new BusinessException('底部导航入口路径必须以 / 开头');
            }
            if ($index > 4) {
                break;
            }
        }
    }

    protected function isDefaultFloatingItem(array $item): bool
    {
        return $this->getDefaultFloatingPresetType($item) !== '';
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

    protected function fallbackScheme(string $type): array
    {
        $schema = $this->blankSchema($type);
        if ($type === ClientDecorationScheme::TYPE_TABBAR) {
            $schema = [
                'items' => [
                    ['text' => '首页', 'path' => '/pages/index/index'],
                    ['text' => '分类', 'path' => '/pages/category/index'],
                    ['text' => '购物车', 'path' => '/pages/cart/index'],
                    ['text' => '订单', 'path' => '/pages/order/index'],
                    ['text' => '我的', 'path' => '/pages/profile/index'],
                ],
            ];
        }

        return [
            'id' => 0,
            'type' => $type,
            'name' => '本地默认方案',
            'schema' => $schema,
            'tabbar_mode' => ClientDecorationScheme::TABBAR_MODE_NATIVE,
            'is_system' => 1,
            'is_active' => 1,
            'status' => 1,
        ];
    }
}
