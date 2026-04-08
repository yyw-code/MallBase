<?php
declare(strict_types=1);

namespace app\admin\service\goods;

use app\admin\model\goods\Goods;
use app\admin\model\goods\GoodsComment;
use app\admin\model\user\User;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 商品评论服务
 */
class GoodsCommentService extends BaseService
{
    /**
     * 默认 Model 类名
     */
    protected string $modelClass = GoodsComment::class;

    /**
     * 构建列表查询条件
     */
    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(!empty($where['goods_id']), function ($q) use ($where) {
                $q->where('goods_id', $where['goods_id']);
            })
            ->when(!empty($where['rating']), function ($q) use ($where) {
                $q->where('rating', $where['rating']);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', $where['status']);
            });
    }

    /**
     * 获取评论列表
     *
     * @param array $where 搜索条件
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array{total: int, list: array}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $query = $this->buildListQuery($where);
        $list = $query->order('id', 'desc')->page($page, $limit)->select();
        $total = $this->buildListQuery($where)->count();

        $listArray = $list->toArray();
        if (!empty($listArray)) {
            // 批量获取用户昵称（避免 N+1）
            $userIds = array_unique(array_column($listArray, 'user_id'));
            $users = $this->model(User::class)
                ->whereIn('id', $userIds)
                ->column('nickname', 'id');

            // 批量获取商品名称（避免 N+1）
            $goodsIds = array_unique(array_column($listArray, 'goods_id'));
            $goodsList = $this->model(Goods::class)
                ->whereIn('id', $goodsIds)
                ->column('name', 'id');

            foreach ($listArray as &$item) {
                $item['user_nickname'] = $users[$item['user_id']] ?? '已注销用户';
                $item['goods_name'] = $goodsList[$item['goods_id']] ?? '已删除商品';
            }
        }

        $list = $listArray;
        return compact('total', 'list');
    }

    /**
     * 获取评论详情
     *
     * @param int $id 评论 ID
     * @return array 评论详情（含用户和商品信息）
     * @throws BusinessException 评论不存在时抛出
     */
    public function getInfo(int $id): array
    {
        $comment = $this->model()->find($id);

        if (!$comment) {
            throw new BusinessException('评论不存在');
        }

        $result = $comment->toArray();

        // 获取用户信息
        $user = $this->model(User::class)->find($result['user_id']);
        $result['user_nickname'] = $user ? $user->nickname : '已注销用户';

        // 获取商品信息
        $goods = $this->model(Goods::class)->find($result['goods_id']);
        $result['goods_name'] = $goods ? $goods->name : '已删除商品';

        return $result;
    }

    /**
     * 回复评论
     *
     * @param int $id 评论 ID
     * @param string $replyContent 回复内容
     * @return bool 回复成功返回 true
     * @throws BusinessException 评论不存在时抛出
     */
    public function reply(int $id, string $replyContent): bool
    {
        $comment = $this->model()->find($id);

        if (!$comment) {
            throw new BusinessException('评论不存在');
        }

        $comment->save([
            'reply_content' => $replyContent,
            'reply_time' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * 更新评论状态（显示/隐藏）
     *
     * @param int $id 评论 ID
     * @param int $status 状态（1=显示，0=隐藏）
     * @return bool 更新成功返回 true
     * @throws BusinessException 评论不存在时抛出
     */
    public function updateStatus(int $id, int $status): bool
    {
        $comment = $this->model()->find($id);

        if (!$comment) {
            throw new BusinessException('评论不存在');
        }

        $comment->save(['status' => $status]);

        return true;
    }

    /**
     * 删除评论
     *
     * @param int $id 评论 ID
     * @return bool 删除成功返回 true
     * @throws BusinessException 评论不存在时抛出
     */
    public function delete(int $id): bool
    {
        $comment = $this->model()->find($id);

        if (!$comment) {
            throw new BusinessException('评论不存在');
        }

        $comment->delete();

        return true;
    }
}
