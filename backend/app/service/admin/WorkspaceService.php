<?php

declare(strict_types=1);

namespace app\service\admin;

use app\common\enum\OrderStatus;
use app\common\enum\RefundOrderStatus;
use app\model\auth\AdminWorkspaceShortcut;
use app\model\goods\Goods;
use app\model\logistics\LogisticsCompany;
use app\model\order\Order;
use app\model\order\RefundOrder;
use app\model\sms\SmsProvider;
use app\service\admin\auth\PermissionService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台工作台服务
 *
 * @extends BaseService<AdminWorkspaceShortcut>
 */
class WorkspaceService extends BaseService
{
    private const MAX_SHORTCUTS = 8;
    private const STOCK_WARNING_THRESHOLD = 10;

    protected string $modelClass = AdminWorkspaceShortcut::class;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingShipmentTodo(): array
    {
        $count = $this->orderCount(OrderStatus::PAID);
        return $count > 0
            ? [$this->buildTodo(
                key: 'pending_shipment',
                title: '待发货订单',
                count: $count,
                description: '已支付订单等待发货',
                level: 'warning',
                link: '/order',
                query: ['status' => OrderStatus::PAID],
                actionText: '去发货',
            )]
            : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function refundPendingTodo(): array
    {
        $count = $this->refundCount(RefundOrderStatus::PENDING);
        return $count > 0
            ? [$this->buildTodo(
                key: 'refund_pending',
                title: '售后待审核',
                count: $count,
                description: '售后申请等待商家审核',
                level: 'danger',
                link: '/order/refund',
                query: ['status' => RefundOrderStatus::PENDING],
                actionText: '去审核',
            )]
            : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function stockWarningTodo(): array
    {
        $count = $this->stockWarningCount();
        return $count > 0
            ? [$this->buildTodo(
                key: 'stock_warning',
                title: '库存预警商品',
                count: $count,
                description: '上架商品库存低于预警线',
                level: 'warning',
                link: '/goods',
                query: ['view' => 'on_sale', 'stock_warning' => 1],
                actionText: '去处理',
            )]
            : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function logisticsConfigTodo(): array
    {
        $count = $this->enabledLogisticsCompanyCount();
        return $count === 0
            ? [$this->buildTodo(
                key: 'logistics_config',
                title: '物流公司配置',
                count: 1,
                description: '未配置启用的物流公司会影响发货',
                level: 'danger',
                link: '/logistics/company',
                query: [],
                actionText: '去配置',
            )]
            : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function smsProviderConfigTodo(): array
    {
        $count = $this->enabledDefaultSmsProviderCount();
        return $count === 0
            ? [$this->buildTodo(
                key: 'sms_provider_config',
                title: '短信服务配置',
                count: 1,
                description: '未配置启用的默认短信服务商会影响验证码和通知',
                level: 'warning',
                link: '/sms/provider',
                query: [],
                actionText: '去配置',
            )]
            : [];
    }

    /**
     * @param int $adminId
     * @return array<int, array<string, mixed>>
     */
    public function shortcuts(int $adminId): array
    {
        $menuOptions = $this->availableMenuOptions($adminId);
        return $this->buildShortcuts($adminId, $menuOptions);
    }

    /**
     * @return array<int,array<string, mixed>>
     */
    public function menuOptions(int $adminId): array
    {
        return $this->availableMenuOptions($adminId);
    }

    /**
     * @param array<int, mixed> $shortcuts
     * @return array<int, array<string, mixed>>
     */
    public function updateShortcuts(int $adminId, array $shortcuts): array
    {
        $menuOptions = $this->availableMenuOptions($adminId);
        $menuMap = [];
        foreach ($menuOptions as $option) {
            $menuMap[(string) $option['path']] = $option;
        }

        $normalized = [];
        foreach ($shortcuts as $shortcut) {
            $path = is_array($shortcut)
                ? trim((string) ($shortcut['path'] ?? ''))
                : trim((string) $shortcut);
            if ($path === '') {
                continue;
            }
            if (!isset($menuMap[$path])) {
                throw new BusinessException('快捷入口包含不可访问的菜单');
            }
            if (isset($normalized[$path])) {
                continue;
            }
            $normalized[$path] = $menuMap[$path];
            if (count($normalized) >= self::MAX_SHORTCUTS) {
                break;
            }
        }

        $this->transaction(function () use ($adminId, $normalized): void {
            $this->model()->where('admin_id', $adminId)->delete();

            $sort = 0;
            foreach ($normalized as $item) {
                $this->model()->save([
                    'admin_id' => $adminId,
                    'title'    => mb_substr((string) $item['title'], 0, 100),
                    'path'     => (string) $item['path'],
                    'icon'     => mb_substr((string) ($item['icon'] ?? ''), 0, 100),
                    'sort'     => $sort++,
                ]);
            }
        });

        return $this->shortcuts($adminId);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTodo(
        string $key,
        string $title,
        int $count,
        string $description,
        string $level,
        string $link,
        array $query,
        string $actionText
    ): array {
        return [
            'key'         => $key,
            'title'       => $title,
            'count'       => $count,
            'description' => $description,
            'level'       => $level,
            'link'        => $link,
            'query'       => $query,
            'action_text' => $actionText,
        ];
    }

    /**
     * @param int $adminId
     * @param array<int, array<string, mixed>> $menuOptions
     * @return array<int, array<string, mixed>>
     */
    private function buildShortcuts(int $adminId, array $menuOptions): array
    {
        $menuMap = [];
        foreach ($menuOptions as $option) {
            $menuMap[(string) $option['path']] = $option;
        }

        $saved = $this->model()
            ->where('admin_id', $adminId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        if ($saved === []) {
            return $this->defaultShortcuts($menuMap);
        }

        $shortcuts = [];
        foreach ($saved as $row) {
            $path = (string) ($row['path'] ?? '');
            if (!isset($menuMap[$path])) {
                continue;
            }
            $shortcuts[] = $menuMap[$path];
        }

        return $shortcuts;
    }

    /**
     * @param array<string, array<string, mixed>> $menuMap
     * @return array<int, array<string, mixed>>
     */
    private function defaultShortcuts(array $menuMap): array
    {
        $paths = ['/order', '/order/refund', '/goods', '/user', '/upload/asset', '/settings/item'];
        $shortcuts = [];
        foreach ($paths as $path) {
            if (isset($menuMap[$path])) {
                $shortcuts[] = $menuMap[$path];
            }
        }

        return $shortcuts;
    }

    /**
     * @return array<int, array{title:string,path:string,icon:string}>
     */
    private function availableMenuOptions(int $adminId): array
    {
        /** @var PermissionService $permissionService */
        $permissionService = app()->make(PermissionService::class);
        $menu = $permissionService->getMenu($adminId);

        return $this->flattenRoutes($menu['routes'] ?? []);
    }

    /**
     * @param array<int, array<string, mixed>> $routes
     * @return array<int, array{title:string,path:string,icon:string}>
     */
    private function flattenRoutes(array $routes): array
    {
        $items = [];
        foreach ($routes as $route) {
            $meta = is_array($route['meta'] ?? null) ? $route['meta'] : [];
            $path = (string) ($route['path'] ?? '');
            $title = (string) ($meta['title'] ?? '');
            $hidden = (bool) ($meta['hideInMenu'] ?? false);
            $component = (string) ($route['component'] ?? '');

            if ($path !== '' && $path !== '/workspace' && $component !== '' && !$hidden && $title !== '') {
                $items[] = [
                    'title' => $title,
                    'path'  => $path,
                    'icon'  => (string) ($meta['icon'] ?? 'lucide:circle'),
                ];
            }

            if (is_array($route['children'] ?? null)) {
                array_push($items, ...$this->flattenRoutes($route['children']));
            }
        }

        return $items;
    }

    private function orderCount(int $status): int
    {
        return (int) $this->model(Order::class)
            ->where('status', $status)
            ->whereNull('delete_time')
            ->count();
    }

    private function refundCount(int $status): int
    {
        return (int) $this->model(RefundOrder::class)
            ->where('status', $status)
            ->whereNull('delete_time')
            ->count();
    }

    private function stockWarningCount(): int
    {
        return (int) $this->model(Goods::class)
            ->where('status', 1)
            ->where('is_on_sale', 1)
            ->where('stock', '<=', self::STOCK_WARNING_THRESHOLD)
            ->whereNull('delete_time')
            ->count();
    }

    private function enabledLogisticsCompanyCount(): int
    {
        return (int) $this->model(LogisticsCompany::class)
            ->where('status', 1)
            ->count();
    }

    private function enabledDefaultSmsProviderCount(): int
    {
        return (int) $this->model(SmsProvider::class)
            ->where('status', 1)
            ->where('is_default', 1)
            ->count();
    }
}
