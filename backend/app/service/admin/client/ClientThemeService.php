<?php
declare(strict_types=1);

namespace app\service\admin\client;

use app\model\client\ClientTheme;
use app\model\client\ClientThemePolicy;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 客户端主题服务
 * @extends BaseService<ClientTheme>
 */
class ClientThemeService extends BaseService
{
    protected string $modelClass = ClientTheme::class;

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

        $theme->save([
            'status' => ClientTheme::STATUS_DRAFT,
            'delete_time' => time(),
        ]);

        $policy = $this->getPolicy();
        if ((int) ($policy['default_theme_id'] ?? 0) === $id) {
            $this->savePolicy([
                'allow_user_select' => (int) $policy['allow_user_select'],
                'default_mode' => ClientThemePolicy::MODE_SYSTEM,
                'default_theme_id' => null,
            ]);
        }

        return true;
    }

    public function getPolicy(): array
    {
        $policy = $this->model(ClientThemePolicy::class)->find(ClientThemePolicy::POLICY_ID);
        if (!$policy) {
            $policy = $this->model(ClientThemePolicy::class)->create([
                'id' => ClientThemePolicy::POLICY_ID,
                'allow_user_select' => 1,
                'default_mode' => ClientThemePolicy::MODE_SYSTEM,
                'default_theme_id' => null,
            ]);
        }

        return $policy->toArray();
    }

    public function savePolicy(array $data): array
    {
        $allowUserSelect = (int) ($data['allow_user_select'] ?? 1);
        $defaultMode = (string) ($data['default_mode'] ?? ClientThemePolicy::MODE_SYSTEM);
        $defaultThemeId = isset($data['default_theme_id']) && $data['default_theme_id'] !== ''
            ? (int) $data['default_theme_id']
            : null;

        if (!in_array($defaultMode, ClientThemePolicy::validModes(), true)) {
            throw new BusinessException('默认主题模式不正确');
        }

        if ($defaultMode === ClientThemePolicy::MODE_CUSTOM) {
            if ($defaultThemeId === null || $defaultThemeId <= 0) {
                throw new BusinessException('请选择默认自定义主题');
            }
            $theme = $this->model()
                ->where('id', $defaultThemeId)
                ->where('type', ClientTheme::TYPE_CUSTOM)
                ->where('status', ClientTheme::STATUS_PUBLISHED)
                ->whereNull('delete_time')
                ->find();
            if (!$theme) {
                throw new BusinessException('默认自定义主题不存在或未发布');
            }
        } else {
            $defaultThemeId = null;
        }

        $payload = [
            'id' => ClientThemePolicy::POLICY_ID,
            'allow_user_select' => $allowUserSelect,
            'default_mode' => $defaultMode,
            'default_theme_id' => $defaultThemeId,
        ];

        $policy = $this->model(ClientThemePolicy::class)->find(ClientThemePolicy::POLICY_ID);
        if ($policy) {
            $policy->save($payload);
        } else {
            $policy = $this->model(ClientThemePolicy::class)->create($payload);
        }

        return $policy->toArray();
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
