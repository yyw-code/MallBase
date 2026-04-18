<?php
declare(strict_types=1);

namespace app\validate\admin\user;

use think\Validate;

class UserAddressValidate extends Validate
{
    protected $rule = [
        'user_id' => 'require|integer|min:1',
        'receiver_name' => 'require|max:50',
        'receiver_mobile' => ['require', 'regex' => '/^1[3-9]\d{9}$/'],
        'province_id' => 'require|integer|min:1',
        'city_id' => 'require|integer|min:1',
        'district_id' => 'require|integer|min:1',
        'street_id' => 'require|integer|min:1',
        'address_detail' => 'require|max:255',
        'tag' => 'max:20',
        'is_default' => 'in:0,1',
    ];

    protected $message = [
        'user_id.require' => '用户不能为空',
        'receiver_name.require' => '收货人不能为空',
        'receiver_mobile.require' => '联系电话不能为空',
        'receiver_mobile.regex' => '联系电话格式不正确',
        'province_id.require' => '省份不能为空',
        'city_id.require' => '城市不能为空',
        'district_id.require' => '区县不能为空',
        'street_id.require' => '街道不能为空',
        'address_detail.require' => '详细地址不能为空',
    ];

    protected $scene = [
        'create' => ['user_id', 'receiver_name', 'receiver_mobile', 'province_id', 'city_id', 'district_id', 'street_id', 'address_detail', 'tag', 'is_default'],
        'update' => ['user_id', 'receiver_name', 'receiver_mobile', 'province_id', 'city_id', 'district_id', 'street_id', 'address_detail', 'tag', 'is_default'],
    ];
}
