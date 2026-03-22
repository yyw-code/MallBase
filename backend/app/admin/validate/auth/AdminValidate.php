<?php

declare (strict_types=1);

namespace app\admin\validate\auth;

use think\Validate;

/**
 * 管理员验证器
 */
class AdminValidate extends Validate
{
    /**
     * 验证规则
     *
     * @var array
     */
    protected $rule = [
        'username|用户名' => 'require|alphaNum|max:20|min:3',
        'password|密码' => 'require|max:32|min:6',
        'password_confirm|确认密码' => 'require|confirm:password',
        'nickname|昵称' => 'max:50',
        'avatar|头像' => 'max:255',
        'email|邮箱' => 'email|max:100',
        'mobile|手机号' => 'mobile|max:20',
        'status|状态' => 'in:0,1',
        'remark|备注' => 'max:500',
        'role_ids|角色ID' => 'array',
    ];

    /**
     * 验证场景
     *
     * @var array
     */
    protected $scene = [
        'login' => ['username', 'password'],
        'create' => ['username', 'password', 'password_confirm', 'nickname', 'avatar', 'email', 'mobile', 'status', 'remark', 'role_ids'],
        'update' => ['username', 'nickname', 'avatar', 'email', 'mobile', 'status', 'remark', 'role_ids'],
        'resetPassword' => ['password'],
        'changePassword' => ['password', 'password_confirm'],
    ];

    /**
     * 手机号验证规则
     *
     * @param mixed $value
     * @param mixed $rule
     * @param array $data
     * @return bool|string
     */
    protected function mobile($value, $rule, $data = [])
    {
        // 为空时跳过验证
        if (empty($value)) {
            return true;
        }

        return preg_match('/^1[3-9]\d{9}$/', (string)$value) ? true : '手机号格式不正确';
    }
}