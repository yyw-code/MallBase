<?php

declare(strict_types=1);

namespace app\service;

use app\model\region\Region;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * @extends BaseService<Region>
 */
class RegionResolverService extends BaseService
{
    protected string $modelClass = Region::class;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function rematchAddressRegionByCodes(array $data): array
    {
        $provinceCode = trim((string) ($data['province_code'] ?? ''));
        $cityCode = trim((string) ($data['city_code'] ?? ''));
        $districtCode = trim((string) ($data['district_code'] ?? ''));
        $streetCode = trim((string) ($data['street_code'] ?? ''));

        $province = $this->matchRegionByCode($provinceCode, 1, '省级');
        if (!$province['success']) {
            return $province;
        }

        $city = $this->matchRegionByCode($cityCode, 2, '市级');
        if (!$city['success']) {
            return $city;
        }

        $district = $this->matchRegionByCode($districtCode, 3, '区县');
        if (!$district['success']) {
            return $district;
        }

        $street = $this->matchRegionByCode($streetCode, 4, '街道');
        if (!$street['success']) {
            return $street;
        }

        /** @var array<string, mixed> $provinceRegion */
        $provinceRegion = $province['region'];
        /** @var array<string, mixed> $cityRegion */
        $cityRegion = $city['region'];
        /** @var array<string, mixed> $districtRegion */
        $districtRegion = $district['region'];
        /** @var array<string, mixed> $streetRegion */
        $streetRegion = $street['region'];

        $reason = $this->validateAddressChain(
            $provinceRegion,
            $cityRegion,
            $districtRegion,
            $streetRegion,
        );

        if ($reason !== null) {
            return [
                'success' => false,
                'reason' => $reason,
            ];
        }

        return [
            'success' => true,
            'reason' => null,
            'data' => $this->buildAddressRegionPayload(
                $provinceRegion,
                $cityRegion,
                $districtRegion,
                $streetRegion,
            ),
        ];
    }

    /**
     * @param array<int, int|string> $regionCodes
     * @return array<string, mixed>
     */
    public function rematchStreetSelectionsByCodes(array $regionCodes): array
    {
        $regionCodes = array_values(array_unique(array_filter(array_map(
            static fn ($code): string => trim((string) $code),
            $regionCodes,
        ))));

        if ($regionCodes === []) {
            return [
                'success' => false,
                'reason' => '街道编码未匹配',
            ];
        }

        $regions = [];
        foreach ($regionCodes as $code) {
            $matched = $this->matchRegionByCode($code, 4, '街道');
            if (!$matched['success']) {
                return [
                    'success' => false,
                    'reason' => ($matched['reason'] ?? '街道编码未匹配') . '：' . $code,
                ];
            }

            /** @var array<string, mixed> $street */
            $street = $matched['region'];
            $path = $this->getPath((int) $street['id']);
            if (count($path) !== 4) {
                return [
                    'success' => false,
                    'reason' => '街道路径数据不完整：' . $code,
                ];
            }

            $reason = $this->validatePathStatus($path);
            if ($reason !== null) {
                return [
                    'success' => false,
                    'reason' => $reason . '：' . $code,
                ];
            }

            $regions[] = [
                'region' => $street,
                'path' => $path,
            ];
        }

        $ids = [];
        $codes = [];
        $names = [];
        $paths = [];
        foreach ($regions as $item) {
            $ids[] = (int) $item['region']['id'];
            $codes[] = (string) $item['region']['code'];
            $names[] = (string) $item['region']['name'];
            $paths[] = implode(' / ', array_column($item['path'], 'name'));
        }

        return [
            'success' => true,
            'reason' => null,
            'data' => [
                'region_ids' => $ids,
                'region_codes' => $codes,
                'region_names' => $names,
                'region_path_texts' => $paths,
                'region_status' => 1,
                'region_invalid_reason' => null,
            ],
        ];
    }

