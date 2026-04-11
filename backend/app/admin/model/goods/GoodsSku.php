<?php
declare(strict_types=1);

namespace app\admin\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品SKU模型
 */
class GoodsSku extends BaseModel
{
    protected $name = 'goods_sku';
    protected array $append = ['image_full_url'];

    /**
     * 搜索器-按商品ID搜索
     */
    public function searchGoodsIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('goods_id', $value);
        }
    }

    public function getImageFullUrlAttr($value, $data): string
    {
        return buildUploadUrl($data['image'] ?? '');
    }
}
