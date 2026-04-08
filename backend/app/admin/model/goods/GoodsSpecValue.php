<?php
declare(strict_types=1);

namespace app\admin\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品规格值模型
 */
class GoodsSpecValue extends BaseModel
{
    protected $name = 'goods_spec_value';

    /**
     * 搜索器-按规格ID搜索
     */
    public function searchSpecIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('spec_id', $value);
        }
    }
}
