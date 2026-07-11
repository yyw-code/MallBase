<?php
declare(strict_types=1);

namespace app\validate\admin\goods;

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
        'freight_template_id' => 'integer|egt:0',
        'price' => 'float|egt:0',
        'market_price' => 'float|egt:0',
        'stock' => 'integer|egt:0',
        'main_image' => 'max:255',
        'main_video' => 'max:255',
        'spec_type' => 'integer|in:1,2',
        'spec_meta' => 'checkSpecMeta',
        'unit' => 'max:20',
        'sort' => 'integer|egt:0',
        'description' => 'max:16000',
        'sku_detail_enabled' => 'in:0,1',
        'points_reward_mode' => 'in:inherit,global,disabled,ratio,fixed,sku',
        'points_reward_ratio' => 'integer|egt:0',
        'points_reward_fixed' => 'integer|egt:0',
        'member_benefit_mode' => 'in:global,disabled,level_discount,sku_price',
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
        'freight_template_id.integer' => '运费模板必须是整数',
        'freight_template_id.egt' => '运费模板不合法',
        'price.float' => '价格必须是数字',
        'price.egt' => '价格必须大于等于0',
        'market_price.float' => '市场价必须是数字',
        'market_price.egt' => '市场价必须大于等于0',
        'stock.integer' => '库存必须是整数',
        'stock.egt' => '库存必须大于等于0',
        'main_image.max' => '主图URL最多255个字符',
        'main_video.max' => '主视频URL最多255个字符',
        'spec_type.integer' => '规格类型必须是整数',
        'spec_type.in' => '规格类型必须是1或2',
        'unit.max' => '单位最多20个字符',
        'sort.integer' => '排序必须是整数',
        'sort.egt' => '排序必须大于等于0',
        'description.max' => '商品描述最多16000个字符',
        'sku_detail_enabled.in' => '规格独立详情开关必须是0或1',
        'points_reward_mode.in' => '积分赠送模式不合法',
        'points_reward_ratio.integer' => '积分赠送比例必须是整数',
        'points_reward_ratio.egt' => '积分赠送比例不能小于0',
        'points_reward_fixed.integer' => '固定赠送积分必须是整数',
        'points_reward_fixed.egt' => '固定赠送积分不能小于0',
        'member_benefit_mode.in' => '会员权益模式不合法',
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
            'name', 'subtitle', 'category_id', 'brand_id', 'freight_template_id',
            'price', 'market_price', 'stock', 'main_image', 'main_video', 'spec_type', 'spec_meta',
            'unit', 'sort', 'description', 'sku_detail_enabled',
            'points_reward_mode', 'points_reward_ratio', 'points_reward_fixed', 'member_benefit_mode',
            'status', 'is_on_sale', 'is_recommend', 'is_new', 'is_hot',
            'images', 'skus', 'tag_ids',
        ],
        'update' => [
            'name', 'subtitle', 'category_id', 'brand_id', 'freight_template_id',
            'price', 'market_price', 'stock', 'main_image', 'main_video', 'spec_type', 'spec_meta',
            'unit', 'sort', 'description', 'sku_detail_enabled',
            'points_reward_mode', 'points_reward_ratio', 'points_reward_fixed', 'member_benefit_mode',
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

        $specType = (int) ($this->data['spec_type'] ?? 1);

        foreach ($value as $index => $sku) {
            if (!is_array($sku)) {
                return "SKU第" . ($index + 1) . "项数据格式错误";
            }

            if ($specType === 2 && empty($sku['spec_values'])) {
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

            // weight 选填，填写时必须是大于等于0的数字（单位：克）
            if (isset($sku['weight']) && $sku['weight'] !== ''
                && (!is_numeric($sku['weight']) || $sku['weight'] < 0)) {
                return "SKU第" . ($index + 1) . "项重量必须大于等于0";
            }

            $mode = (string) ($sku['points_reward_mode'] ?? 'inherit');
            if (!in_array($mode, ['inherit', 'disabled', 'ratio', 'fixed'], true)) {
                return "SKU第" . ($index + 1) . "项积分赠送模式不合法";
            }
            if (isset($sku['points_reward_ratio']) && (!is_numeric($sku['points_reward_ratio']) || $sku['points_reward_ratio'] < 0)) {
                return "SKU第" . ($index + 1) . "项积分赠送比例不能小于0";
            }
            if (isset($sku['points_reward_fixed']) && (!is_numeric($sku['points_reward_fixed']) || $sku['points_reward_fixed'] < 0)) {
                return "SKU第" . ($index + 1) . "项固定赠送积分不能小于0";
            }
            if (isset($sku['member_price']) && $sku['member_price'] !== ''
                && (!is_numeric($sku['member_price']) || $sku['member_price'] < 0)) {
                return "SKU第" . ($index + 1) . "项会员价不能小于0";
            }
            if (isset($sku['description']) && mb_strlen((string) $sku['description']) > 16000) {
                return "SKU第" . ($index + 1) . "项详情最多16000个字符";
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
            $url = is_array($image) ? (string) ($image['url'] ?? '') : (string) $image;
            if ($url === '') {
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
