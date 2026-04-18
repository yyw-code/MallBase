<?php
declare(strict_types=1);

namespace app\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品图片模型
 */
class GoodsImage extends BaseModel
{
    protected $name = 'goods_image';
    protected array $append = ['full_url'];

    public function getFullUrlAttr($value, $data): string
    {
        return buildUploadUrl($data['url'] ?? '');
    }
}
