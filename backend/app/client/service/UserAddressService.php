<?php
declare(strict_types=1);

namespace app\client\service;

use app\model\user\UserAddress;
use app\service\RegionResolverService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * @extends BaseService<UserAddress>
 */
class UserAddressService extends BaseService
{
    protected string $modelClass = UserAddress::class;

    public function getMyList(int $userId): array
    {
        $list = $this->model()
            ->where('user_id', $userId)
            ->whereNull('delete_time')
            ->order('is_default', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return array_map(fn(array $item) => $this->refreshRegionState($item), $list);
    }

    public function getMyInfo(int $userId, int $id): array
    {
        $info = $this->model()->where('id', $id)->where('user_id', $userId)->whereNull('delete_time')->find();
        if (!$info) {
            throw new BusinessException('地址不存在');
        }

        return $this->refreshRegionState($info->toArray());
    }

    public function create(int $userId, array $data): int
    {
        $regionData = app()->make(RegionResolverService::class)->normalizeAddressRegion($data);
        $payload = array_merge($data, $regionData, ['user_id' => $userId]);

        return $this->transaction(function () use ($payload, $userId) {
            if ((int) ($payload['is_default'] ?? 0) === 1) {
                $this->clearDefaultAddress($userId);
            }

            $address = $this->model()->create($payload);

            if ($this->model()->where('user_id', $userId)->whereNull('delete_time')->count() === 1) {
                $address->save(['is_default' => 1]);
            }

            return (int) $address->id;
        });
    }

    public function update(int $userId, int $id, array $data): bool
    {
        $address = $this->model()->where('id', $id)->where('user_id', $userId)->whereNull('delete_time')->find();
        if (!$address) {
            throw new BusinessException('地址不存在');
        }

        $regionData = app()->make(RegionResolverService::class)->normalizeAddressRegion($data);
        $payload = array_merge($data, $regionData);

        return $this->transaction(function () use ($address, $payload, $userId) {
            if ((int) ($payload['is_default'] ?? 0) === 1) {
                $this->clearDefaultAddress($userId, (int) $address->id);
            }
            $address->save($payload);
            return true;
        });
    }

    public function delete(int $userId, int $id): bool
    {
        $address = $this->model()->where('id', $id)->where('user_id', $userId)->whereNull('delete_time')->find();
        if (!$address) {
            throw new BusinessException('地址不存在');
        }

        $wasDefault = (int) $address->is_default === 1;
        $address->delete();

        if ($wasDefault) {
            $next = $this->model()->where('user_id', $userId)->whereNull('delete_time')->order('id', 'desc')->find();
            if ($next) {
                $next->save(['is_default' => 1]);
            }
        }

        return true;
    }

    public function setDefault(int $userId, int $id): bool
    {
        $address = $this->model()->where('id', $id)->where('user_id', $userId)->whereNull('delete_time')->find();
        if (!$address) {
            throw new BusinessException('地址不存在');
        }

        $state = $this->refreshRegionState($address->toArray());
        if ((int) $state['region_status'] !== 1) {
            throw new BusinessException('该地址所属区域已失效，请编辑后再使用');
        }

        return $this->transaction(function () use ($userId, $address) {
            $this->clearDefaultAddress($userId, (int) $address->id);
            $address->save(['is_default' => 1]);
            return true;
        });
    }

    protected function clearDefaultAddress(int $userId, ?int $excludeId = null): void
    {
        $query = $this->model()->where('user_id', $userId)->whereNull('delete_time');
        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }
        $query->update(['is_default' => 0]);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function refreshRegionState(array $item): array
    {
        $valid = app()->make(RegionResolverService::class)->isAddressRegionValid($item);
        $item['region_status'] = $valid ? 1 : 0;
        $item['region_invalid_reason'] = $valid ? null : '关联街道已失效，请重新编辑地址';
        return $item;
    }
}
