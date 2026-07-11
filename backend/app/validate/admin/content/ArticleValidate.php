<?php
declare(strict_types=1);

namespace app\validate\admin\content;

use think\Validate;

/**
 * 文章验证器
 */
class ArticleValidate extends Validate
{
    protected $rule = [
        'category_id' => 'require|integer|gt:0',
        'title' => 'require|max:160',
        'cover' => 'max:255',
        'description' => 'max:500',
        'content' => 'max:16777215',
        'sort' => 'integer|egt:0',
        'status' => 'in:0,1',
    ];

    protected $message = [
        'category_id.require' => '请选择文章分类',
        'category_id.integer' => '文章分类必须是整数',
        'category_id.gt' => '请选择文章分类',
        'title.require' => '文章标题不能为空',
        'title.max' => '文章标题最多160个字符',
        'cover.max' => '封面最多255个字符',
        'description.max' => '文章描述最多500个字符',
        'content.max' => '文章内容超过系统限制',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'status.in' => '状态必须是0或1',
    ];

    protected $scene = [
        'create' => ['category_id', 'title', 'cover', 'description', 'content', 'sort', 'status'],
        'update' => ['category_id', 'title', 'cover', 'description', 'content', 'sort', 'status'],
    ];
}
