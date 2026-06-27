<?php
declare(strict_types=1);

namespace app\service\admin\content;

use app\model\content\Article;
use app\model\content\ArticleCategory;
use app\model\content\ArticleReadRecord;
use app\service\content\RichTextSanitizer;
use app\service\upload\AssetHydrator;
use app\service\upload\AssetIdNormalizer;
use app\service\upload\AssetService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台文章服务
 * @extends BaseService<Article>
 */
class ArticleService extends BaseService
{
    protected string $modelClass = Article::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->alias('a')
            ->leftJoin('mb_article_category c', 'c.id = a.category_id')
            ->whereNull('a.delete_time')
            ->when(($where['keyword'] ?? null) !== null && $where['keyword'] !== '', function ($q) use ($where) {
                $keyword = trim((string) $where['keyword']);
                $q->whereLike('a.title|a.description', '%' . $keyword . '%');
            })
            ->when(!empty($where['category_id']), function ($q) use ($where) {
                $q->where('a.category_id', (int) $where['category_id']);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('a.status', (int) $where['status']);
            });
    }

    public function getList(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)
            ->field('a.*, c.name AS category_name')
            ->order('a.sort', 'asc')
            ->order('a.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $list = app()->make(AssetHydrator::class)->hydrateArticleList($list);

        $total = $this->buildListQuery($where)->count();

        return compact('total', 'list');
    }

    public function getInfo(int $id): array
    {
        $article = $this->findValidArticle($id);
        $data = $article->toArray();
        $categoryName = (string) $this->model(ArticleCategory::class)
            ->where('id', (int) $data['category_id'])
            ->value('name');
        $data['category_name'] = $categoryName;

        return app()->make(AssetHydrator::class)->hydrateArticleDetail($data);
    }

    public function create(array $data): int
    {
        $payload = $this->normalizePayload($data);
        $this->validateCategoryUsable((int) $payload['category_id']);
        $this->validateAssetRefs($payload);

        $articleId = (int) $this->transaction(function () use ($payload) {
            $article = $this->model();
            $article->save($payload);
            $this->syncArticleAssetUsage((int) $article->id, $payload);

            return (int) $article->id;
        });

        return $articleId;
    }

    public function update(int $id, array $data): bool
    {
        $article = $this->findValidArticle($id);
        $payload = $this->normalizePayload($data, $article->toArray());
        $this->validateCategoryUsable((int) $payload['category_id']);
        $this->validateAssetRefs($payload);

        $this->transaction(function () use ($article, $payload) {
            $article->save($payload);
            $this->syncArticleAssetUsage((int) $article->id, $payload);
            return true;
        });

        return true;
    }

    public function delete(int $id): bool
    {
        $article = $this->findValidArticle($id);
        $article->save([
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

        $article = $this->findValidArticle($id);
        if ($status === 1) {
            $this->validateCategoryUsable((int) $article->category_id);
        }
        $article->save(['status' => $status]);

        return true;
    }

    /**
     * @return array{total:int, list:array<int, array<string, mixed>>}
     */
    public function getReadRecords(array $where, int $page, int $limit): array
    {
        $query = $this->model(ArticleReadRecord::class)
            ->alias('r')
            ->leftJoin('mb_article a', 'a.id = r.article_id')
            ->leftJoin('mb_user u', 'u.id = r.user_id')
            ->when(!empty($where['article_id']), function ($q) use ($where) {
                $q->where('r.article_id', (int) $where['article_id']);
            })
            ->when(($where['keyword'] ?? null) !== null && $where['keyword'] !== '', function ($q) use ($where) {
                $keyword = trim((string) $where['keyword']);
                $q->where(function ($subQuery) use ($keyword) {
                    $subQuery->whereLike('a.title|u.nickname|u.mobile|u.email', '%' . $keyword . '%');
                    if (str_contains('未登录用户匿名用户未知用户', $keyword)) {
                        $subQuery->whereOr('r.user_id', 0);
                    }
                });
            })
            ->when(($where['start_time'] ?? null) !== null && $where['start_time'] !== '', function ($q) use ($where) {
                $q->where('r.last_read_time', '>=', (string) $where['start_time']);
            })
            ->when(($where['end_time'] ?? null) !== null && $where['end_time'] !== '', function ($q) use ($where) {
                $q->where('r.last_read_time', '<=', (string) $where['end_time']);
            });

        $list = (clone $query)
            ->field('r.*, a.title AS article_title, u.nickname AS user_nickname, u.mobile AS user_mobile, u.email AS user_email, u.avatar AS user_avatar')
            ->order('r.last_read_time', 'desc')
            ->order('r.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $list = app()->make(AssetHydrator::class)->hydrateFields($list, [
            'user_avatar' => 'user_avatar_full_url',
        ]);
        foreach ($list as &$item) {
            $item['user_id'] = (int) ($item['user_id'] ?? 0);
            if ($item['user_id'] === 0) {
                $item['user_nickname'] = '未登录用户';
                $item['user_mobile'] = null;
                $item['user_email'] = null;
                $item['user_avatar'] = null;
                $item['user_avatar_full_url'] = null;
                continue;
            }

            $item['user_nickname'] = $item['user_nickname'] ?: '已注销用户';
        }
        unset($item);

        $total = $query->count();

        return compact('total', 'list');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPickerArticles(array $where): array
    {
        $list = $this->model()
            ->where('status', 1)
            ->whereNull('delete_time')
            ->when(($where['keyword'] ?? null) !== null && $where['keyword'] !== '', function ($q) use ($where) {
                $keyword = trim((string) $where['keyword']);
                $q->whereLike('title|description', '%' . $keyword . '%');
            })
            ->field('id,category_id,title,cover,description,read_count,sort,status')
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->limit(50)
            ->select()
            ->toArray();

        return app()->make(AssetHydrator::class)->hydrateArticleList($this->appendCategoryNames($list));
    }

    protected function normalizePayload(array $data, array $base = []): array
    {
        $content = (string) ($data['content'] ?? $base['content'] ?? '');

        return [
            'category_id' => (int) ($data['category_id'] ?? $base['category_id'] ?? 0),
            'title' => mb_substr(trim((string) ($data['title'] ?? $base['title'] ?? '')), 0, 160),
            'cover' => $this->normalizeCoverField($data['cover'] ?? $base['cover'] ?? ''),
            'description' => mb_substr(trim((string) ($data['description'] ?? $base['description'] ?? '')), 0, 500) ?: null,
            'content' => app()->make(RichTextSanitizer::class)->sanitize($content),
            'sort' => (int) ($data['sort'] ?? $base['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? $base['status'] ?? 1),
        ];
    }

    protected function normalizeCoverField(mixed $value): ?int
    {
        $normalized = app()->make(AssetIdNormalizer::class)->normalizeSingle($value);
        if ($normalized === '') {
            return null;
        }

        if (!is_int($normalized)) {
            throw new BusinessException('请选择有效的封面素材');
        }

        app()->make(AssetService::class)->assertUsableImageAssets([$normalized]);

        return $normalized;
    }

    protected function validateCategoryUsable(int $categoryId): void
    {
        if ($categoryId <= 0) {
            throw new BusinessException('请选择文章分类');
        }

        $category = $this->model(ArticleCategory::class)
            ->where('id', $categoryId)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->find();
        if (!$category) {
            throw new BusinessException('文章分类不存在或已禁用');
        }
    }

    protected function validateAssetRefs(array $data): void
    {
        $ids = $this->collectArticleAssetIds($data);
        app()->make(AssetService::class)->assertUsableAssets($ids);
    }

    /**
     * @return array<int, int>
     */
    protected function collectArticleAssetIds(array $data): array
    {
        $values = [$data['cover'] ?? ''];
        foreach ($this->extractAssetIdsFromHtml((string) ($data['content'] ?? '')) as $id) {
            $values[] = $id;
        }

        return app()->make(AssetIdNormalizer::class)->collectAssetIds($values);
    }

    protected function syncArticleAssetUsage(int $articleId, array $data): void
    {
        $assetService = app()->make(AssetService::class);
        $assetService->syncUsage('article', $articleId, 'cover', [$data['cover'] ?? '']);
        $assetService->syncUsage('article', $articleId, 'content', $this->extractAssetIdsFromHtml((string) ($data['content'] ?? '')));
    }

    /**
     * @return array<int, int>
     */
    protected function extractAssetIdsFromHtml(string $html): array
    {
        if ($html === '' || !str_contains($html, 'data-asset-id')) {
            return [];
        }

        preg_match_all('/\bdata-asset-id=["\']?(\d+)["\']?/i', $html, $matches);
        return array_values(array_unique(array_map('intval', $matches[1] ?? [])));
    }

    protected function findValidArticle(int $id)
    {
        $article = $this->model()->where('id', $id)->whereNull('delete_time')->find();
        if (!$article) {
            throw new BusinessException('文章不存在');
        }

        return $article;
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    protected function appendCategoryNames(array $list): array
    {
        if ($list === []) {
            return [];
        }

        $categoryIds = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'category_id')))));
        $categories = $categoryIds !== []
            ? $this->model(ArticleCategory::class)->whereIn('id', $categoryIds)->column('name', 'id')
            : [];

        foreach ($list as &$item) {
            $item['category_name'] = $categories[(int) ($item['category_id'] ?? 0)] ?? '';
        }
        unset($item);

        return $list;
    }
}
