<?php
declare(strict_types=1);

namespace app\validate\client\user;

use think\Validate;

class UserAddressValidate extends Validate
{
    protected $rule = [
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

    protected $scene = [
        'create' => ['receiver_name', 'receiver_mobile', 'province_id', 'city_id', 'district_id', 'street_id', 'address_detail', 'tag', 'is_default'],
        'update' => ['receiver_name', 'receiver_mobile', 'province_id', 'city_id', 'district_id', 'street_id', 'address_detail', 'tag', 'is_default'],
    ];
}
