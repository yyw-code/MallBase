<?php
declare(strict_types=1);

namespace app\admin\model\goods;

use mall_base\base\BaseModel;

/**
 * 商品分类模型
 */
class GoodsCategory extends BaseModel
{
    protected $name = 'goods_category';
    protected array $append = ['image_full_url'];

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
     * 搜索器-按父级ID搜索
     */
    public function searchPidAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('pid', $value);
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
     * 分类图片完整地址
     */
    public function getImageFullUrlAttr($value, $data): string
    {
        return buildUploadUrl($data['image'] ?? '');
    }
}
