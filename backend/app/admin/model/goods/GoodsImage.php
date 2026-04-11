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
    protected array $append = ['full_url'];

    /**
     * 搜索器-按商品ID搜索
     */
    public function searchGoodsIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('goods_id', $value);
        }
    }

    public function getFullUrlAttr($value, $data): string
    {
        $path = $data['url'] ?? '';
        if (empty($path)) {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return getUploadDomain() . $path;
    }
}
