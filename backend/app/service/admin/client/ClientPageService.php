<?php
declare(strict_types=1);

namespace app\service\admin\client;

use app\model\client\ClientPage;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 客户端页面库服务
 * @extends BaseService<ClientPage>
 */
class ClientPageService extends BaseService
{
    protected string $modelClass = ClientPage::class;

    private const THEME_SETTING_PAGE_PATH = '/pages-sub/user/theme';
    private const CATEGORY_NAME_DISTRIBUTION = '分销页面';

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->whereNull('delete_time')
            ->when(($where['keyword'] ?? null) !== null && $where['keyword'] !== '', function ($q) use ($where) {
                $keyword = trim((string) $where['keyword']);
                $q->whereLike('name|path|remark', '%' . $keyword . '%');
            })
            ->when(($where['page_type'] ?? null) !== null && $where['page_type'] !== '', function ($q) use ($where) {
                $q->where('page_type', $where['page_type']);
            })
            ->when(($where['category_id'] ?? null) !== null && $where['category_id'] !== '', function ($q) use ($where) {
                $q->where('category_id', (int) $where['category_id']);
            })
            ->when(($where['source'] ?? null) !== null && $where['source'] !== '', function ($q) use ($where) {
                $q->where('source', $where['source']);
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
        $list = $this->withCategoryLabels($list, $this->categoryLabels());

        $total = $this->buildListQuery($where)->count();

        return compact('total', 'list');
    }

    public function getInfo(int $id): array
    {
        $page = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$page) {
            throw new BusinessException('页面不存在');
        }

        $info = $page->toArray();
        $labels = $this->categoryLabels();
        $categoryId = (int) ($info['category_id'] ?? 0);
        $info['category_label'] = $labels[$categoryId] ?? '';

        return $info;
    }

    /**
     * 获取页面链接选择器数据。
     *
     * @return array{total:int, groups:array<int, array{key:string, label:string, count:int, items:array<int, array<string, mixed>>}>}
     */
    public function getPickerGroups(array $where): array
    {
        $list = $this->buildListQuery(array_merge($where, ['status' => 1]))
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $list = array_values(array_filter(
            $list,
            fn (array $item): bool => $this->normalizePagePath((string) ($item['path'] ?? '')) !== self::THEME_SETTING_PAGE_PATH
        ));

        $categoryLabels = $this->categoryLabels();
        $groupMap = [];
        foreach ($categoryLabels as $id => $label) {
            $groupKey = (string) $id;
            $groupMap[$groupKey] = [
                'key' => $groupKey,
                'label' => $label,
                'count' => 0,
                'items' => [],
            ];
        }

        foreach ($list as $item) {
            $categoryId = (int) ($item['category_id'] ?? 0);
            $groupKey = (string) $categoryId;
            if (!isset($groupMap[$groupKey])) {
                $groupMap[$groupKey] = [
                    'key' => $groupKey,
                    'label' => $categoryLabels[$categoryId] ?? '未分类',
                    'count' => 0,
                    'items' => [],
                ];
            }
            $groupMap[$groupKey]['items'][] = $this->formatPickerItem($item, $categoryId, $categoryLabels);
            $groupMap[$groupKey]['count'] = count($groupMap[$groupKey]['items']);
        }

        return [
            'total' => count($list),
            'groups' => array_values(array_filter(
                $groupMap,
                static fn (array $group): bool => $group['count'] > 0
            )),
        ];
    }

    public function create(array $data): int
    {
        $data = $this->normalizePayload($data);
        $this->validateWritableSource($data['source']);
        $this->validateCategoryId($data['category_id']);
        $this->validatePath($data['path']);
        $this->validateUniquePath($data['path']);

        $page = $this->model()->create($data);

        return (int) $page->id;
    }

    public function update(int $id, array $data): bool
    {
        $page = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$page) {
            throw new BusinessException('页面不存在');
        }
        if ((string) $page->source === ClientPage::SOURCE_SYSTEM) {
            throw new BusinessException('系统页面不能修改');
        }

        $data = $this->normalizePayload($data);
        $this->validateWritableSource($data['source']);
        $this->validateCategoryId($data['category_id']);
        $this->validatePath($data['path']);
        $this->validateUniquePath($data['path'], $id);

        $page->save($data);

