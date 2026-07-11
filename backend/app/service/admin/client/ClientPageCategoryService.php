<?php
declare(strict_types=1);

namespace app\service\admin\client;

use app\model\client\ClientPage;
use app\model\client\ClientPageCategory;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 客户端页面分类服务
 * @extends BaseService<ClientPageCategory>
 */
class ClientPageCategoryService extends BaseService
{
    protected string $modelClass = ClientPageCategory::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->whereNull('delete_time')
            ->when(($where['keyword'] ?? null) !== null && $where['keyword'] !== '', function ($q) use ($where) {
                $keyword = trim((string) $where['keyword']);
                $q->whereLike('name|description', '%' . $keyword . '%');
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
    }

    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $total = $this->buildListQuery($where)->count();

        return compact('total', 'list');
    }

    public function getAllCategories(): array
    {
        return $this->model()
            ->whereNull('delete_time')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * @return array<int, string>
     */
    public function getLabelMap(): array
    {
        $labels = [];
        foreach ($this->getAllCategories() as $category) {
            $labels[(int) $category['id']] = (string) $category['name'];
        }

        return $labels;
    }

    /**
     * @return array<int, int>
     */
    public function getIdMap(): array
    {
        $map = [];
        foreach ($this->getAllCategories() as $category) {
            $id = (int) $category['id'];
            $map[$id] = $id;
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    public function getSystemNameIdMap(): array
    {
        $map = [];
        $categories = $this->model()
            ->where('is_system', 1)
            ->whereNull('delete_time')
            ->select()
            ->toArray();
        foreach ($categories as $category) {
            $map[(string) $category['name']] = (int) $category['id'];
        }

        return $map;
    }

    public function getInfo(int $id): array
    {
        return $this->findValidCategory($id)->toArray();
    }

    public function create(array $data): int
    {
        $payload = $this->normalizePayload($data);
        $this->validateUniqueName($payload['name']);

        $category = $this->model()->create($payload);

        return (int) $category->id;
    }

    public function update(int $id, array $data): bool
    {
        $category = $this->findValidCategory($id);
        $base = $category->toArray();
        $payload = $this->normalizePayload($data, $base);

        $this->validateUniqueName($payload['name'], $id);

        $category->save($payload);

        return true;
    }

    public function delete(int $id): bool
    {
        $category = $this->findValidCategory($id);
        if ((int) $category->is_system === 1) {
            throw new BusinessException('系统分类不能删除');
        }

        $pageCount = $this->model(ClientPage::class)
            ->where('category_id', $id)
            ->whereNull('delete_time')
            ->count();
        if ((int) $pageCount > 0) {
            throw new BusinessException('该分类下还有页面，不能删除');
        }

        $category->delete();

        return true;
    }

    public function updateStatus(int $id, int $status): bool
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态必须是0或1');
        }

        $category = $this->findValidCategory($id);
        $category->save(['status' => $status]);

        return true;
    }

    public function assertSelectableCategoryId(int $id): void
    {
        $category = $this->model()
            ->where('id', $id)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->find();

        if (!$category) {
            throw new BusinessException('页面分类不存在或已禁用');
        }
    }

    public function getDefaultCategoryId(): int
    {
        $category = $this->model()
            ->where('id', ClientPage::CATEGORY_ID_OTHER)
            ->whereNull('delete_time')
            ->find();

        if (!$category) {
            throw new BusinessException('默认页面分类不存在');
        }

        return (int) $category->id;
    }

    protected function normalizePayload(array $data, array $base = []): array
    {
        return [
            'name' => mb_substr(trim((string) ($data['name'] ?? $base['name'] ?? '')), 0, 80),
            'description' => mb_substr(trim((string) ($data['description'] ?? $base['description'] ?? '')), 0, 255) ?: null,
            'sort' => (int) ($data['sort'] ?? $base['sort'] ?? 0),
            'is_system' => (int) ($base['is_system'] ?? 0),
            'status' => (int) ($data['status'] ?? $base['status'] ?? 1),
        ];
    }

    protected function validateUniqueName(string $name, int $excludeId = 0): void
    {
        $query = $this->model()->where('name', $name)->whereNull('delete_time');
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        if ($query->find()) {
            throw new BusinessException('分类名称已存在');
        }
    }

    protected function findValidCategory(int $id): ClientPageCategory
    {
        $category = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$category) {
            throw new BusinessException('页面分类不存在');
        }

        return $category;
    }
}
