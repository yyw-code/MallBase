<?php
declare(strict_types=1);

namespace app\validate\client\user;

use think\Validate;

/**
 * 前台用户验证器
 */
class UserValidate extends Validate
{
    protected $rule = [
        'mobile|手机号' => 'mobile|max:20',
        'email|邮箱' => 'email|max:100',
        'password|密码' => 'require|max:32|min:6',
        'nickname|昵称' => 'max:50',
        'real_name|真实姓名' => 'max:50',
        'gender|性别' => 'in:0,1,2',
        'status|状态' => 'in:0,1',
        'mobile_verified|手机验证' => 'in:0,1',
    ];

    protected $scene = [
        'create' => ['mobile', 'email', 'password', 'nickname', 'status'], // 后台 admin 创建仍允许 email
        'update' => ['mobile', 'email', 'nickname', 'real_name', 'gender', 'birthday', 'status'],
    ];
}
