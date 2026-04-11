<?php
declare(strict_types=1);

namespace app\admin\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品模型
 */
class Goods extends BaseModel
{
    protected $name = 'goods';
    protected array $append = ['main_image_full_url'];

    /**
     * 搜索器-按关键词搜索（名称/副标题）
     */
    public function searchKeywordAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->whereLike('name|subtitle', '%' . $value . '%');
        }
    }

    /**
     * 搜索器-按分类ID搜索
     */
    public function searchCategoryIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('category_id', $value);
        }
    }

    /**
     * 搜索器-按品牌ID搜索
     */
    public function searchBrandIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('brand_id', $value);
        }
    }

    /**
     * 搜索器-按是否上架搜索
     */
    public function searchIsOnSaleAttr($query, $value)
    {
        if (($value ?? null) !== null && $value !== '') {
            $query->where('is_on_sale', $value);
        }
    }

    /**
     * 搜索器-按状态搜索
     */
    public function searchStatusAttr($query, $value)
    {
        if (($value ?? null) !== null && $value !== '') {
            $query->where('status', $value);
        }
    }

    public function getMainImageFullUrlAttr($value, $data): string
    {
        $path = $data['main_image'] ?? '';
        if (empty($path)) {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return getUploadDomain() . $path;
    }
}
