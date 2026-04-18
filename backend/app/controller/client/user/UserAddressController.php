<?php
declare(strict_types=1);

namespace app\controller\client\user;

use app\service\client\UserAddressService;
use app\validate\client\user\UserAddressValidate;
use mall_base\base\BaseController;

/**
 * @extends BaseController<UserAddressService>
 */
class UserAddressController extends BaseController
{
    protected string $serviceClass = UserAddressService::class;

    public function list()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        return $this->success($this->service()->getMyList($userId), '获取成功');
    }

    public function info($id)
    {
        $userId = (int) ($this->request->user_id ?? 0);
        return $this->success($this->service()->getMyInfo($userId, (int) $id), '获取成功');
    }

    public function create()
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $data = $this->request->param(['receiver_name', 'receiver_mobile', 'province_id', 'city_id', 'district_id', 'street_id', 'address_detail', 'tag', 'is_default']);
        $this->validate($data, UserAddressValidate::class . '.create');
        return $this->success(['id' => $this->service()->create($userId, $data)], '创建成功');
    }

    public function update($id)
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $data = $this->request->param(['receiver_name', 'receiver_mobile', 'province_id', 'city_id', 'district_id', 'street_id', 'address_detail', 'tag', 'is_default']);
        $this->validate($data, UserAddressValidate::class . '.update');
        $this->service()->update($userId, (int) $id, $data);
        return $this->success(null, '更新成功');
    }

    public function delete($id)
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $this->service()->delete($userId, (int) $id);
        return $this->success(null, '删除成功');
    }

    public function setDefault($id)
    {
        $userId = (int) ($this->request->user_id ?? 0);
        $this->service()->setDefault($userId, (int) $id);
        return $this->success(null, '设置成功');
    }
}
