<?php
declare(strict_types=1);

namespace app\admin\model\goods;

use mall_base\base\BaseModel;
use think\model\type\Json;

/**
 * 商品模型
 */
class Goods extends BaseModel
{
    protected $name = 'goods';
    protected $json = ['spec_meta'];
    protected $jsonAssoc = true;
    protected array $append = ['main_image_full_url', 'main_video_full_url'];

    public const SPEC_TYPE_SINGLE = 1;
    public const SPEC_TYPE_MULTI = 2;

    public function getSpecMetaAttr($value, $data)
    {
        if ($value instanceof Json) {
            $value = $value->value();
        }

        if (!is_array($value)) {
            return [];
        }

        foreach ($value as &$group) {
            if (!is_array($group['values'] ?? null)) {
                continue;
            }

            foreach ($group['values'] as &$item) {
                $item['pic_full_url'] = buildUploadUrl($item['pic'] ?? '');
            }
        }

        return $value;
    }

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
        return buildUploadUrl($data['main_image'] ?? '');
    }

    /**
     * 获取主视频完整地址
     */
    public function getMainVideoFullUrlAttr($value, $data): string
    {
        return buildUploadUrl($data['main_video'] ?? '');
    }
}
