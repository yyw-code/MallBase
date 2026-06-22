<?php
declare(strict_types=1);

namespace app\service\upload;

use app\model\setting\Setting;

/**
 * 业务返回数据素材回显拼接服务。
 */
class AssetHydrator
{
    private const DECORATION_UPLOAD_FIELDS = [
        'background_image',
        'icon',
        'icon_image',
        'image',
        'selected_icon',
    ];

    public function __construct(
        private readonly AssetResolver $resolver,
        private readonly AssetIdNormalizer $normalizer,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    public function hydrateGoodsList(array $list): array
    {
        $ids = [];
        foreach ($list as &$row) {
            $value = $this->normalizer->normalizeSingle($row['main_image'] ?? '');
            if ($value === '') {
                $first = $this->firstImageValue($row['images'] ?? []);
                if ($first !== '') {
                    $row['main_image'] = $first;
                    $value = $first;
                }
            }
            if (is_int($value)) {
                $ids[] = $value;
            }
            foreach ((array) ($row['spec_meta'] ?? []) as $group) {
                foreach ((array) ($group['values'] ?? []) as $specValue) {
                    $ids[] = $this->normalizer->normalizeSingle($specValue['pic'] ?? '');
                }
            }
        }
        unset($row);

        $assetMap = $this->resolver->resolve($this->normalizer->collectAssetIds($ids));
        foreach ($list as &$row) {
            $row['main_image_full_url'] = $this->fullUrl($row['main_image'] ?? '', $assetMap);
            if (!is_array($row['spec_meta'] ?? null)) {
                continue;
            }
            foreach ($row['spec_meta'] as &$group) {
                if (!is_array($group['values'] ?? null)) {
                    continue;
                }
                foreach ($group['values'] as &$specValue) {
                    $specValue['pic_full_url'] = $this->fullUrl($specValue['pic'] ?? '', $assetMap);
                }
                unset($specValue);
            }
            unset($group);
        }
        unset($row);

        return $list;
    }

    /**
     * @param array<string, mixed> $goods
     * @return array<string, mixed>
     */
    public function hydrateGoodsDetail(array $goods): array
    {
        $ids = [];
        $ids[] = $this->normalizer->normalizeSingle($goods['main_image'] ?? '');
        $ids[] = $this->normalizer->normalizeSingle($goods['main_video'] ?? '');
        foreach ($this->normalizer->normalizeMany($goods['images'] ?? []) as $id) {
            $ids[] = $id;
        }
        foreach ((array) ($goods['spec_meta'] ?? []) as $group) {
            foreach ((array) ($group['values'] ?? []) as $value) {
                $ids[] = $this->normalizer->normalizeSingle($value['pic'] ?? '');
            }
        }
        foreach ((array) ($goods['skus'] ?? []) as $sku) {
            $ids[] = $this->normalizer->normalizeSingle($sku['image'] ?? '');
        }
        foreach ($this->extractAssetIdsFromHtml((string) ($goods['description'] ?? '')) as $id) {
            $ids[] = $id;
        }

        $assetMap = $this->resolver->resolve($this->normalizer->collectAssetIds($ids));
        $goods['main_image_full_url'] = $this->fullUrl($goods['main_image'] ?? '', $assetMap);
        $goods['main_video_full_url'] = $this->fullUrl($goods['main_video'] ?? '', $assetMap);
        $goods['images'] = $this->hydrateImageList($goods['images'] ?? [], $assetMap);
        if (($goods['main_image'] ?? '') === '' && !empty($goods['images'][0]['url'])) {
            $goods['main_image'] = $goods['images'][0]['url'];
            $goods['main_image_full_url'] = (string) ($goods['images'][0]['full_url'] ?? '');
        }

        if (is_array($goods['spec_meta'] ?? null)) {
            foreach ($goods['spec_meta'] as &$group) {
                if (!is_array($group['values'] ?? null)) {
                    continue;
                }
                foreach ($group['values'] as &$value) {
                    $value['pic_full_url'] = $this->fullUrl($value['pic'] ?? '', $assetMap);
                }
                unset($value);
            }
            unset($group);
        }

        if (is_array($goods['skus'] ?? null)) {
            foreach ($goods['skus'] as &$sku) {
                $sku['image_full_url'] = $this->fullUrl($sku['image'] ?? '', $assetMap);
            }
            unset($sku);
        }

        if (isset($goods['description']) && is_string($goods['description'])) {
            $goods['description'] = $this->hydrateRichText($goods['description'], $assetMap);
        }

        return $goods;
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    public function hydrateComments(array $list): array
    {
        $ids = [];
        foreach ($list as $row) {
            foreach ($this->normalizer->normalizeMany($row['images'] ?? []) as $id) {
                $ids[] = $id;
            }
            foreach ($this->normalizer->normalizeMany($row['append_images'] ?? []) as $id) {
                $ids[] = $id;
            }
            $ids[] = $this->normalizer->normalizeSingle($row['user_avatar_raw'] ?? $row['avatar'] ?? '');
        }

        $assetMap = $this->resolver->resolve($this->normalizer->collectAssetIds($ids));
        foreach ($list as &$row) {
            $row['images'] = $this->normalizer->normalizeMany($row['images'] ?? []);
            $row['images_full_urls'] = $this->fullUrls($row['images'], $assetMap);
            $row['append_images'] = $this->normalizer->normalizeMany($row['append_images'] ?? []);
            $row['append_images_full_urls'] = $this->fullUrls($row['append_images'], $assetMap);
            if (array_key_exists('user_avatar_raw', $row)) {
                $row['user_avatar_full_url'] = $this->fullUrl($row['user_avatar_raw'], $assetMap);
            }
        }
        unset($row);

        return $list;
    }

    /**
     * 通用单图字段回显。
     *
     * @param array<int, array<string, mixed>> $list
     * @param array<string, string> $fieldMap raw_field => full_url_field
     * @return array<int, array<string, mixed>>
     */
    public function hydrateFields(array $list, array $fieldMap): array
    {
        $ids = [];
        foreach ($list as $row) {
            foreach ($fieldMap as $rawField => $_fullField) {
                $value = $this->normalizer->normalizeSingle($row[$rawField] ?? '');
                if (is_int($value)) {
                    $ids[] = $value;
                }
            }
        }

        $assetMap = $this->resolver->resolve($this->normalizer->collectAssetIds($ids));
        foreach ($list as &$row) {
            foreach ($fieldMap as $rawField => $fullField) {
                $row[$fullField] = $this->fullUrl($row[$rawField] ?? '', $assetMap);
            }
        }
        unset($row);

        return $list;
    }

    /**
     * 设置项文件字段回显。
     *
     * @param array<int, array<string, mixed>> $settings
     * @return array<int, array<string, mixed>>
     */
    public function hydrateSettings(array $settings): array
    {
        $ids = [];
        foreach ($settings as $row) {
            $type = (string) ($row['type'] ?? '');
            if (!in_array($type, Setting::FILE_TYPES, true)) {
                continue;
            }

            if (in_array($type, [Setting::TYPE_IMAGES, Setting::TYPE_FILES, Setting::TYPE_VIDEOS], true)) {
                foreach ($this->normalizer->normalizeMany($row['value'] ?? '') as $value) {
                    if (is_int($value)) {
                        $ids[] = $value;
                    }
                }
                continue;
            }

            $value = $this->normalizer->normalizeSingle($row['value'] ?? '');
            if (is_int($value)) {
                $ids[] = $value;
            }
        }

        $assetMap = $this->resolver->resolve($this->normalizer->collectAssetIds($ids));
        foreach ($settings as &$row) {
            $type = (string) ($row['type'] ?? '');
            if (!in_array($type, Setting::FILE_TYPES, true)) {
                continue;
            }

            if (in_array($type, [Setting::TYPE_IMAGES, Setting::TYPE_FILES, Setting::TYPE_VIDEOS], true)) {
                $row['full_url'] = $this->fullUrls($this->normalizer->normalizeMany($row['value'] ?? ''), $assetMap);
                continue;
            }

            $row['full_url'] = $this->fullUrl($row['value'] ?? '', $assetMap);
        }
        unset($row);

        return $settings;
    }

    /**
     * 装修配置图片字段回显。
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function hydrateDecorationSchema(array $schema): array
    {
        $ids = [];
        $this->collectDecorationAssetIds($schema, $ids);

        $assetMap = $this->resolver->resolve($this->normalizer->collectAssetIds($ids));

        return $this->hydrateDecorationAssetValues($schema, $assetMap);
    }

    /**
     * @param mixed $images
     * @param array<int, array<string, mixed>> $assetMap
     * @return array<int, array{url:int|string, full_url:string}>
     */
    public function hydrateImageList(mixed $images, array $assetMap): array
    {
        $list = [];
        foreach ($this->normalizer->normalizeMany($images) as $value) {
            $list[] = [
                'url' => $value,
                'full_url' => $this->fullUrl($value, $assetMap),
            ];
        }

        return $list;
    }

    public function firstImageValue(mixed $images): int|string
    {
        $values = $this->normalizer->normalizeMany($images);
        return $values[0] ?? '';
    }

    /**
     * @param array<int, array<string, mixed>> $assetMap
     */
    public function hydrateRichText(string $html, array $assetMap): string
    {
        if ($html === '' || !str_contains($html, 'data-asset-id')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<(img|video)\b([^>]*\bdata-asset-id=["\']?(\d+)["\']?[^>]*)>/i',
            function (array $matches) use ($assetMap): string {
                $assetId = (int) ($matches[3] ?? 0);
                $url = (string) ($assetMap[$assetId]['full_url'] ?? '');
                if ($assetId <= 0 || $url === '') {
                    return $matches[0];
                }

                $tag = $matches[1];
                $attrs = $matches[2];
                if (preg_match('/\bsrc=["\'][^"\']*["\']/i', $attrs)) {
                    $attrs = preg_replace('/\bsrc=["\'][^"\']*["\']/i', 'src="' . htmlspecialchars($url, ENT_QUOTES) . '"', $attrs);
                } else {
                    $attrs .= ' src="' . htmlspecialchars($url, ENT_QUOTES) . '"';
                }

                return '<' . $tag . $attrs . '>';
            },
            $html
        );
    }

    /**
     * @return array<int, int>
     */
    private function extractAssetIdsFromHtml(string $html): array
    {
        if ($html === '' || !str_contains($html, 'data-asset-id')) {
            return [];
        }

        preg_match_all('/\bdata-asset-id=["\']?(\d+)["\']?/i', $html, $matches);
        return array_values(array_unique(array_map('intval', $matches[1] ?? [])));
    }

    /**
     * @param array<mixed> $value
     * @param array<int, mixed> $ids
     */
    private function collectDecorationAssetIds(array $value, array &$ids): void
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::DECORATION_UPLOAD_FIELDS, true)) {
                $normalized = $this->normalizer->normalizeSingle($item);
                if (is_int($normalized)) {
                    $ids[] = $normalized;
                }
            }

            if (is_array($item)) {
                $this->collectDecorationAssetIds($item, $ids);
            }
        }
    }

