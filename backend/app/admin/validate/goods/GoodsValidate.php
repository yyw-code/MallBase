<?php
declare(strict_types=1);

namespace app\admin\validate\goods;

use think\Validate;

/**
 * 商品验证器
 */
class GoodsValidate extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'name' => 'require|max:200',
        'subtitle' => 'max:200',
        'category_id' => 'require|integer',
        'brand_id' => 'integer',
        'price' => 'float|egt:0',
        'market_price' => 'float|egt:0',
        'stock' => 'integer|egt:0',
        'main_image' => 'max:255',
        'main_video' => 'max:255',
        'spec_meta' => 'checkSpecMeta',
        'unit' => 'max:20',
        'sort' => 'integer|egt:0',
        'description' => 'max:2000',
        'status' => 'in:0,1',
        'is_on_sale' => 'in:0,1',
        'is_recommend' => 'in:0,1',
        'is_new' => 'in:0,1',
        'is_hot' => 'in:0,1',
        'skus' => 'checkSkus',
        'images' => 'checkImages',
    ];

    /**
     * 错误消息
     */
    protected $message = [
        'name.require' => '商品名称不能为空',
        'name.max' => '商品名称最多200个字符',
        'subtitle.max' => '副标题最多200个字符',
        'category_id.require' => '商品分类不能为空',
        'category_id.integer' => '商品分类必须是整数',
        'brand_id.integer' => '品牌必须是整数',
        'price.float' => '价格必须是数字',
        'price.egt' => '价格必须大于等于0',
        'market_price.float' => '市场价必须是数字',
        'market_price.egt' => '市场价必须大于等于0',
        'stock.integer' => '库存必须是整数',
        'stock.egt' => '库存必须大于等于0',
        'main_image.max' => '主图URL最多255个字符',
        'main_video.max' => '主视频URL最多255个字符',
        'unit.max' => '单位最多20个字符',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'description.max' => '商品描述最多2000个字符',
        'status.in' => '状态必须是0或1',
        'is_on_sale.in' => '是否上架必须是0或1',
        'is_recommend.in' => '是否推荐必须是0或1',
        'is_new.in' => '是否新品必须是0或1',
        'is_hot.in' => '是否热卖必须是0或1',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'create' => [
            'name', 'subtitle', 'category_id', 'brand_id',
            'price', 'market_price', 'stock', 'main_image', 'main_video', 'spec_meta',
            'unit', 'sort', 'description',
            'status', 'is_on_sale', 'is_recommend', 'is_new', 'is_hot',
            'images', 'skus', 'tag_ids',
        ],
        'update' => [
            'name', 'subtitle', 'category_id', 'brand_id',
            'price', 'market_price', 'stock', 'main_image', 'main_video', 'spec_meta',
            'unit', 'sort', 'description',
            'status', 'is_on_sale', 'is_recommend', 'is_new', 'is_hot',
            'images', 'skus', 'tag_ids',
        ],
    ];

    /**
     * 验证SKU数组
     *
     * @param mixed $value SKU数据
     * @return bool|string
     */
    protected function checkSkus($value): bool|string
    {
        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $index => $sku) {
            if (!is_array($sku)) {
                return "SKU第" . ($index + 1) . "项数据格式错误";
            }

            // spec_values 在多规格模式下必须非空
            if (empty($sku['spec_values'])) {
                return "SKU第" . ($index + 1) . "项规格值不能为空";
            }

            // price 必须是大于等于0的数字
            if (isset($sku['price']) && (!is_numeric($sku['price']) || $sku['price'] < 0)) {
                return "SKU第" . ($index + 1) . "项价格必须大于等于0";
            }

            // stock 必须是大于等于0的整数
            if (isset($sku['stock']) && (!is_numeric($sku['stock']) || $sku['stock'] < 0)) {
                return "SKU第" . ($index + 1) . "项库存必须大于等于0";
            }
        }

        return true;
    }

    /**
     * 验证图片数组
     *
     * @param mixed $value 图片数据
     * @return bool|string
     */
    protected function checkImages($value): bool|string
    {
        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $index => $image) {
            if (!is_array($image)) {
                return "图片第" . ($index + 1) . "项数据格式错误";
            }

            if (empty($image['url'])) {
                return "图片第" . ($index + 1) . "项URL不能为空";
            }
        }

        return true;
    }

    /**
     * 验证规格元数据
     *
     * @param mixed $value
     * @return bool|string
     */
    protected function checkSpecMeta($value): bool|string
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (!is_array($value)) {
            return '规格元数据格式错误';
        }

        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                return '规格元数据第' . ($index + 1) . '项格式错误';
            }

            if (empty($item['name'])) {
                return '规格元数据第' . ($index + 1) . '项名称不能为空';
            }

            if (!isset($item['values']) || !is_array($item['values'])) {
                return '规格元数据第' . ($index + 1) . '项规格值格式错误';
            }
        }

        return true;
    }
}
