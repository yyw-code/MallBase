<?php
declare(strict_types=1);

namespace app\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品SKU模型
 */
class GoodsSku extends BaseModel
{
    protected $name = 'goods_sku';
    protected array $append = ['image_full_url'];

    public function getImageFullUrlAttr($value, $data): string
    {
        return buildUploadUrl($data['image'] ?? '');
    }
}
