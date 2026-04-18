<?php
declare(strict_types=1);

namespace app\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品品牌模型
 */
class GoodsBrand extends BaseModel
{
    protected $name = 'goods_brand';
    protected array $append = ['logo_full_url'];

    /**
     * 品牌 LOGO 完整地址
     */
    public function getLogoFullUrlAttr($value, $data): string
    {
        return buildUploadUrl($data['logo'] ?? '');
    }
}
