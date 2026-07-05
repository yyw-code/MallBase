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

    public function bindByInviteCode(int $userId, string $inviteCode, string $source = 'invite'): void
    {
        if (!app()->make(DistributionConfigService::class)->isEnabled()) {
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

        $this->transaction(function () use ($userId, $parentUserId, $grandparentUserId, $inviteCode, $source): void {
            $this->model()->save([
                'user_id' => $userId,
                'parent_user_id' => $parentUserId,
                'grandparent_user_id' => $grandparentUserId,
                'invite_code' => strtoupper(trim($inviteCode)),
                'source' => mb_substr(trim($source), 0, 32),
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
     * @return array<int,array{user_id:int,relation_level:int}>
     */
    public function beneficiariesForBuyer(int $buyerUserId, bool $selfPurchaseEnabled): array
    {
        $beneficiaries = [];
        /** @var DistributionAccountService $accountService */
        $accountService = app()->make(DistributionAccountService::class);

        if ($selfPurchaseEnabled) {
            $active = $accountService->activeDistributorsByUserIds([$buyerUserId]);
            if (isset($active[$buyerUserId])) {
                $beneficiaries[] = ['user_id' => $buyerUserId, 'relation_level' => 1];
            }
        }

        /** @var DistributionRelation|null $relation */
        $relation = $this->model()->where('user_id', $buyerUserId)->find();
        if ($relation === null) {
            return $beneficiaries;
        }

        $parentUserId = (int) $relation->parent_user_id;
        $grandparentUserId = (int) $relation->grandparent_user_id;
        $candidateIds = array_filter([$parentUserId, $grandparentUserId], static fn(int $id): bool => $id > 0);
        $activeMap = $accountService->activeDistributorsByUserIds($candidateIds);

        if ($parentUserId > 0 && isset($activeMap[$parentUserId])) {
            $beneficiaries[] = [
                'user_id' => $parentUserId,
                'relation_level' => $selfPurchaseEnabled && $beneficiaries !== [] ? 2 : 1,
            ];
        }
        if (!$selfPurchaseEnabled && $grandparentUserId > 0 && isset($activeMap[$grandparentUserId])) {
            $beneficiaries[] = ['user_id' => $grandparentUserId, 'relation_level' => 2];
        }

        $deduped = [];
        foreach ($beneficiaries as $row) {
            $key = $row['user_id'] . ':' . $row['relation_level'];
            $deduped[$key] = $row;
        }
        return array_values($deduped);
    }
}
