<?php
declare(strict_types=1);

namespace app\model\order;

use mall_base\base\BaseModel;

/**
 * 订单明细（订单项）模型
 *
 * goods_name / goods_image / sku_spec 为下单时快照，商品后续变更不影响历史订单
 */
class OrderItem extends BaseModel
{
    protected $name = 'order_item';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    protected array $append = ['goods_image_full_url'];

    /**
     * 归属订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * 商品主图完整 URL（兼容相对路径）
     */
    public function getGoodsImageFullUrlAttr($value, $data): string
    {
        return buildUploadUrl($data['goods_image'] ?? '');
    }
}
