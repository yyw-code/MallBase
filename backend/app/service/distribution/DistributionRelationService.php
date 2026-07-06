<?php
declare(strict_types=1);

namespace app\service\distribution;

use app\model\distribution\DistributionDistributor;
use app\model\distribution\DistributionRelation;
use app\model\user\User;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 分销关系服务
 *
 * @extends BaseService<DistributionRelation>
 */
class DistributionRelationService extends BaseService
{
    protected string $modelClass = DistributionRelation::class;

    /**
     * @param array<string,mixed> $attribution
     */
    public function bindByInviteCode(int $userId, string $inviteCode, string $source = 'invite', array $attribution = []): void
    {
        /** @var DistributionConfigService $configService */
        $configService = app()->make(DistributionConfigService::class);
        $settings = $configService->settings();
        if (!$settings['distribution_enabled']) {
            throw new BusinessException('分销功能未开启');
        }
        if ($userId <= 0) {
            throw new BusinessException('未登录');
        }
        if ($this->model(User::class)->where('id', $userId)->whereNull('delete_time')->count() <= 0) {
            throw new BusinessException('用户不存在');
        }
        if ($this->model()->where('user_id', $userId)->count() > 0) {
            throw new BusinessException('已绑定邀请关系');
        }

        /** @var DistributionAccountService $accountService */
        $accountService = app()->make(DistributionAccountService::class);
        $parent = $accountService->activeDistributorByInviteCode($inviteCode);
        if ($parent === null) {
            throw new BusinessException('邀请码无效或分销员已禁用');
        }

        $parentUserId = (int) $parent->user_id;
        if ($parentUserId === $userId) {
            throw new BusinessException('不能绑定自己为上级');
        }

        /** @var DistributionRelation|null $parentRelation */
        $parentRelation = $this->model()->where('user_id', $parentUserId)->find();
        $grandparentUserId = (int) ($parentRelation->parent_user_id ?? 0);
        if ($grandparentUserId === $userId || (int) ($parentRelation->grandparent_user_id ?? 0) === $userId) {
            throw new BusinessException('不能形成循环邀请关系');
        }

        $attribution = $this->normalizeAttribution($attribution, (bool) $settings['attribution_enabled']);

        $this->transaction(function () use ($userId, $parentUserId, $grandparentUserId, $inviteCode, $source, $attribution): void {
            /** @var DistributionRelation $relation */
            $relation = $this->model();
            $relation->save([
                'user_id' => $userId,
                'parent_user_id' => $parentUserId,
                'grandparent_user_id' => $grandparentUserId,
                'invite_code' => strtoupper(trim($inviteCode)),
                'source' => mb_substr(trim($source), 0, 32),
                'expire_time' => null,
                'attribution_scene' => $attribution['scene'],
                'attribution_page' => $attribution['page'],
                'attribution_target_type' => $attribution['target_type'],
                'attribution_target_id' => $attribution['target_id'],
            ]);

            $this->model(DistributionDistributor::class)
                ->where('user_id', $parentUserId)
                ->inc('direct_user_count')
                ->update();
            if ($grandparentUserId > 0) {
                $this->model(DistributionDistributor::class)
                    ->where('user_id', $grandparentUserId)
                    ->inc('indirect_user_count')
                    ->update();
            }

        });
    }

    /**
     * @return array<int,array{user_id:int,relation_level:int,relation_id:int,attribution_scene:string,attribution_target_type:string,attribution_target_id:int}>
     */
    public function beneficiariesForBuyer(int $buyerUserId, bool $selfPurchaseEnabled, bool $secondLevelEnabled = false): array
    {
        $beneficiaries = [];
        /** @var DistributionAccountService $accountService */
        $accountService = app()->make(DistributionAccountService::class);

        if ($selfPurchaseEnabled) {
            $active = $accountService->activeDistributorsByUserIds([$buyerUserId]);
            if (isset($active[$buyerUserId])) {
                $beneficiaries[] = $this->beneficiaryRow($buyerUserId, 1);
            }
        }

        /** @var DistributionRelation|null $relation */
        $relation = $this->model()->where('user_id', $buyerUserId)->find();
        if ($relation === null) {
            return $beneficiaries;
        }
        if (!$this->isRelationValid((string) ($relation->expire_time ?? ''))) {
            return $beneficiaries;
        }

        $parentUserId = (int) $relation->parent_user_id;
        $grandparentUserId = (int) $relation->grandparent_user_id;
        $candidateIds = array_filter([$parentUserId, $grandparentUserId], static fn(int $id): bool => $id > 0);
        $activeMap = $accountService->activeDistributorsByUserIds($candidateIds);

        $parentRelationLevel = $selfPurchaseEnabled && $beneficiaries !== [] ? 2 : 1;
        if ($parentUserId > 0 && isset($activeMap[$parentUserId]) && ($parentRelationLevel === 1 || $secondLevelEnabled)) {
            $beneficiaries[] = $this->beneficiaryRow($parentUserId, $parentRelationLevel, $relation);
        }
        if (!$secondLevelEnabled) {
            return $this->dedupeBeneficiaries($beneficiaries);
        }

        if (!$selfPurchaseEnabled && $grandparentUserId > 0 && isset($activeMap[$grandparentUserId])) {
            $beneficiaries[] = $this->beneficiaryRow($grandparentUserId, 2, $relation);
        }

        return $this->dedupeBeneficiaries($beneficiaries);
    }

    /**
     * @return array{user_id:int,relation_level:int,relation_id:int,attribution_scene:string,attribution_target_type:string,attribution_target_id:int}
     */
    private function beneficiaryRow(int $userId, int $level, ?DistributionRelation $relation = null): array
    {
        return [
            'user_id' => $userId,
            'relation_level' => $level,
            'relation_id' => $relation === null ? 0 : (int) $relation->id,
            'attribution_scene' => $relation === null ? '' : (string) ($relation->attribution_scene ?? ''),
            'attribution_target_type' => $relation === null ? '' : (string) ($relation->attribution_target_type ?? ''),
            'attribution_target_id' => $relation === null ? 0 : (int) ($relation->attribution_target_id ?? 0),
        ];
    }

    /**
     * @param array<int,array{user_id:int,relation_level:int,relation_id:int,attribution_scene:string,attribution_target_type:string,attribution_target_id:int}> $beneficiaries
     * @return array<int,array{user_id:int,relation_level:int,relation_id:int,attribution_scene:string,attribution_target_type:string,attribution_target_id:int}>
     */
    private function dedupeBeneficiaries(array $beneficiaries): array
    {
        $deduped = [];
        foreach ($beneficiaries as $row) {
            $key = $row['user_id'] . ':' . $row['relation_level'];
            $deduped[$key] = $row;
        }
        return array_values($deduped);
    }

    /**
     * @param array<string,mixed> $attribution
     * @return array{scene:string,page:string,target_type:string,target_id:int}
     */
    private function normalizeAttribution(array $attribution, bool $enabled): array
    {
        if (!$enabled) {
            return ['scene' => '', 'page' => '', 'target_type' => '', 'target_id' => 0];
        }

        return [
            'scene' => mb_substr(trim((string) ($attribution['scene'] ?? '')), 0, 32),
            'page' => mb_substr(trim((string) ($attribution['page'] ?? '')), 0, 128),
            'target_type' => mb_substr(trim((string) ($attribution['target_type'] ?? '')), 0, 32),
            'target_id' => max(0, (int) ($attribution['target_id'] ?? 0)),
        ];
    }

    private function isRelationValid(string $expireTime): bool
    {
        return true;
    }
}
