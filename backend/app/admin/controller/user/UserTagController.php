<?php
declare(strict_types=1);

namespace app\admin\controller\user;

use app\admin\service\user\UserTagService;
use app\admin\validate\user\UserTagValidate;
use mall_base\base\BaseController;

/**
 * 用户标签控制器
 * @extends BaseController<UserTagService>
 */
class UserTagController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = UserTagService::class;

    /**
     * 获取标签列表
     */
    public function list()
    {
        $where = $this->request->param(['name', 'status']);

        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    /**
     * 获取标签详情
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
     * 创建标签
     */
    public function create()
    {
        $data = $this->request->param([
            'name', 'color', 'sort', 'status',
        ]);

        $this->validate($data, UserTagValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新标签
     */
    public function update()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param([
            'name', 'color', 'sort', 'status',
        ]);

        $this->validate($data, UserTagValidate::class . '.update');

        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除标签
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

    /**
     * 更新标签状态
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
     * 获取标签下的用户数
     */
    public function getUserCount()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $count = $this->service()->getUserCount((int) $id);
        return $this->success(['count' => $count], '获取成功');
    }

    /**
     * 批量给用户打标签
     */
    public function batchSetUsers()
    {
        $data = $this->request->param(['tag_id', 'user_ids']);

        if (empty($data['tag_id'])) {
            return $this->error('标签ID不能为空');
        }

        if (empty($data['user_ids']) || !is_array($data['user_ids'])) {
            return $this->error('用户ID不能为空');
        }

        $this->service()->batchSetUsers((int) $data['tag_id'], $data['user_ids']);
        return $this->success(null, '设置成功');
    }

    /**
     * 移除用户标签
     */
    public function removeUser()
    {
        $data = $this->request->param(['tag_id', 'user_id']);

        if (empty($data['tag_id'])) {
            return $this->error('标签ID不能为空');
        }

        if (empty($data['user_id'])) {
            return $this->error('用户ID不能为空');
        }

        $this->service()->removeUser((int) $data['tag_id'], (int) $data['user_id']);
        return $this->success(null, '移除成功');
    }

    /**
     * 获取用户的所有标签
     */
    public function getUserTags()
    {
        $userId = $this->request->param('user_id');

        if (empty($userId)) {
            return $this->error('用户ID不能为空');
        }

        $tags = $this->service()->getUserTags((int) $userId);
        return $this->success($tags, '获取成功');
    }
}
