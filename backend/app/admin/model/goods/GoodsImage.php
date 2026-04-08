<?php
declare(strict_types=1);

namespace app\admin\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品图片模型
 */
class GoodsImage extends BaseModel
{
    protected $name = 'goods_image';

    /**
     * 搜索器-按商品ID搜索
     */
    public function searchGoodsIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('goods_id', $value);
        }
    }
}
