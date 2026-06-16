<?php

declare(strict_types=1);

namespace app\controller\admin\sms;

use app\service\admin\sms\SmsProviderService;
use app\validate\admin\sms\SmsProviderValidate;
use mall_base\base\BaseController;

/**
 * 短信服务商控制器
 *
 * @extends BaseController<SmsProviderService>
 */
class ProviderController extends BaseController
{
    protected string $serviceClass = SmsProviderService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'driver', 'status']);
        [$page, $limit] = $this->getPagination(1, 15);
        $result = $this->service()->getList($where, $page, $limit);
        return $this->success($result, '获取成功');
    }

    public function info($id)
    {
        $info = $this->service()->getInfo((int) $id);
        return $this->success($info, '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['name', 'driver', 'access_key_id', 'access_key_secret', 'region', 'is_default', 'status', 'remark', 'sort']);
        $this->validate($data, SmsProviderValidate::class . '.create');
        $id = $this->service()->create($data);
        return $this->success(['id' => $id], '创建成功');
    }

    public function update($id)
    {
        $data = $this->request->param(['name', 'driver', 'access_key_id', 'access_key_secret', 'region', 'is_default', 'status', 'remark', 'sort']);
        $this->validate($data, SmsProviderValidate::class . '.update');
        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    public function delete($id)
    {
        $this->service()->delete((int) $id);
        return $this->success(null, '删除成功');
    }

    public function test($id)
    {
        $result = $this->service()->testConnection((int) $id);
        return $this->success($result, $result['message'] ?? '');
    }
}
