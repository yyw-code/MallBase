<?php
declare(strict_types=1);

namespace app\controller\admin\setting;

use app\service\admin\setting\FreightTemplateService;
use app\validate\admin\setting\FreightTemplateValidate;
use mall_base\base\BaseController;

/**
 * @extends BaseController<FreightTemplateService>
 */
class FreightTemplateController extends BaseController
{
    protected string $serviceClass = FreightTemplateService::class;

    public function list()
    {
        $where = $this->request->param(['name', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);
        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function info($id)
    {
        return $this->success($this->service()->getInfo((int) $id), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['name', 'charge_type', 'default_first_amount', 'default_first_fee', 'default_continue_amount', 'default_continue_fee', 'status', 'remark', 'rules']);
        $this->validate($data, FreightTemplateValidate::class . '.create');
        return $this->success(['id' => $this->service()->create($data)], '创建成功');
    }

    public function update($id)
    {
        $data = $this->request->param(['name', 'charge_type', 'default_first_amount', 'default_first_fee', 'default_continue_amount', 'default_continue_fee', 'status', 'remark', 'rules']);
        $this->validate($data, FreightTemplateValidate::class . '.update');
        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    public function delete($id)
    {
        $this->service()->delete((int) $id);
        return $this->success(null, '删除成功');
    }

    public function updateStatus($id)
    {
        $data = $this->request->param(['status']);
        $this->validate($data, FreightTemplateValidate::class . '.status');
        $this->service()->updateStatus((int) $id, (int) $data['status']);
        return $this->success(null, '更新成功');
    }

    public function refreshInvalid()
    {
        return $this->success($this->service()->refreshInvalidData(), '更新成功');
    }
}
