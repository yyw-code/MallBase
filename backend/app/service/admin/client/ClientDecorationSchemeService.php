<?php
declare(strict_types=1);

namespace app\service\admin\client;

use app\model\client\ClientDecorationScheme;
use app\model\client\ClientDecorationSnapshot;
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
        $data['schema'] = $this->hydrateSchemeSchemaAssets($this->normalizeJsonValue($data['schema'] ?? []));

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
            'sections' => [],
            ...$productSources,
        ];
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
            ClientDecorationScheme::TYPE_PROFILE => ['modules' => []],
            ClientDecorationScheme::TYPE_TABBAR => ['items' => []],
            default => ['components' => [], 'modules' => [], 'pageStyle' => ['paddingY' => 0, 'paddingX' => 28]],
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
            if ($type === 'banner') {
                $props = $this->normalizeBannerProps($props);
            }
            if ($type === 'navGrid') {
                $props = $this->normalizeNavGridProps($props);
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

        $props['items'] = $normalized;
        $props['list'] = $normalized;
        $props['images'] = $normalized;

        return $props;
    }

    protected function defaultBannerImageByIndex(int $index): string
    {
        $ids = ['48', '49', '50'];
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
            if ($image === '' || $image === null || $this->isLegacySvgImage($image) || $this->isLegacyDefaultNavImage($image)) {
                $item['image'] = $this->defaultNavImageByItem($item, '');
            }
        }
        unset($item);

        $props['items'] = $items;

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
            $item['schema'] = $this->hydrateSchemeSchemaAssets($this->normalizeJsonValue($item['schema'] ?? []));
        }
        unset($item);

        return $list;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function hydrateSchemeSchemaAssets(array $schema): array
    {
        /** @var AssetHydrator $hydrator */
        $hydrator = app()->make(AssetHydrator::class);

        return $hydrator->hydrateDecorationSchema($schema);
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
