<?php
declare(strict_types=1);

namespace app\service\client;

use app\model\goods\Goods;
use app\model\order\Order;
use app\model\user\User;
use app\service\connector\CustomerServiceContextTokenService;
use app\service\connector\CustomerServiceSettingService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * MallBase C 端在线客服上下文编排。
 *
 * @extends BaseService<User>
 */
class CustomerServiceContextService extends BaseService
{
    protected string $modelClass = User::class;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function issue(int $userId, array $input): array
    {
        if (!$this->isClientConfigured()) {
            return $this->disabledPayload();
        }

        /** @var User|null $user */
        $user = $this->model(User::class)
            ->where('id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($user === null) {
            throw new BusinessException('用户不存在');
        }

        $source = $this->normalizeText($input['source'] ?? 'mallbase', 40);
        $businessResources = $this->businessResources($userId, (array) ($input['resources'] ?? []));
        $resources = array_merge($businessResources, [$this->userResource($user)]);
        $primary = $businessResources[0] ?? $resources[0];
        $conversationKey = $this->normalizeText($input['conversation_key'] ?? '', 160);
        if ($conversationKey === '') {
            $conversationKey = 'mallbase:' . $userId . ':' . $source . ':' . $primary['type'] . ':' . $primary['id'];
        }

        $payload = [
            'visitor' => [
                'id' => (string) $userId,
                'authenticated' => true,
                'name' => $this->visitorName($user),
                'contact' => $this->visitorContact($user),
            ],
            'conversationKey' => $conversationKey,
            'context' => $this->legacyContext($source, $primary),
            'resources' => $resources,
        ];

        $token = app()->make(CustomerServiceContextTokenService::class)->issue($payload);

        return [
            'enabled' => true,
            'context_token' => $token,
            'expires_in' => $this->customerServiceSettings()->contextTtl(),
            'platform_code' => $this->customerServiceSettings()->platformCode(),
            'widget_url' => $this->customerServiceSettings()->widgetUrl(),
            'api_base' => $this->customerServiceSettings()->apiBase(),
            'socket_base' => $this->customerServiceSettings()->socketBase(),
            'conversation_key' => $conversationKey,
            'resources' => $resources,
        ];
    }

    private function isClientConfigured(): bool
    {
        $settings = $this->customerServiceSettings();
        return $settings->clientMode() === 'system'
            && $settings->contextSecret() !== ''
            && $settings->widgetUrl() !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function disabledPayload(): array
    {
        return [
            'enabled' => false,
            'reason' => '客服系统未配置',
            'context_token' => '',
            'expires_in' => 0,
            'platform_code' => $this->customerServiceSettings()->platformCode(),
            'widget_url' => $this->customerServiceSettings()->widgetUrl(),
            'api_base' => $this->customerServiceSettings()->apiBase(),
            'socket_base' => $this->customerServiceSettings()->socketBase(),
            'resources' => [],
        ];
    }

    private function customerServiceSettings(): CustomerServiceSettingService
    {
        return app()->make(CustomerServiceSettingService::class);
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function businessResources(int $userId, array $rows): array
    {
        $resources = [];
        foreach (array_slice($rows, 0, 6) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower($this->normalizeText($row['type'] ?? '', 30));
            if ($type === 'goods') {
                $type = 'product';
            }
            if ($type === 'product') {
                $resources[] = $this->productResource($row);
                continue;
            }
            if ($type === 'order') {
                $resources[] = $this->orderResource($userId, $row);
            }
        }

        return $this->deduplicateResources($resources);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function productResource(array $row): array
    {
        $goodsId = $this->resourceId($row);
        if ($goodsId <= 0) {
            throw new BusinessException('商品ID无效');
        }

        /** @var Goods|null $goods */
        $goods = $this->model(Goods::class)
            ->where('id', $goodsId)
            ->whereNull('delete_time')
            ->find();
        if ($goods === null) {
            throw new BusinessException('商品不存在');
        }

        return [
            'type' => 'product',
            'id' => (string) $goodsId,
            'title' => $this->normalizeText($row['title'] ?? $goods->name ?? '', 160),
            'url' => $this->normalizeUrl($row['url'] ?? ''),
            'summary' => $this->normalizeText($row['summary'] ?? $goods->subtitle ?? '', 300),
            'metadata' => [
                'source' => 'mallbase',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function orderResource(int $userId, array $row): array
    {
        $orderId = $this->resourceId($row);
        if ($orderId <= 0) {
            throw new BusinessException('订单ID无效');
        }

        /** @var Order|null $order */
        $order = $this->model(Order::class)
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->find();
        if ($order === null) {
            throw new BusinessException('订单不存在');
        }

        return [
            'type' => 'order',
            'id' => (string) $orderId,
            'title' => $this->normalizeText($row['title'] ?? ('订单 ' . (string) $order->sn), 160),
            'url' => $this->normalizeUrl($row['url'] ?? ''),
            'summary' => $this->normalizeText($row['summary'] ?? (string) $order->status_text, 300),
            'metadata' => [
                'sn' => (string) $order->sn,
                'source' => 'mallbase',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userResource(User $user): array
    {
        return [
            'type' => 'user',
            'id' => (string) $user->id,
            'title' => $this->visitorName($user),
            'url' => '',
            'summary' => $this->normalizeText($user->mobile ?? $user->email ?? '', 120),
            'metadata' => [
                'source' => 'mallbase',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateResources(array $resources): array
    {
        $seen = [];
        $result = [];
        foreach ($resources as $resource) {
            $key = (string) $resource['type'] . ':' . (string) $resource['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $resource;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resourceId(array $row): int
    {
        return (int) ($row['id'] ?? $row['external_id'] ?? $row['externalId'] ?? 0);
    }

    /**
     * @param array<string, mixed> $primary
     * @return array<string, mixed>
     */
    private function legacyContext(string $source, array $primary): array
    {
        $context = [
            'type' => 'CUSTOM',
            'title' => (string) ($primary['title'] ?? '业务上下文'),
            'url' => (string) ($primary['url'] ?? ''),
            'path' => $source,
            'metadata' => [
                'source' => 'mallbase',
                'resource_type' => (string) ($primary['type'] ?? ''),
                'resource_id' => (string) ($primary['id'] ?? ''),
            ],
        ];

        if (($primary['type'] ?? '') === 'product') {
            $context['type'] = 'PRODUCT';
            $context['productId'] = (string) $primary['id'];
            $context['productName'] = (string) $primary['title'];
        } elseif (($primary['type'] ?? '') === 'order') {
            $context['type'] = 'ORDER';
            $context['orderId'] = (string) $primary['id'];
            $context['orderSn'] = (string) ($primary['metadata']['sn'] ?? $primary['title']);
        }

        return $context;
    }

    private function visitorName(User $user): string
    {
        return $this->normalizeText($user->nickname ?? $user->username ?? $user->mobile ?? ('用户' . $user->id), 80);
    }

    private function visitorContact(User $user): string
    {
        return $this->normalizeText($user->mobile ?? $user->email ?? '', 120);
    }

    private function normalizeText(mixed $value, int $limit): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return mb_substr(trim((string) $value), 0, $limit);
    }

    private function normalizeUrl(mixed $value): string
    {
        $url = $this->normalizeText($value, 300);
        if ($url === '') {
            return '';
        }
        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }
}
