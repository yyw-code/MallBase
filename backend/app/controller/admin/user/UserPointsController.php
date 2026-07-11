<?php
declare(strict_types=1);

namespace app\controller\admin\user;

use app\service\admin\user\UserPointsService;
use mall_base\base\BaseController;

/**
 * 后台用户积分控制器
 *
 * @extends BaseController<UserPointsService>
 */
class UserPointsController extends BaseController
{
    protected string $serviceClass = UserPointsService::class;

    public function logs()
    {
        $where = $this->request->param(['user_id', 'type', 'biz_type']);
        [$page, $limit] = $this->getPagination(1, 15);

        return $this->success($this->service()->logs($where, $page, $limit), '获取成功');
    }

    public function adjust()
    {
        $data = $this->request->param(['user_id', 'direction', 'points', 'remark']);
        $result = $this->service()->adjust(
            userId: (int) ($data['user_id'] ?? 0),
            direction: (string) ($data['direction'] ?? ''),
            points: (int) ($data['points'] ?? 0),
            remark: (string) ($data['remark'] ?? ''),
            adminId: (int) ($this->request->admin_id ?? 0),
        );

        return $this->success($result, '调整成功');
    }
}
