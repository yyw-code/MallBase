<?php
declare(strict_types=1);

namespace app\validate\admin\goods;

use think\Validate;

/**
 * 商品评论验证器
 */
class GoodsCommentValidate extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'reply_content' => 'require|max:500',
    ];

    /**
     * 错误消息
     */
    protected $message = [
        'reply_content.require' => '回复内容不能为空',
        'reply_content.max' => '回复内容最多500个字符',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'reply' => ['reply_content'],
    ];
}
