<?php
declare(strict_types=1);

namespace app\validate\admin\client;

use think\Validate;

/**
 * 客户端装修方案验证器
 */
class ClientDecorationSchemeValidate extends Validate
{
    protected $rule = [
        'type' => 'require|in:home,profile,tabbar',
        'name' => 'require|max:80',
        'description' => 'max:255',
        'schema' => 'require|array',
        'tabbar_mode' => 'in:native,custom',
        'sort' => 'integer|egt:0',
        'status' => 'in:0,1',
    ];

    protected $message = [
        'type.require' => '方案类型不能为空',
        'type.in' => '方案类型不正确',
        'name.require' => '方案名称不能为空',
        'name.max' => '方案名称最多80个字符',
        'description.max' => '方案说明最多255个字符',
        'schema.require' => '装修配置不能为空',
        'schema.array' => '装修配置必须是数组',
        'tabbar_mode.in' => '底部导航模式不正确',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'status.in' => '状态必须是0或1',
    ];

    protected $scene = [
        'create' => ['type', 'name', 'description', 'schema', 'tabbar_mode', 'sort', 'status'],
        'update' => ['type', 'name', 'description', 'schema', 'tabbar_mode', 'sort', 'status'],
    ];
}
