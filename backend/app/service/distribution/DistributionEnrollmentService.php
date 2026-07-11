<?php
declare(strict_types=1);

namespace app\service\distribution;

use app\common\enum\OrderStatus;
use app\model\distribution\DistributionApply;
use app\model\distribution\DistributionDistributor;
use app\model\distribution\DistributionRelation;
use app\model\order\Order;
use app\model\user\User;
use app\service\upload\AssetHydrator;
use app\service\upload\AssetService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 分销员准入与邀请奖励服务
 *
 * @extends BaseService<DistributionApply>
 */
class DistributionEnrollmentService extends BaseService
{
    protected string $modelClass = DistributionApply::class;

    public function ensureEveryoneDistributor(int $userId): void
    {
        $settings = app()->make(DistributionConfigService::class)->settings();
        if (!$settings['distribution_enabled'] || $settings['distributor_open_mode'] !== DistributionConfigService::OPEN_MODE_EVERYONE) {
            return;
        }
        $this->ensureDistributor($userId, (int) $settings['auto_open_level_id'], 'everyone', '人人分销自动开通');
    }

    public function handlePaidOrderQualification(Order $order): void
    {
        $settings = app()->make(DistributionConfigService::class)->settings();
        if (!$settings['distribution_enabled']) {
            return;
        }

        $buyerUserId = (int) $order->user_id;
        if ($buyerUserId <= 0) {
            return;
        }

        if ($settings['distributor_open_mode'] === DistributionConfigService::OPEN_MODE_AMOUNT) {
            $threshold = (int) $settings['amount_open_threshold_cents'];
            if ($threshold > 0 && $this->paidAmountCents($buyerUserId) >= $threshold) {
                $this->ensureDistributor($buyerUserId, (int) $settings['auto_open_level_id'], 'amount', '累计消费满额自动开通');
            }
        }

        if ($this->paidOrderCount($buyerUserId) <= 1) {
            $this->tryGrantInviteRewardForBuyer($buyerUserId, DistributionConfigService::INVITE_REWARD_TRIGGER_FIRST_ORDER);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function qualificationSummary(int $userId): array
    {
        $settings = app()->make(DistributionConfigService::class)->settings();
        $latestApply = $this->latestApply($userId);
        $paidCents = $settings['distributor_open_mode'] === DistributionConfigService::OPEN_MODE_AMOUNT
            ? $this->paidAmountCents($userId)
            : 0;

        return [
            'open_mode' => $settings['distributor_open_mode'],
            'can_apply' => $settings['distribution_enabled'] && $settings['distributor_open_mode'] === DistributionConfigService::OPEN_MODE_APPLY,
            'apply_status' => $latestApply['status'] ?? null,
            'apply_status_text' => isset($latestApply['status']) ? DistributionApply::statusText((int) $latestApply['status']) : '',
            'apply_review_remark' => (string) ($latestApply['review_remark'] ?? ''),
            'latest_apply' => $latestApply === null ? null : $this->formatLatestApply($latestApply),
            'amount_open_threshold' => app()->make(DistributionAccountService::class)->centsToAmount((int) $settings['amount_open_threshold_cents']),
            'amount_open_paid' => app()->make(DistributionAccountService::class)->centsToAmount($paidCents),
        ];
    }

    public function apply(int $userId, string $realName, string $mobile, string $reason, string $proofImage = ''): int
    {
        $settings = app()->make(DistributionConfigService::class)->settings();
        if (!$settings['distribution_enabled']) {
            throw new BusinessException('分销功能未开启');
        }
        if ($settings['distributor_open_mode'] !== DistributionConfigService::OPEN_MODE_APPLY) {
            throw new BusinessException('当前不支持申请开通分销员');
        }
        $this->assertUserExists($userId);
        if ($this->distributorExists($userId)) {
            throw new BusinessException('已开通分销员资格');
        }
        if ($this->model()->where('user_id', $userId)->where('status', DistributionApply::STATUS_PENDING)->count() > 0) {
            throw new BusinessException('已有待审核申请');
        }

        $realName = mb_substr(trim($realName), 0, 60);
        $mobile = mb_substr(trim($mobile), 0, 20);
        $reason = mb_substr(trim($reason), 0, 500);
        if ($realName === '') {
            throw new BusinessException('请填写申请人姓名');
        }
        if ($mobile === '') {
            throw new BusinessException('请填写联系电话');
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            throw new BusinessException('请输入正确的手机号');
        }
        $proofImage = $this->normalizeProofImage($proofImage);

        return (int) $this->transaction(function () use ($userId, $realName, $mobile, $reason, $proofImage): int {
            /** @var DistributionApply $apply */
            $apply = $this->model();
            $apply->save([
                'user_id' => $userId,
                'real_name' => $realName,
                'mobile' => $mobile,
                'reason' => $reason,
                'proof_image' => $proofImage,
                'status' => DistributionApply::STATUS_PENDING,
            ]);

            if ($proofImage !== '') {
                app()->make(AssetService::class)->syncUsage('distribution_apply', (int) $apply->id, 'proof_image', [$proofImage]);
            }

            return (int) $apply->id;
        });
    }

    public function approveApply(int $applyId, int $adminId, int $levelId, string $remark = ''): void
    {
        $this->reviewApply($applyId, DistributionApply::STATUS_APPROVED, $adminId, $levelId, $remark);
    }

    public function rejectApply(int $applyId, int $adminId, string $remark): void
    {
        if (trim($remark) === '') {
            throw new BusinessException('请填写驳回原因');
        }
        $this->reviewApply($applyId, DistributionApply::STATUS_REJECTED, $adminId, 0, $remark);
    }

    public function withdrawPendingApply(int $userId): void
    {
        $settings = app()->make(DistributionConfigService::class)->settings();
        if (!$settings['distribution_enabled']) {
            throw new BusinessException('分销功能未开启');
        }
        if ($settings['distributor_open_mode'] !== DistributionConfigService::OPEN_MODE_APPLY) {
            throw new BusinessException('当前不支持申请开通分销员');
        }
        $this->assertUserExists($userId);
        if ($this->distributorExists($userId)) {
            throw new BusinessException('已开通分销员资格');
        }

        $applyId = (int) $this->model()
            ->where('user_id', $userId)
            ->where('status', DistributionApply::STATUS_PENDING)
            ->order('id', 'desc')
            ->value('id');
        if ($applyId <= 0) {
            throw new BusinessException('暂无待审核申请');
        }

        $updated = 0;
        $this->transaction(function () use ($applyId, &$updated): void {
            $updated = (int) $this->model()
                ->where('id', $applyId)
                ->where('status', DistributionApply::STATUS_PENDING)
                ->update([
                    'status' => DistributionApply::STATUS_WITHDRAWN,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
        });
        if ($updated <= 0) {
            throw new BusinessException('分销员申请已处理');
        }
    }

    public function tryGrantInviteRewardForBuyer(int $buyerUserId, string $trigger): bool
    {
        /** @var DistributionRelation|null $relation */
        $relation = $this->validRelationQuery()
            ->where('user_id', $buyerUserId)
            ->find();
        if ($relation === null) {
            return false;
        }

        return $this->tryGrantInviteReward((int) $relation->id, $trigger);
    }

    public function tryGrantInviteReward(int $relationId, string $trigger): bool
    {
        $settings = app()->make(DistributionConfigService::class)->settings();
        if (
            !$settings['distribution_enabled']
            || !$settings['invite_reward_enabled']
            || $settings['invite_reward_trigger'] !== $trigger
            || (int) $settings['invite_reward_amount_cents'] <= 0
        ) {
            return false;
        }

        /** @var DistributionAccountService $accountService */
        $accountService = app()->make(DistributionAccountService::class);

        return (bool) $this->transaction(function () use ($relationId, $settings, $accountService): bool {
            /** @var DistributionRelation|null $relation */
            $relation = $this->model(DistributionRelation::class)
                ->where('id', $relationId)
                ->lock(true)
                ->find();
            if ($relation === null || (int) $relation->invite_reward_status === 1) {
                return false;
            }
            if (!$this->isRelationValid($relation->expire_time ? (string) $relation->expire_time : null)) {
                return false;
            }

            $parentUserId = (int) $relation->parent_user_id;
            if ($parentUserId <= 0) {
                return false;
            }

            /** @var DistributionDistributor|null $parentDistributor */
            $parentDistributor = $this->model(DistributionDistributor::class)
                ->where('user_id', $parentUserId)
                ->lock(true)
                ->find();
            if ($parentDistributor === null || (int) $parentDistributor->status !== DistributionDistributor::STATUS_ENABLED) {
                return false;
            }
            $amountCents = (int) $settings['invite_reward_amount_cents'];
            $relation->invite_reward_status = 1;
            $relation->invite_reward_cents = $amountCents;
            $relation->invite_reward_at = date('Y-m-d H:i:s');
            $relation->save();

            $accountService->addInviteReward($parentUserId, $amountCents, (int) $relation->id);
            return true;
        });
    }

    private function reviewApply(int $applyId, int $status, int $adminId, int $levelId, string $remark): void
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }

        $settings = app()->make(DistributionConfigService::class)->settings();
        $levelId = $levelId > 0 ? $levelId : (int) $settings['auto_open_level_id'];

        $this->transaction(function () use ($applyId, $status, $adminId, $levelId, $remark): void {
            /** @var DistributionApply|null $apply */
            $apply = $this->model()
                ->where('id', $applyId)
                ->lock(true)
                ->find();
            if ($apply === null) {
                throw new BusinessException('分销员申请不存在');
            }
            if ((int) $apply->status !== DistributionApply::STATUS_PENDING) {
                throw new BusinessException('分销员申请已处理');
            }

            if ($status === DistributionApply::STATUS_APPROVED) {
                app()->make(DistributionAccountService::class)
                    ->autoOpenDistributor((int) $apply->user_id, $levelId, 'apply', '申请审核通过');
            }

            $apply->status = $status;
            $apply->review_admin_id = $adminId;
            $apply->review_remark = mb_substr(trim($remark), 0, 255);
            $apply->reviewed_at = date('Y-m-d H:i:s');
            $apply->save();
        });
    }

    private function normalizeProofImage(string $proofImage): string
    {
        $proofImage = trim($proofImage);
        if ($proofImage === '') {
            return '';
        }
        if (!ctype_digit($proofImage)) {
            throw new BusinessException('请先上传申请凭证图片');
        }

        $assetId = (int) $proofImage;
        if ($assetId <= 0) {
            throw new BusinessException('申请凭证图片不正确');
        }
        app()->make(AssetService::class)->assertUsableImageAssets([$assetId]);

        return (string) $assetId;
    }

    private function ensureDistributor(int $userId, int $levelId, string $source, string $remark): void
    {
        if ($userId <= 0 || $this->distributorExists($userId)) {
            return;
        }
        app()->make(DistributionAccountService::class)
            ->autoOpenDistributor($userId, $levelId, $source, $remark);
    }

    private function distributorExists(int $userId): bool
    {
        return $this->model(DistributionDistributor::class)->where('user_id', $userId)->count() > 0;
    }

    private function paidAmountCents(int $userId): int
    {
        $amount = (string) $this->model(Order::class)
            ->where('user_id', $userId)
            ->whereIn('status', [OrderStatus::PAID, OrderStatus::SHIPPED, OrderStatus::RECEIVED, OrderStatus::COMPLETED])
            ->sum('pay_amount');

        return $this->decimalToCents($amount);
    }

    private function paidOrderCount(int $userId): int
    {
        return (int) $this->model(Order::class)
            ->where('user_id', $userId)
            ->whereIn('status', [OrderStatus::PAID, OrderStatus::SHIPPED, OrderStatus::RECEIVED, OrderStatus::COMPLETED])
            ->count();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestApply(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $row = $this->model()
            ->where('user_id', $userId)
            ->order('id', 'desc')
            ->find();
        return $row?->toArray();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatLatestApply(array $row): array
    {
        $proofImage = (string) ($row['proof_image'] ?? '');
        $hydrated = app()->make(AssetHydrator::class)->hydrateFields([$row], [
            'proof_image' => 'proof_image_full_url',
        ]);
        $row = $hydrated[0] ?? $row;
        $status = (int) ($row['status'] ?? 0);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'real_name' => (string) ($row['real_name'] ?? ''),
            'mobile' => (string) ($row['mobile'] ?? ''),
            'reason' => (string) ($row['reason'] ?? ''),
            'proof_image' => $proofImage,
            'proof_image_full_url' => (string) ($row['proof_image_full_url'] ?? ''),
            'status' => $status,
            'status_text' => DistributionApply::statusText($status),
            'review_remark' => (string) ($row['review_remark'] ?? ''),
            'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
            'update_time' => (string) ($row['update_time'] ?? ''),
        ];
    }

    private function assertUserExists(int $userId): void
    {
        if ($userId <= 0) {
            throw new BusinessException('未登录');
        }
        if ($this->model(User::class)->where('id', $userId)->whereNull('delete_time')->count() <= 0) {
            throw new BusinessException('用户不存在');
        }
    }

    private function validRelationQuery()
    {
        return $this->model(DistributionRelation::class);
    }

    private function isRelationValid(?string $expireTime): bool
    {
        return $expireTime === null || trim($expireTime) === '' || strtotime($expireTime) > time();
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            return 0;
        }
        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $yuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
    }
}
