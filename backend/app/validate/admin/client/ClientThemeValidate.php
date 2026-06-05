<?php
declare(strict_types=1);

namespace app\validate\admin\client;

use think\Validate;

/**
 * 客户端主题方案验证器
 */
class ClientThemeValidate extends Validate
{
    protected $rule = [
        'name' => 'require|max:80',
        'type' => 'require|in:light,dark,custom',
        'tokens' => 'require|array',
        'status' => 'in:0,1',
        'sort' => 'integer|egt:0',
    ];

    protected $message = [
        'name.require' => '主题名称不能为空',
        'name.max' => '主题名称最多80个字符',
        'type.require' => '主题类型不能为空',
        'type.in' => '主题类型不正确',
        'tokens.require' => '主题变量不能为空',
        'tokens.array' => '主题变量必须是数组',
        'status.in' => '状态必须是0或1',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
    ];

    protected $scene = [
        'create' => ['name', 'type', 'tokens', 'status', 'sort'],
        'update' => ['name', 'type', 'tokens', 'status', 'sort'],
    ];
}
