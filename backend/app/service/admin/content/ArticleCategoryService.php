<?php
declare(strict_types=1);

namespace app\service\admin\content;

use app\model\content\Article;
use app\model\content\ArticleCategory;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台文章分类服务
 * @extends BaseService<ArticleCategory>
 */
class ArticleCategoryService extends BaseService
{
    protected string $modelClass = ArticleCategory::class;

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
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $total = $this->buildListQuery($where)->count();

        return compact('total', 'list');
    }

    public function getAllCategories(): array
    {
        return $this->model()
            ->where('status', 1)
            ->whereNull('delete_time')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    public function getInfo(int $id): array
    {
        $category = $this->findValidCategory($id);
        return $category->toArray();
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
        $payload = $this->normalizePayload($data, $category->toArray());
        $this->validateUniqueName($payload['name'], $id);

        $category->save($payload);

        return true;
    }

    public function delete(int $id): bool
    {
        $category = $this->findValidCategory($id);
        $articleCount = $this->model(Article::class)
            ->where('category_id', $id)
            ->whereNull('delete_time')
            ->count();
        if ($articleCount > 0) {
            throw new BusinessException('该分类下还有文章，无法删除');
        }

        $category->save([
            'status' => 0,
            'delete_time' => time(),
        ]);

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

    protected function normalizePayload(array $data, array $base = []): array
    {
        return [
            'name' => mb_substr(trim((string) ($data['name'] ?? $base['name'] ?? '')), 0, 80),
            'description' => mb_substr(trim((string) ($data['description'] ?? $base['description'] ?? '')), 0, 255) ?: null,
            'sort' => (int) ($data['sort'] ?? $base['sort'] ?? 0),
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

    protected function findValidCategory(int $id)
    {
        $category = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$category) {
            throw new BusinessException('文章分类不存在');
        }

        return $category;
    }
}
