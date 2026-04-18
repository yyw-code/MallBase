<?php
declare(strict_types=1);

namespace app\controller\admin\user;

use app\service\admin\user\UserAddressService;
use app\validate\admin\user\UserAddressValidate;
use mall_base\base\BaseController;

/**
 * @extends BaseController<UserAddressService>
 */
class UserAddressController extends BaseController
{
    protected string $serviceClass = UserAddressService::class;

    public function list()
    {
        $where = $this->request->param(['keyword', 'user_id', 'region_status', 'is_default']);
        [$page, $limit] = $this->getPagination(1, 20);
        return $this->success($this->service()->getList($where, $page, $limit), '获取成功');
    }

    public function info($id)
    {
        return $this->success($this->service()->getInfo((int) $id), '获取成功');
    }

    public function create()
    {
        $data = $this->request->param(['user_id', 'receiver_name', 'receiver_mobile', 'province_id', 'city_id', 'district_id', 'street_id', 'address_detail', 'tag', 'is_default']);
        $this->validate($data, UserAddressValidate::class . '.create');
        return $this->success(['id' => $this->service()->create($data)], '创建成功');
    }

    public function update($id)
    {
        $data = $this->request->param(['user_id', 'receiver_name', 'receiver_mobile', 'province_id', 'city_id', 'district_id', 'street_id', 'address_detail', 'tag', 'is_default']);
        $this->validate($data, UserAddressValidate::class . '.update');
        $this->service()->update((int) $id, $data);
        return $this->success(null, '更新成功');
    }

    public function delete($id)
    {
        $this->service()->delete((int) $id);
        return $this->success(null, '删除成功');
    }

    public function setDefault($id)
    {
        $this->service()->setDefault((int) $id);
        return $this->success(null, '设置成功');
    }

    public function refreshInvalid()
    {
        return $this->success($this->service()->refreshInvalidData(), '更新成功');
    }
}
