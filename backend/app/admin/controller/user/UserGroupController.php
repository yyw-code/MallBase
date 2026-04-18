<?php
declare(strict_types=1);

namespace app\admin\controller\user;

use app\service\admin\user\UserGroupService;
use app\admin\validate\user\UserGroupValidate;
use mall_base\base\BaseController;

/**
 * 用户分组控制器
 * @extends BaseController<UserGroupService>
 */
class UserGroupController extends BaseController
{
    /**
     * 默认 Service 类名
     */
    protected string $serviceClass = UserGroupService::class;

    /**
     * 获取分组列表
     */
    public function list()
    {
        $where = $this->request->param(['name', 'code', 'status']);

        [$page, $limit] = $this->getPagination(1, 15);

        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    /**
     * 获取分组详情
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
     * 创建分组
     */
    public function create()
    {
        $data = $this->request->param([
            'name', 'code', 'description', 'color', 'sort', 'status',
        ]);

        $this->validate($data, UserGroupValidate::class . '.create');

        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    /**
     * 更新分组
     */
    public function update()
    {
        $id = $this->request->param('id');

        if (empty($id)) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param([
            'name', 'code', 'description', 'color', 'sort', 'status',
        ]);

        $this->validate($data, UserGroupValidate::class . '.update');

        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    /**
     * 删除分组
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
     * 更新分组状态
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
     * 获取分组下的用户数
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
     * 批量设置用户分组
     */
    public function batchSetUsers()
    {
        $data = $this->request->param(['group_id', 'user_ids']);

        if (empty($data['group_id'])) {
            return $this->error('分组ID不能为空');
        }

        if (empty($data['user_ids']) || !is_array($data['user_ids'])) {
            return $this->error('用户ID不能为空');
        }

        $this->service()->batchSetUsers((int) $data['group_id'], $data['user_ids']);
        return $this->success(null, '设置成功');
    }

    /**
     * 移除用户分组
     */
    public function removeUser()
    {
        $data = $this->request->param(['group_id', 'user_id']);

        if (empty($data['group_id'])) {
            return $this->error('分组ID不能为空');
        }

        if (empty($data['user_id'])) {
            return $this->error('用户ID不能为空');
        }

        $this->service()->removeUser((int) $data['group_id'], (int) $data['user_id']);
        return $this->success(null, '移除成功');
    }

    /**
     * 获取用户的所有分组
     */
    public function getUserGroups()
    {
        $userId = $this->request->param('user_id');

        if (empty($userId)) {
            return $this->error('用户ID不能为空');
        }

        $groups = $this->service()->getUserGroups((int) $userId);
        return $this->success($groups, '获取成功');
    }
}
