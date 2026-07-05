<?php
declare(strict_types=1);

namespace app\validate\admin\client;

use think\Validate;

/**
 * 客户端页面库验证器
 */
class ClientPageValidate extends Validate
{
    protected $rule = [
        'name' => 'require|max:80',
        'path' => 'require|max:255',
        'page_type' => 'require|in:tab,page,subpackage',
        'category' => 'max:30|regex:/^[a-z][a-z0-9_]{0,29}$/',
        'package_root' => 'max:120',
        'need_login' => 'in:0,1',
        'source' => 'in:auto,manual,system',
        'remark' => 'max:255',
        'sort' => 'integer|egt:0',
        'status' => 'in:0,1',
    ];

    protected $message = [
        'name.require' => '页面名称不能为空',
        'name.max' => '页面名称最多80个字符',
        'path.require' => '页面路径不能为空',
        'path.max' => '页面路径最多255个字符',
        'page_type.require' => '页面类型不能为空',
        'page_type.in' => '页面类型不正确',
        'category.max' => '页面分类最多30个字符',
        'category.regex' => '页面分类只能使用小写字母、数字和下划线，且必须以字母开头',
        'package_root.max' => '分包 root 最多120个字符',
        'need_login.in' => '登录要求必须是0或1',
        'source.in' => '页面来源不正确',
        'remark.max' => '备注最多255个字符',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'status.in' => '状态必须是0或1',
    ];

    protected $scene = [
        'create' => ['name', 'path', 'page_type', 'category', 'package_root', 'need_login', 'source', 'remark', 'sort', 'status'],
        'update' => ['name', 'path', 'page_type', 'category', 'package_root', 'need_login', 'source', 'remark', 'sort', 'status'],
    ];
}
