<?php
declare(strict_types=1);

namespace app\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品规格模型
 */
class GoodsSpec extends BaseModel
{
    protected $name = 'goods_spec';

    /**
     * 关联-规格值（一对多）
     */
    public function specValues()
    {
        return $this->hasMany(GoodsSpecValue::class, 'spec_id', 'id');
    }
}
