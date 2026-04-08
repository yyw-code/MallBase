<?php
declare(strict_types=1);

namespace app\admin\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品评论模型
 */
class GoodsComment extends BaseModel
{
    protected $name = 'goods_comment';

    /**
     * 搜索器-按商品ID搜索
     */
    public function searchGoodsIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('goods_id', $value);
        }
    }

    /**
     * 搜索器-按评分搜索
     */
    public function searchRatingAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('rating', $value);
        }
    }

    /**
     * 搜索器-按状态搜索
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', $value);
        }
    }
}
