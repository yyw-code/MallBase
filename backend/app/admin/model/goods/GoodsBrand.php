<?php
declare(strict_types=1);

namespace app\admin\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品品牌模型
 */
class GoodsBrand extends BaseModel
{
    protected $name = 'goods_brand';
    protected array $append = ['logo_full_url'];

    /**
     * 搜索器-按名称搜索
     */
    public function searchNameAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->whereLike('name', '%' . $value . '%');
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

    /**
     * 品牌 LOGO 完整地址
     */
    public function getLogoFullUrlAttr($value, $data): string
    {
        return buildUploadUrl($data['logo'] ?? '');
    }
}
