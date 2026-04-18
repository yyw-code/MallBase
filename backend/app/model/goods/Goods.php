<?php
declare(strict_types=1);

namespace app\model\goods;

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
