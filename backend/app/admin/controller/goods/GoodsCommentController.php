<?php
declare(strict_types=1);

namespace app\admin\controller\goods;

use app\service\admin\goods\GoodsCommentService;
use app\admin\validate\goods\GoodsCommentValidate;
use mall_base\base\BaseController;

/**
 * 商品评论控制器
 * @extends BaseController<GoodsCommentService>
 */
class GoodsCommentController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = GoodsCommentService::class;

    /**
     * 获取评论列表
     */
    public function list()
    {
        $where = $this->request->param(['goods_id', 'rating', 'status']);

        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    /**
     * 获取评论详情
     */
    public function info()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $info = $this->service()->getInfo((int) $id);
        return $this->success($info, '获取成功');
    }

    /**
     * 回复评论
     */
    public function reply()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['reply_content']);

        $this->validate($data, GoodsCommentValidate::class . '.reply');

        $this->service()->reply((int) $id, $data['reply_content']);
        return $this->success(null, '回复成功');
    }

    /**
     * 更新评论状态（显示/隐藏）
     */
    public function updateStatus()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['status']);

        if (!isset($data['status'])) {
            return $this->error('状态不能为空');
        }

        $this->service()->updateStatus((int) $id, (int) $data['status']);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除评论
     */
    public function delete()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete((int) $id);
        return $this->success(null, '删除成功');
    }
}
