<?php

declare(strict_types=1);

namespace app\admin\validate\user;

use think\Validate;

/**
 * 前台用户验证器（后台管理用）
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
        'birthday|生日' => 'date',
        'status|状态' => 'in:0,1',
        'remark|备注' => 'max:500',
    ];

    protected $scene = [
        'create' => ['mobile', 'email', 'password', 'nickname', 'real_name', 'gender', 'birthday', 'status', 'remark'],
        'update' => ['mobile', 'email', 'nickname', 'real_name', 'gender', 'birthday', 'status', 'remark'],
    ];
}