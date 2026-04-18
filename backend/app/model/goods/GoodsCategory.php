<?php
declare(strict_types=1);

namespace app\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品分类模型
 */
class GoodsCategory extends BaseModel
{
    protected $name = 'goods_category';
    protected array $append = ['image_full_url'];

    /**
     * 分类图片完整地址
     */
    public function getImageFullUrlAttr($value, $data): string
    {
        return buildUploadUrl($data['image'] ?? '');
    }
}
