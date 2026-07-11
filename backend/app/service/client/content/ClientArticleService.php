<?php
declare(strict_types=1);

namespace app\service\client\content;

use app\model\content\Article;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use think\facade\Db;

/**
 * C端文章服务
 * @extends BaseService<Article>
 */
class ClientArticleService extends BaseService
{
    protected string $modelClass = Article::class;

    protected function buildListQuery(array $where)
    {
        return $this->saleableQuery()
            ->when(($where['keyword'] ?? null) !== null && $where['keyword'] !== '', function ($q) use ($where) {
                $keyword = trim((string) $where['keyword']);
                $q->whereLike('a.title|a.description', '%' . $keyword . '%');
            })
            ->when(!empty($where['category_id']), function ($q) use ($where) {
                $q->where('a.category_id', (int) $where['category_id']);
            });
    }

    public function list(array $where, int $page, int $limit): array
    {
        $list = $this->buildListQuery($where)
            ->field('a.id,a.category_id,a.title,a.cover,a.description,a.read_count,a.sort,a.create_time,a.update_time,c.name AS category_name')
            ->order('a.sort', 'asc')
            ->order('a.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $list = app()->make(AssetHydrator::class)->hydrateArticleList($list);

        $total = $this->buildListQuery($where)->count();

        return compact('total', 'list');
    }

    public function detail(int $id, int $userId = 0): array
    {
        $article = $this->saleableQuery()
            ->where('a.id', $id)
            ->field('a.*, c.name AS category_name')
            ->find();
        if (!$article) {
            throw new BusinessException('文章不存在或已下架');
        }

        $this->recordRead($id, $userId);

        $data = $article->toArray();
        $data['read_count'] = (int) ($data['read_count'] ?? 0) + 1;

        return app()->make(AssetHydrator::class)->hydrateArticleDetail($data);
    }

    protected function saleableQuery()
    {
        return $this->model()
            ->alias('a')
            ->leftJoin('mb_article_category c', 'c.id = a.category_id')
            ->where('a.status', 1)
            ->where('c.status', 1)
            ->whereNull('a.delete_time')
            ->whereNull('c.delete_time');
    }

    protected function recordRead(int $articleId, int $userId): void
    {
        $userId = max(0, $userId);
        $now = date('Y-m-d H:i:s');
        $this->transaction(function () use ($articleId, $userId, $now) {
            $affected = $this->model()
                ->where('id', $articleId)
                ->where('status', 1)
                ->whereNull('delete_time')
                ->inc('read_count', 1)
                ->update();
            if ($affected !== 1) {
                throw new BusinessException('文章不存在或已下架');
            }

            Db::execute(
                'INSERT INTO `mb_article_read_record` (`article_id`, `user_id`, `read_count`, `first_read_time`, `last_read_time`, `create_time`, `update_time`)
                 VALUES (?, ?, 1, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `read_count` = `read_count` + 1, `last_read_time` = VALUES(`last_read_time`), `update_time` = VALUES(`update_time`)',
                [$articleId, $userId, $now, $now, $now, $now]
            );

            return true;
        });
    }
}
