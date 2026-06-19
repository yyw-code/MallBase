<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\service\admin\WorkspaceService;
use mall_base\base\BaseController;
use mall_base\exception\BusinessException;

/**
 * 后台工作台控制器
 *
 * @extends BaseController<WorkspaceService>
 */
class WorkspaceController extends BaseController
{
    protected string $serviceClass = WorkspaceService::class;

    public function pendingShipmentTodo()
    {
        return $this->success($this->service()->pendingShipmentTodo(), '获取成功');
    }

    public function refundPendingTodo()
    {
        return $this->success($this->service()->refundPendingTodo(), '获取成功');
    }

    public function stockWarningTodo()
    {
        return $this->success($this->service()->stockWarningTodo(), '获取成功');
    }

    public function logisticsConfigTodo()
    {
        return $this->success($this->service()->logisticsConfigTodo(), '获取成功');
    }

    public function smsProviderConfigTodo()
    {
        return $this->success($this->service()->smsProviderConfigTodo(), '获取成功');
    }

    public function shortcuts()
    {
        return $this->success(
            $this->service()->shortcuts($this->adminId()),
            '获取成功',
        );
    }

    public function menuOptions()
    {
        return $this->success(
            $this->service()->menuOptions($this->adminId()),
            '获取成功',
        );
    }

    public function updateShortcuts()
    {
        $shortcuts = $this->request->param('shortcuts', []);
        if (!is_array($shortcuts)) {
            throw new BusinessException('快捷入口参数格式不正确');
        }

        return $this->success(
            $this->service()->updateShortcuts($this->adminId(), $shortcuts),
            '保存成功',
        );
    }

    private function adminId(): int
    {
        $adminId = (int) ($this->request->admin_id ?? 0);
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }

        return $adminId;
    }
}
