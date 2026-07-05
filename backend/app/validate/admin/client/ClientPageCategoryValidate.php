<?php
declare(strict_types=1);

namespace app\validate\admin\client;

use think\Validate;

/**
 * 客户端页面分类验证器
 */
class ClientPageCategoryValidate extends Validate
{
    protected $rule = [
        'code' => 'require|max:30|regex:/^[a-z][a-z0-9_]{0,29}$/',
        'name' => 'require|max:80',
        'description' => 'max:255',
        'sort' => 'integer|egt:0',
        'status' => 'in:0,1',
    ];

    protected $message = [
        'code.require' => '分类编码不能为空',
        'code.max' => '分类编码最多30个字符',
        'code.regex' => '分类编码只能使用小写字母、数字和下划线，且必须以字母开头',
        'name.require' => '分类名称不能为空',
        'name.max' => '分类名称最多80个字符',
        'description.max' => '分类描述最多255个字符',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'status.in' => '状态必须是0或1',
    ];

    protected $scene = [
        'create' => ['code', 'name', 'description', 'sort', 'status'],
        'update' => ['code', 'name', 'description', 'sort', 'status'],
    ];
}
