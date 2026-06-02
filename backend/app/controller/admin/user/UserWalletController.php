<?php
declare(strict_types=1);

namespace app\controller\admin\user;

use app\service\admin\user\UserWalletService;
use mall_base\base\BaseController;

/**
 * 后台用户余额控制器
 *
 * @extends BaseController<UserWalletService>
 */
class UserWalletController extends BaseController
{
    protected string $serviceClass = UserWalletService::class;

    public function logs()
    {
        $where = $this->request->param(['user_id', 'type', 'biz_type']);
        [$page, $limit] = $this->getPagination(1, 15);

        return $this->success($this->service()->logs($where, $page, $limit), '获取成功');
    }

    public function adjust()
    {
        $data = $this->request->param(['user_id', 'direction', 'amount', 'remark']);
        $result = $this->service()->adjust(
            userId: (int) ($data['user_id'] ?? 0),
            direction: (string) ($data['direction'] ?? ''),
            amount: (string) ($data['amount'] ?? ''),
            remark: (string) ($data['remark'] ?? ''),
            adminId: (int) ($this->request->admin_id ?? 0),
        );

        return $this->success($result, '调整成功');
    }
}
