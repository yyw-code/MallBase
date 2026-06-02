<?php
declare(strict_types=1);

namespace app\controller\admin\marketing;

use app\service\admin\marketing\RechargePackageService;
use app\validate\admin\marketing\RechargePackageValidate;
use mall_base\base\BaseController;

/**
 * 充值套餐控制器
 *
 * @extends BaseController<RechargePackageService>
 */
class RechargePackageController extends BaseController
{
    protected string $serviceClass = RechargePackageService::class;

    public function list()
    {
        $where = $this->request->param(['name', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);

        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function info()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        return $this->success($this->service()->getInfo($id), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['name', 'pay_amount', 'gift_amount', 'background_image', 'sort', 'status', 'remark']);
        $this->validate($data, RechargePackageValidate::class . '.create');

        $id = $this->service()->create($data);

        return $this->success(['id' => $id], '创建成功');
    }

    public function update()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $data = $this->request->param(['name', 'pay_amount', 'gift_amount', 'background_image', 'sort', 'status', 'remark']);
        $this->validate($data, RechargePackageValidate::class . '.update');
        $this->service()->update($id, $data);

        return $this->success(null, '更新成功');
    }

    public function delete()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $this->service()->delete($id);

        return $this->success(null, '删除成功');
    }

    public function updateStatus()
    {
        $id = (int) $this->request->param('id');
        if ($id <= 0) {
            return $this->error('ID不能为空');
        }

        $status = (int) $this->request->param('status');
        $this->service()->updateStatus($id, $status);

        return $this->success(null, '更新成功');
    }
}
