<?php
declare(strict_types=1);

namespace app\controller\client\distribution;

use app\service\client\distribution\DistributionCenterService;
use mall_base\base\BaseController;
use mall_base\exception\BusinessException;

/**
 * 客户端分销中心控制器
 *
 * @extends BaseController<DistributionCenterService>
 */
class DistributionController extends BaseController
{
    protected string $serviceClass = DistributionCenterService::class;

    public function summary()
    {
        return $this->success($this->service()->summary($this->userId()), '获取成功');
    }

    public function commissions()
    {
        $where = $this->request->param(['status']);
        [$page, $limit] = $this->getPagination(1, 20);
        return $this->success($this->service()->commissions($this->userId(), $where, $page, $limit), '获取成功');
    }

    public function logs()
    {
        $where = $this->request->param(['direction']);
        [$page, $limit] = $this->getPagination(1, 20);
        return $this->success($this->service()->logs($this->userId(), $where, $page, $limit), '获取成功');
    }

    public function team()
    {
        $level = (int) $this->request->param('level', 1);
        [$page, $limit] = $this->getPagination(1, 20);
        return $this->success($this->service()->team($this->userId(), $level, $page, $limit), '获取成功');
    }

    public function withdraws()
    {
        $where = $this->request->param(['status']);
        [$page, $limit] = $this->getPagination(1, 20);
        return $this->success($this->service()->withdraws($this->userId(), $where, $page, $limit), '获取成功');
    }

    public function applyWithdraw()
    {
        $data = $this->request->param(['amount', 'account_type', 'account_name', 'account_no']);
        $id = $this->service()->applyWithdraw(
            userId: $this->userId(),
            amount: (string) ($data['amount'] ?? ''),
            accountType: (string) ($data['account_type'] ?? 'offline'),
            accountName: (string) ($data['account_name'] ?? ''),
            accountNo: (string) ($data['account_no'] ?? ''),
        );
        return $this->success(['id' => $id], '提交成功');
    }

    public function bindInvite()
    {
        $this->service()->bindInvite($this->userId(), (string) $this->request->param('invite_code', ''));
        return $this->success(null, '绑定成功');
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
