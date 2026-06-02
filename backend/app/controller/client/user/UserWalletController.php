<?php
declare(strict_types=1);

namespace app\controller\client\user;

use app\service\client\user\UserWalletService;
use mall_base\base\BaseController;
use mall_base\exception\BusinessException;

/**
 * 前台用户余额控制器
 *
 * @extends BaseController<UserWalletService>
 */
class UserWalletController extends BaseController
{
    protected string $serviceClass = UserWalletService::class;

    public function info()
    {
        $userId = $this->userId();
        return $this->success($this->service()->info($userId), '获取成功');
    }

    public function logs()
    {
        $userId = $this->userId();
        $where = $this->request->param(['type', 'range', 'biz_type']);
        [$page, $limit] = $this->getPagination(1, 20);

        return $this->success($this->service()->logs($userId, $where, $page, $limit), '获取成功');
    }

    private function userId(): int
    {
        $userId = (int) ($this->request->user_id ?? 0);
        if ($userId <= 0) {
            throw new BusinessException('未登录');
        }
        return $userId;
    }
}
