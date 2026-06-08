<?php
declare(strict_types=1);

namespace app\model\goods;

use mall_base\base\BaseModel;
use think\model\type\Json;

/**
 * 商品评论模型
 */
class GoodsComment extends BaseModel
{
    protected $name = 'goods_comment';
    protected $json = ['images', 'append_images'];
    protected $jsonAssoc = true;
    protected array $append = ['images_full_urls', 'append_images_full_urls'];

    /**
     * 主评价图片路径数组
     *
     * @return array<int, int|string>
     */
    public function getImagesAttr($value, $data): array
    {
        return $this->normalizeImagePaths($value ?? ($data['images'] ?? null));
    }

    /**
     * 追评图片路径数组
     *
     * @return array<int, int|string>
     */
    public function getAppendImagesAttr($value, $data): array
    {
        return $this->normalizeImagePaths($value ?? ($data['append_images'] ?? null));
    }

    /**
     * 主评价图片完整 URL
     *
     * @return array<int, string>
     */
    public function getImagesFullUrlsAttr($value, $data): array
    {
        return buildUploadUrls($this->normalizeImagePaths($data['images'] ?? null));
    }

    /**
     * 追评图片完整 URL
     *
     * @return array<int, string>
     */
    public function getAppendImagesFullUrlsAttr($value, $data): array
    {
        return buildUploadUrls($this->normalizeImagePaths($data['append_images'] ?? null));
    }

    /**
     * @return array<int, int|string>
     */
    private function normalizeImagePaths(mixed $images): array
    {
        if ($images instanceof Json) {
            $images = $images->value();
        }

        if (is_array($images)) {
            return array_values(array_filter(
                array_map(static function ($image): int|string {
                    if (is_array($image)) {
                        $image = $image['asset_id'] ?? $image['id'] ?? $image['url'] ?? '';
                    }
                    if (!is_scalar($image)) {
                        return '';
                    }
                    $value = trim((string) $image);
                    return ctype_digit($value) ? (int) $value : $value;
                }, $images),
                static fn($image): bool => $image !== '' && $image !== 0
            ));
        }

        if (!is_string($images) || trim($images) === '') {
            return [];
        }

        $decoded = json_decode($images, true);
        if (is_array($decoded)) {
            return $this->normalizeImagePaths($decoded);
        }

        return array_values(array_filter(
            array_map(static fn(string $image): string => trim($image), explode(',', $images)),
            static fn(string $image): bool => $image !== ''
        ));
    }
}
