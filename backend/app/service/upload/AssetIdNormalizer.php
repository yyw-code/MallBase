<?php
declare(strict_types=1);

namespace app\service\upload;

/**
 * 素材 ID 归一化工具。
 */
class AssetIdNormalizer
{
    /**
     * 单图字段：数字素材 ID 返回 int，旧路径/外链返回原字符串。
     */
    public function normalizeSingle(mixed $value): int|string
    {
        if (is_int($value)) {
            return $value > 0 ? $value : '';
        }

        if (is_float($value)) {
            $intValue = (int) $value;
            return $intValue > 0 ? $intValue : '';
        }

        if (is_array($value)) {
            $value = $value['asset_id'] ?? $value['id'] ?? $value['url'] ?? '';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return ctype_digit($value) ? (int) $value : $value;
    }

    /**
     * 多图字段：数字素材 ID 按数组顺序返回；旧路径保留字符串用于迁移期兼容。
     *
     * @return array<int, int|string>
     */
    public function normalizeMany(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } elseif (trim($value) === '') {
                return [];
            } else {
                $value = explode(',', $value);
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $normalized = $this->normalizeSingle($item);
            if ($normalized === '' || $normalized === 0) {
                continue;
            }
            $result[] = $normalized;
        }

        return array_values($result);
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, int>
     */
    public function collectAssetIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $normalized = $this->normalizeSingle($value);
            if (is_int($normalized) && $normalized > 0) {
                $ids[] = $normalized;
            }
        }

        return array_values(array_unique($ids));
    }
}