    /**
     * @param array<mixed> $value
     * @param array<int, array<string, mixed>> $assetMap
     * @return array<mixed>
     */
    private function hydrateDecorationAssetValues(array $value, array $assetMap): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::DECORATION_UPLOAD_FIELDS, true)) {
                $value[$key] = $this->hydrateDecorationUploadValue($item, $assetMap);
                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->hydrateDecorationAssetValues($item, $assetMap);
            }
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $assetMap
     */
    private function hydrateDecorationUploadValue(mixed $value, array $assetMap): mixed
    {
        $normalized = $this->normalizer->normalizeSingle($value);
        if (!is_int($normalized)) {
            return $value;
        }

        $asset = $assetMap[$normalized] ?? [];
        $fileInfo = is_array($value) ? $value : [];
        $fileInfo['url'] = (string) $normalized;
        $fileInfo['full_url'] = (string) ($asset['full_url'] ?? '');
        $fileInfo['name'] = (string) ($fileInfo['name'] ?? $asset['name'] ?? ('素材' . $normalized));
        $fileInfo['asset_id'] = $normalized;

        return $fileInfo;
    }

    /**
     * @param mixed $value
     * @param array<int, array<string, mixed>> $assetMap
     */
    public function fullUrl(mixed $value, array $assetMap): string
    {
        $normalized = $this->normalizer->normalizeSingle($value);
        if ($normalized === '') {
            return '';
        }

        if (is_int($normalized)) {
            return (string) ($assetMap[$normalized]['full_url'] ?? '');
        }

        return buildUploadUrl((string) $normalized);
    }

    /**
     * @param array<int, mixed> $values
     * @param array<int, array<string, mixed>> $assetMap
     * @return array<int, string>
     */
    private function fullUrls(array $values, array $assetMap): array
    {
        $urls = [];
        foreach ($values as $value) {
            $url = $this->fullUrl($value, $assetMap);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