    /**
     * 获取子级地区
     */
    public function getChildren(int $parentId = 0): array
    {
        return $this->model()
            ->where('parent_id', $parentId)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 获取地区路径
     */
    public function getPath(int $id): array
    {
        $region = $this->model()->where('id', $id)->find();
        if ($region === null) {
            throw new BusinessException('地区不存在');
        }

        $codes = array_filter(explode(',', (string) $region['path_codes']));
        if ($codes === []) {
            return [];
        }

        $list = $this->model()
            ->whereIn('code', $codes)
            ->order('level', 'asc')
            ->select()
            ->toArray();

        $map = [];
        foreach ($list as $item) {
            $map[$item['code']] = $item;
        }

        $result = [];
        foreach ($codes as $code) {
            if (isset($map[$code])) {
                $result[] = $map[$code];
            }
        }

        return $result;
    }

    /**
     * 规范化四级地址
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeAddressRegion(array $data): array
    {
        $provinceId = (int) ($data['province_id'] ?? 0);
        $cityId = (int) ($data['city_id'] ?? 0);
        $districtId = (int) ($data['district_id'] ?? 0);
        $streetId = (int) ($data['street_id'] ?? 0);

        if ($provinceId <= 0 || $cityId <= 0 || $districtId <= 0 || $streetId <= 0) {
            throw new BusinessException('请选择完整的省市区街道');
        }

        $regions = $this->model()
            ->whereIn('id', [$provinceId, $cityId, $districtId, $streetId])
            ->select()
            ->toArray();

        if (count($regions) !== 4) {
            throw new BusinessException('地区数据不完整或已失效');
        }

        $map = array_column($regions, null, 'id');
        $province = $map[$provinceId] ?? null;
        $city = $map[$cityId] ?? null;
        $district = $map[$districtId] ?? null;
        $street = $map[$streetId] ?? null;

        if (!$province || !$city || !$district || !$street) {
            throw new BusinessException('地区数据不存在');
        }

        if ((int) $province['level'] !== 1 || (int) $city['level'] !== 2 || (int) $district['level'] !== 3 || (int) $street['level'] !== 4) {
            throw new BusinessException('地区层级不正确');
        }

        if ((int) $city['parent_id'] !== $provinceId || (int) $district['parent_id'] !== $cityId || (int) $street['parent_id'] !== $districtId) {
            throw new BusinessException('地区父子关系不匹配');
        }

        foreach ([$province, $city, $district, $street] as $region) {
            if ((int) $region['status'] !== 1) {
                throw new BusinessException('所选地区已停用，请重新选择');
            }
        }

        return $this->buildAddressRegionPayload($province, $city, $district, $street);
    }

    /**
     * 规范化街道规则
     *
     * @param array<int, int|string> $regionIds
     * @return array<string, mixed>
     */
    public function normalizeStreetSelections(array $regionIds): array
    {
        $regionIds = array_values(array_unique(array_map('intval', $regionIds)));
        if ($regionIds === []) {
            throw new BusinessException('请选择街道区域');
        }

        $regions = $this->model()
            ->whereIn('id', $regionIds)
            ->select()
            ->toArray();

        if (count($regions) !== count($regionIds)) {
            throw new BusinessException('所选街道存在无效数据');
        }

        $paths = [];
        $codes = [];
        $names = [];
        foreach ($regions as $region) {
            if ((int) $region['level'] !== 4) {
                throw new BusinessException('运费模板必须精确到街道');
            }
            if ((int) $region['status'] !== 1) {
                throw new BusinessException('所选街道已停用，请重新选择');
            }
            $path = $this->getPath((int) $region['id']);
            if (count($path) !== 4) {
                throw new BusinessException('街道路径数据不完整');
            }
            $paths[] = implode(' / ', array_column($path, 'name'));
            $codes[] = $region['code'];
            $names[] = $region['name'];
        }

        return [
            'region_ids' => $regionIds,
            'region_codes' => $codes,
            'region_names' => $names,
            'region_path_texts' => $paths,
            'region_status' => 1,
            'region_invalid_reason' => null,
        ];
    }

    /**
     * 规范化区域规则（支持省/市/区/街道任意层级）
     *
     * @param array<int, int|string> $regionIds
     * @return array{
     *     region_ids: array<int, int>,
     *     region_codes: array<int, string>,
     *     region_names: array<int, string>,
     *     region_path_texts: array<int, string>,
     *     match_level: int,
     *     items: array<int, array{id:int, level:int, code:string, name:string, province_id:int, city_id:int, district_id:int, street_id:int, path_text:string}>,
     *     region_status: int,
     *     region_invalid_reason: string|null
     * }
     */
    public function normalizeRegionSelections(array $regionIds): array
    {
        $regionIds = array_values(array_unique(array_map('intval', $regionIds)));
        if ($regionIds === []) {
            throw new BusinessException('请选择区域');
        }

        $regions = $this->model()
            ->whereIn('id', $regionIds)
            ->select()
            ->toArray();

        if (count($regions) !== count($regionIds)) {
            throw new BusinessException('所选区域存在无效数据');
        }

        $regionMap = array_column($regions, null, 'id');

        $ids = [];
        $codes = [];
        $names = [];
        $paths = [];
        $items = [];
        $maxLevel = 0;

        foreach ($regionIds as $id) {
            $region = $regionMap[$id] ?? null;
            if (!$region) {
                throw new BusinessException('所选区域存在无效数据');
            }

            $level = (int) $region['level'];
            if ($level < 1 || $level > 4) {
                throw new BusinessException('所选区域层级不正确');
            }
            if ((int) $region['status'] !== 1) {
                throw new BusinessException(sprintf(
                    '所选%s已停用，请重新选择：%s',
                    $this->levelLabel($level),
                    (string) $region['name'],
                ));
            }

            $path = $this->getPath($id);
            if (count($path) !== $level) {
                throw new BusinessException(sprintf(
                    '%s路径数据不完整：%s',
                    $this->levelLabel($level),
                    (string) $region['name'],
                ));
            }

            $pathCheck = $this->validatePathStatusByLevel($path, $level);
            if ($pathCheck !== null) {
                $label = (string) ($pathCheck['name'] ?? '');
                if ($label === '') {
                    $label = implode(' / ', array_column($path, 'name'));
                }
                throw new BusinessException(
                    $label === ''
                        ? $pathCheck['reason']
                        : $pathCheck['reason'] . '：' . $label,
                );
            }

            $pathText = implode(' / ', array_column($path, 'name'));

            $ids[] = (int) $region['id'];
            $codes[] = (string) $region['code'];
            $names[] = (string) $region['name'];
            $paths[] = $pathText;
            $items[] = [
                'id' => (int) $region['id'],
                'level' => $level,
                'code' => (string) $region['code'],
                'name' => (string) $region['name'],
                'province_id' => $level >= 1 ? (int) ($path[0]['id'] ?? 0) : 0,
                'city_id' => $level >= 2 ? (int) ($path[1]['id'] ?? 0) : 0,
                'district_id' => $level >= 3 ? (int) ($path[2]['id'] ?? 0) : 0,
                'street_id' => $level >= 4 ? (int) ($path[3]['id'] ?? 0) : 0,
                'path_text' => $pathText,
            ];

            if ($level > $maxLevel) {
                $maxLevel = $level;
            }
        }

        return [
            'region_ids' => $ids,
            'region_codes' => $codes,
            'region_names' => $names,
            'region_path_texts' => $paths,
            'match_level' => $maxLevel,
            'items' => $items,
            'region_status' => 1,
            'region_invalid_reason' => null,
        ];
    }

    /**
     * 通过区域编码重新匹配规则数据（支持任意层级）
     *
     * @param array<int, int|string> $regionCodes
     * @return array<string, mixed>
     */
    public function rematchRegionSelectionsByCodes(array $regionCodes): array
    {
        $regionCodes = array_values(array_unique(array_filter(array_map(
            static fn ($code): string => trim((string) $code),
            $regionCodes,
        ))));

        if ($regionCodes === []) {
            return [
                'success' => false,
                'reason' => '区域编码未匹配',
            ];
        }

        $regions = $this->model()
            ->whereIn('code', $regionCodes)
            ->select()
            ->toArray();

        $byCode = array_column($regions, null, 'code');

        $ids = [];
        $codes = [];
        $names = [];
        $paths = [];
        $items = [];
        $maxLevel = 0;

        foreach ($regionCodes as $code) {
            $region = $byCode[$code] ?? null;
            if (!$region) {
                return [
                    'success' => false,
                    'reason' => '区域编码未匹配：' . $code,
                ];
            }

            $level = (int) $region['level'];
            if ($level < 1 || $level > 4) {
                return [
                    'success' => false,
                    'reason' => sprintf('区域层级不正确：%s', $code),
                ];
            }

            if ((int) $region['status'] !== 1) {
                return [
                    'success' => false,
                    'reason' => sprintf(
                        '%s已停用：%s',
                        $this->levelLabel($level),
                        (string) $region['name'],
                    ),
                ];
            }

            $path = $this->getPath((int) $region['id']);
            if (count($path) !== $level) {
                return [
                    'success' => false,
                    'reason' => sprintf(
                        '%s路径数据不完整：%s',
                        $this->levelLabel($level),
                        (string) $region['name'],
                    ),
                ];
            }

            $pathText = implode(' / ', array_column($path, 'name'));

            $pathCheck = $this->validatePathStatusByLevel($path, $level);
            if ($pathCheck !== null) {
                $label = (string) ($pathCheck['name'] ?? '');
                if ($label === '') {
                    $label = $pathText !== '' ? $pathText : (string) $region['name'];
                }
                return [
                    'success' => false,
                    'reason' => $label === ''
                        ? $pathCheck['reason']
                        : $pathCheck['reason'] . '：' . $label,
                ];
            }

            $ids[] = (int) $region['id'];
            $codes[] = (string) $region['code'];
            $names[] = (string) $region['name'];
            $paths[] = $pathText;
            $items[] = [
                'id' => (int) $region['id'],
                'level' => $level,
                'code' => (string) $region['code'],
                'name' => (string) $region['name'],
                'province_id' => $level >= 1 ? (int) ($path[0]['id'] ?? 0) : 0,
                'city_id' => $level >= 2 ? (int) ($path[1]['id'] ?? 0) : 0,
                'district_id' => $level >= 3 ? (int) ($path[2]['id'] ?? 0) : 0,
                'street_id' => $level >= 4 ? (int) ($path[3]['id'] ?? 0) : 0,
                'path_text' => $pathText,
            ];

            if ($level > $maxLevel) {
                $maxLevel = $level;
            }
        }

        return [
            'success' => true,
            'reason' => null,
            'data' => [
                'region_ids' => $ids,
                'region_codes' => $codes,
                'region_names' => $names,
                'region_path_texts' => $paths,
                'match_level' => $maxLevel,
                'items' => $items,
                'region_status' => 1,
                'region_invalid_reason' => null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    public function getRegionRuleState(array $rule): array
    {
        $matched = $this->rematchRegionSelectionsByCodes((array) ($rule['region_codes'] ?? []));
        if ($matched['success'] ?? false) {
            return [
                'valid' => true,
                'reason' => null,
                'data' => $matched['data'],
            ];
        }

        return [
            'valid' => false,
            'reason' => $matched['reason'] ?? '规则包含已失效区域，请重新选择',
            'data' => null,
        ];
    }

    public function isAddressRegionValid(array $address): bool
    {
        return $this->getAddressRegionState($address)['valid'];
    }

    /**
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    public function getAddressRegionState(array $address): array
    {
        $matched = $this->rematchAddressRegionByCodes($address);
        if ($matched['success'] ?? false) {
            return [
                'valid' => true,
                'reason' => null,
                'data' => $matched['data'],
            ];
        }

        return [
            'valid' => false,
            'reason' => $matched['reason'] ?? '关联地区已失效，请重新编辑地址',
            'data' => null,
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    public function getStreetRuleState(array $rule): array
    {
        $matched = $this->rematchStreetSelectionsByCodes((array) ($rule['region_codes'] ?? []));
        if ($matched['success'] ?? false) {
            return [
                'valid' => true,
                'reason' => null,
                'data' => $matched['data'],
            ];
        }

        return [
            'valid' => false,
            'reason' => $matched['reason'] ?? '规则包含已失效街道，请重新选择',
            'data' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function matchRegionByCode(string $code, int $level, string $label): array
    {
        if ($code === '') {
            return [
                'success' => false,
                'reason' => sprintf('%s编码未匹配', $label),
            ];
        }

        $region = $this->model()
            ->where('code', $code)
            ->where('level', $level)
            ->find();

        if ($region === null) {
            return [
                'success' => false,
                'reason' => sprintf('%s编码未匹配', $label),
            ];
        }

        if ((int) $region['status'] !== 1) {
            return [
                'success' => false,
                'reason' => sprintf('%s地区已停用', $label),
            ];
        }

        return [
            'success' => true,
            'reason' => null,
            'region' => $region,
        ];
    }

    protected function validateAddressChain(
        array|\ArrayAccess $province,
        array|\ArrayAccess $city,
        array|\ArrayAccess $district,
        array|\ArrayAccess $street,
    ): ?string {
        if ((int) $city['parent_id'] !== (int) $province['id']) {
            return '地区父子关系不匹配';
        }

        if ((int) $district['parent_id'] !== (int) $city['id']) {
            return '地区父子关系不匹配';
        }

        if ((int) $street['parent_id'] !== (int) $district['id']) {
            return '地区父子关系不匹配';
        }

        return null;
    }

    protected function levelLabel(int $level): string
    {
        return match ($level) {
            1 => '省级',
            2 => '市级',
            3 => '区县',
            4 => '街道',
            default => '未知层级',
        };
    }

    /**
     * 按指定层级校验路径（省=1 层、市=2 层、区=3 层、街道=4 层）
     *
     * 返回结构化诊断：
     * - reason：语义化消息（不含 code / name 后缀，由调用方按需补充）
     * - level：出问题的层级（仅停用场景提供）
     * - name：出问题的地区中文名（仅停用场景提供，便于前端展示而非裸 ID）
     *
     * @param array<int, array<string, mixed>> $path
     * @return array{reason: string, level?: int, name?: string}|null
     */
    protected function validatePathStatusByLevel(array $path, int $level): ?array
    {
        if (count($path) !== $level) {
            return ['reason' => '地区路径数据不完整'];
        }

        foreach ($path as $index => $region) {
            if ((int) ($region['status'] ?? 0) !== 1) {
                return [
                    'reason' => sprintf('%s已停用', $this->levelLabel($index + 1)),
                    'level' => $index + 1,
                    'name' => (string) ($region['name'] ?? ''),
                ];
            }
        }

        $count = count($path);
        for ($i = 1; $i < $count; $i++) {
            if ((int) ($path[$i]['parent_id'] ?? 0) !== (int) ($path[$i - 1]['id'] ?? 0)) {
                return ['reason' => '地区父子关系不匹配'];
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $path
     */
    protected function validatePathStatus(array $path): ?string
    {
        if (count($path) !== 4) {
            return '地区路径数据不完整';
        }

        foreach ($path as $index => $region) {
            if ((int) ($region['status'] ?? 0) !== 1) {
                return match ($index) {
                    0 => '省级地区已停用',
                    1 => '市级地区已停用',
                    2 => '区县地区已停用',
                    default => '街道地区已停用',
                };
            }
        }

        if ((int) ($path[1]['parent_id'] ?? 0) !== (int) ($path[0]['id'] ?? 0)
            || (int) ($path[2]['parent_id'] ?? 0) !== (int) ($path[1]['id'] ?? 0)
            || (int) ($path[3]['parent_id'] ?? 0) !== (int) ($path[2]['id'] ?? 0)
        ) {
            return '地区父子关系不匹配';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $province
     * @param array<string, mixed> $city
     * @param array<string, mixed> $district
     * @param array<string, mixed> $street
     * @return array<string, mixed>
     */
    protected function buildAddressRegionPayload(
        array|\ArrayAccess $province,
        array|\ArrayAccess $city,
        array|\ArrayAccess $district,
        array|\ArrayAccess $street,
    ): array {
        return [
            'province_id' => (int) $province['id'],
            'province_code' => (string) $province['code'],
            'province_name' => (string) $province['name'],
            'city_id' => (int) $city['id'],
            'city_code' => (string) $city['code'],
            'city_name' => (string) $city['name'],
            'district_id' => (int) $district['id'],
            'district_code' => (string) $district['code'],
            'district_name' => (string) $district['name'],
            'street_id' => (int) $street['id'],
            'street_code' => (string) $street['code'],
            'street_name' => (string) $street['name'],
            'region_path_text' => implode(' / ', [
                (string) $province['name'],
                (string) $city['name'],
                (string) $district['name'],
                (string) $street['name'],
            ]),
            'region_status' => 1,
            'region_invalid_reason' => null,
        ];
    }
}