        return true;
    }

    /**
     * @return array<int, string>
     */
    protected function pageTypeLabels(): array
    {
        return [
            ClientPage::TYPE_TAB => '底部导航页',
            ClientPage::TYPE_PAGE => '主包页面',
            ClientPage::TYPE_SUBPACKAGE => '分包页面',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function categoryLabels(): array
    {
        return app()->make(ClientPageCategoryService::class)->getLabelMap();
    }

    protected function formatPickerItem(array $item, int $categoryId, array $categoryLabels): array
    {
        $type = (string) ($item['page_type'] ?? ClientPage::TYPE_PAGE);

        return [
            'id' => (int) ($item['id'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'path' => (string) ($item['path'] ?? ''),
            'page_type' => $type,
            'page_type_label' => $this->pageTypeLabels()[$type] ?? '页面',
            'category_id' => $categoryId,
            'category_label' => $categoryLabels[$categoryId] ?? '',
            'package_root' => $item['package_root'] ?? null,
            'need_login' => (int) ($item['need_login'] ?? 0),
            'source' => (string) ($item['source'] ?? ClientPage::SOURCE_AUTO),
            'remark' => $item['remark'] ?? null,
        ];
    }

    protected function normalizePagePath(string $path): string
    {
        return '/' . ltrim(strtok($path, '?') ?: $path, '/');
    }

    public function delete(int $id): bool
    {
        $page = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$page) {
            throw new BusinessException('页面不存在');
        }

        if ((string) $page->source === ClientPage::SOURCE_SYSTEM) {
            throw new BusinessException('系统页面不能删除');
        }

        $page->save([
            'status' => 0,
            'delete_time' => time(),
        ]);

        return true;
    }

    /**
     * 从显式提交的 UniApp pages.json 内容导入页面库。
     *
     * @return array{created:int, updated:int, skipped:int}
     */
    public function importFromUniappPages(?string $pagesJsonContent = null): array
    {
        $content = trim((string) $pagesJsonContent);
        if ($content === '') {
            throw new BusinessException('请上传 pages.json 或粘贴 pages.json 内容后再导入');
        }

        $decoded = json_decode($this->stripJsonComments($content), true);
        if (!is_array($decoded)) {
            throw new BusinessException('pages.json 解析失败');
        }

        $tabPaths = $this->collectTabPaths($decoded);
        $categoryService = app()->make(ClientPageCategoryService::class);
        $categoryIds = $categoryService->getIdMap();
        $systemCategoryIds = $categoryService->getSystemNameIdMap();
        $defaultCategoryId = $categoryService->getDefaultCategoryId();
        $rows = [];
        foreach ((array) ($decoded['pages'] ?? []) as $index => $page) {
            if (!is_array($page) || empty($page['path'])) {
                continue;
            }
            $path = '/' . trim((string) $page['path'], '/');
            $rows[] = [
                'name' => $this->inferPageName($path, (string) ($page['style']['navigationBarTitleText'] ?? '')),
                'path' => $path,
                'page_type' => in_array($path, $tabPaths, true) ? ClientPage::TYPE_TAB : ClientPage::TYPE_PAGE,
                'category_id' => $this->resolveCategoryId(
                    $this->inferCategoryId($path, $defaultCategoryId, $systemCategoryIds),
                    $categoryIds,
                    $defaultCategoryId
                ),
                'package_root' => null,
                'need_login' => $this->inferNeedLogin($path) ? 1 : 0,
                'source' => ClientPage::SOURCE_SYSTEM,
                'remark' => null,
                'sort' => ($index + 1) * 10,
                'status' => 1,
            ];
        }

        foreach ((array) ($decoded['subPackages'] ?? []) as $packageIndex => $package) {
            if (!is_array($package) || empty($package['root'])) {
                continue;
            }
            $root = trim((string) $package['root'], '/');
            foreach ((array) ($package['pages'] ?? []) as $pageIndex => $page) {
                if (!is_array($page) || empty($page['path'])) {
                    continue;
                }
                $path = '/' . $root . '/' . trim((string) $page['path'], '/');
                $rows[] = [
                    'name' => $this->inferPageName($path, (string) ($page['style']['navigationBarTitleText'] ?? '')),
                    'path' => $path,
                    'page_type' => ClientPage::TYPE_SUBPACKAGE,
                    'category_id' => $this->resolveCategoryId(
                        $this->inferCategoryId($path, $defaultCategoryId, $systemCategoryIds),
                        $categoryIds,
                        $defaultCategoryId
                    ),
                    'package_root' => $root,
                    'need_login' => $this->inferNeedLogin($path) ? 1 : 0,
                    'source' => ClientPage::SOURCE_SYSTEM,
                    'remark' => null,
                    'sort' => 1000 + (($packageIndex + 1) * 100) + (($pageIndex + 1) * 10),
                    'status' => 1,
                ];
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            try {
                $existing = $this->model()
                    ->where('path', $row['path'])
                    ->find();

                if (!$existing) {
                    $this->model()->create($row);
                    ++$created;
                    continue;
                }

                if ((string) $existing->source === ClientPage::SOURCE_SYSTEM) {
                    $existing->save([
                        'name' => $row['name'],
                        'page_type' => $row['page_type'],
                        'category_id' => $row['category_id'],
                        'package_root' => $row['package_root'],
                        'need_login' => $row['need_login'],
                        'sort' => $row['sort'],
                        'status' => 1,
                        'delete_time' => null,
                    ]);
                } else {
                    $existing->save(array_merge($row, ['delete_time' => null]));
                }
                ++$updated;
            } catch (\Throwable) {
                ++$skipped;
            }
        }

        return compact('created', 'updated', 'skipped');
    }

    protected function normalizePayload(array $data): array
    {
        $data['path'] = '/' . trim((string) ($data['path'] ?? ''), '/');
        $data['name'] = trim((string) ($data['name'] ?? ''));
        $data['page_type'] = (string) ($data['page_type'] ?? ClientPage::TYPE_PAGE);
        $data['category_id'] = (int) ($data['category_id'] ?? 0);
        if ($data['category_id'] <= 0) {
            $categoryService = app()->make(ClientPageCategoryService::class);
            $data['category_id'] = $this->resolveCategoryId(
                $this->inferCategoryId(
                    $data['path'],
                    $categoryService->getDefaultCategoryId(),
                    $categoryService->getSystemNameIdMap()
                ),
                $categoryService->getIdMap(),
                $categoryService->getDefaultCategoryId()
            );
        }
        $data['package_root'] = trim((string) ($data['package_root'] ?? '')) ?: null;
        $data['need_login'] = (int) ($data['need_login'] ?? 0);
        $data['source'] = (string) ($data['source'] ?? ClientPage::SOURCE_MANUAL);
        $data['remark'] = trim((string) ($data['remark'] ?? '')) ?: null;
        $data['sort'] = (int) ($data['sort'] ?? 0);
        $data['status'] = (int) ($data['status'] ?? 1);

        return $data;
    }

    protected function validateCategoryId(int $categoryId): void
    {
        app()->make(ClientPageCategoryService::class)->assertSelectableCategoryId($categoryId);
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @param array<int, string> $labels
     * @return array<int, array<string, mixed>>
     */
    protected function withCategoryLabels(array $list, array $labels): array
    {
        foreach ($list as &$item) {
            $categoryId = (int) ($item['category_id'] ?? 0);
            $item['category_label'] = $labels[$categoryId] ?? '';
        }
        unset($item);

        return $list;
    }

    protected function validatePath(string $path): void
    {
        if ($path === '/' || !str_starts_with($path, '/')) {
            throw new BusinessException('页面路径必须以 / 开头');
        }
    }

    protected function validateUniquePath(string $path, int $excludeId = 0): void
    {
        $query = $this->model()->where('path', $path);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        if ($query->find()) {
            throw new BusinessException('页面路径已存在');
        }
    }

    protected function validateWritableSource(string $source): void
    {
        if ($source === ClientPage::SOURCE_SYSTEM) {
            throw new BusinessException('系统页面只能由系统内置数据维护');
        }
    }

    protected function stripJsonComments(string $content): string
    {
        return (string) preg_replace('#//.*$#m', '', $content);
    }

    /**
     * @return array<int, string>
     */
    protected function collectTabPaths(array $pagesJson): array
    {
        $paths = [];
        foreach ((array) ($pagesJson['tabBar']['list'] ?? []) as $item) {
            if (!is_array($item) || empty($item['pagePath'])) {
                continue;
            }
            $paths[] = '/' . trim((string) $item['pagePath'], '/');
        }
        return $paths;
    }

    protected function inferPageName(string $path, string $title): string
    {
        $title = trim($title);
        if ($title !== '') {
            return mb_substr($title, 0, 80);
        }

        $known = [
            '/pages/index/index' => '首页',
            '/pages/category/index' => '分类',
            '/pages/cart/index' => '购物车',
            '/pages/order/index' => '订单',
            '/pages/profile/index' => '我的',
            '/pages-sub/goods/list' => '商品列表',
            '/pages-sub/goods/detail' => '商品详情',
            '/pages-sub/goods/comments' => '商品评价',
            '/pages-sub/article/list' => '文章列表',
            '/pages-sub/article/detail' => '文章详情',
            '/pages-sub/order/confirm' => '确认订单',
            '/pages-sub/order/list' => '订单列表',
            '/pages-sub/order/detail' => '订单详情',
            '/pages-sub/order/pay-result' => '支付结果',
            '/pages-sub/refund/apply' => '申请退款',
            '/pages-sub/refund/list' => '退款列表',
            '/pages-sub/refund/detail' => '退款详情',
            '/pages-sub/search/index' => '搜索',
            '/pages-sub/user/login' => '登录',
            '/pages-sub/user/agreement' => '用户协议',
            '/pages-sub/user/bind-mobile' => '绑定手机号',
            '/pages-sub/user/edit-profile' => '编辑资料',
            '/pages-sub/user/change-password' => '修改密码',
            '/pages-sub/user/settings' => '设置',
            '/pages-sub/wallet/index' => '钱包',
            '/pages-sub/wallet/records' => '钱包记录',
            '/pages-sub/wallet/recharge' => '余额充值',
            '/pages-sub/points/index' => '积分',
            '/pages-sub/points/records' => '积分记录',
            '/pages-sub/points/mall' => '积分商城',
            '/pages-sub/points/mall-detail' => '积分商品详情',
            '/pages-sub/points/exchange-confirm' => '确认积分兑换',
            '/pages-sub/points/exchange-orders' => '积分兑换记录',
            '/pages-sub/points/exchange-detail' => '积分兑换详情',
            '/pages-sub/distribution/index' => '分销中心',
            '/pages-sub/distribution/records' => '佣金明细',
            '/pages-sub/distribution/team' => '我的团队',
            '/pages-sub/distribution/withdraw' => '佣金提现',
            '/pages-sub/address/list' => '地址列表',
            '/pages-sub/address/edit' => '编辑地址',
            '/pages-sub/review/post' => '发布评价',
            '/pages-sub/logistics/detail' => '物流详情',
        ];

        if (isset($known[$path])) {
            return $known[$path];
        }

        $parts = explode('/', trim($path, '/'));
        return mb_substr((string) end($parts), 0, 80);
    }

    protected function resolveCategoryId(int $categoryId, array $categoryIds, int $defaultCategoryId): int
    {
        return $categoryIds[$categoryId] ?? $defaultCategoryId;
    }

    /**
     * @param array<string, int> $systemCategoryIds
     */
    protected function inferCategoryId(string $path, int $defaultCategoryId, array $systemCategoryIds): int
    {
        if (str_starts_with($path, '/pages-sub/points/')) {
            return ClientPage::CATEGORY_ID_POINTS;
        }

        if (str_starts_with($path, '/pages-sub/wallet/')) {
            return ClientPage::CATEGORY_ID_WALLET;
        }

        if (str_starts_with($path, '/pages-sub/distribution/')) {
            return $systemCategoryIds[self::CATEGORY_NAME_DISTRIBUTION] ?? $defaultCategoryId;
        }

        foreach ([
            ClientPage::CATEGORY_ID_GOODS => ['/pages-sub/goods/', '/pages-sub/search/'],
            ClientPage::CATEGORY_ID_CONTENT => ['/pages-sub/article/'],
            ClientPage::CATEGORY_ID_ORDER => ['/pages-sub/order/', '/pages-sub/logistics/', '/pages-sub/review/'],
            ClientPage::CATEGORY_ID_AFTERSALE => ['/pages-sub/refund/'],
            ClientPage::CATEGORY_ID_USER => ['/pages-sub/user/', '/pages-sub/address/'],
        ] as $category => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    return $category;
                }
            }
        }

        if (str_starts_with($path, '/pages/')) {
            return ClientPage::CATEGORY_ID_BASIC;
        }

        $segments = explode('/', trim($path, '/'));
        foreach ([
            ClientPage::CATEGORY_ID_GOODS => ['goods', 'search'],
            ClientPage::CATEGORY_ID_CONTENT => ['article', 'content'],
            ClientPage::CATEGORY_ID_ORDER => ['order', 'logistics', 'review'],
            ClientPage::CATEGORY_ID_AFTERSALE => ['refund'],
            ClientPage::CATEGORY_ID_USER => ['user', 'address'],
            ClientPage::CATEGORY_ID_POINTS => ['points'],
            ClientPage::CATEGORY_ID_WALLET => ['wallet'],
            $systemCategoryIds[self::CATEGORY_NAME_DISTRIBUTION] ?? $defaultCategoryId => ['distribution'],
        ] as $category => $markers) {
            foreach ($markers as $marker) {
                if (in_array($marker, $segments, true)) {
                    return $category;
                }
            }
        }

        return $defaultCategoryId;
    }

    protected function inferNeedLogin(string $path): bool
    {
        foreach (['/pages-sub/user/login', '/pages-sub/user/agreement'] as $publicPath) {
            if ($path === $publicPath) {
                return false;
            }
        }

        foreach (['/pages/cart/', '/pages/order/', '/pages/profile/', '/pages-sub/order/', '/pages-sub/refund/', '/pages-sub/user/', '/pages-sub/wallet/', '/pages-sub/distribution/', '/pages-sub/address/', '/pages-sub/review/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
