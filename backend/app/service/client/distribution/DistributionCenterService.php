<?php
declare(strict_types=1);

namespace app\service\client\distribution;

use app\model\distribution\DistributionCommissionLog;
use app\model\distribution\DistributionDistributor;
use app\model\distribution\DistributionOrderCommission;
use app\model\distribution\DistributionRelation;
use app\model\distribution\DistributionWithdraw;
use app\model\user\User;
use app\service\distribution\DistributionAccountService;
use app\service\distribution\DistributionConfigService;
use app\service\distribution\DistributionEnrollmentService;
use app\service\distribution\DistributionRelationService;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 客户端分销中心服务
 *
 * @extends BaseService<DistributionDistributor>
 */
class DistributionCenterService extends BaseService
{
    protected string $modelClass = DistributionDistributor::class;

    public function summary(int $userId): array
    {
        /** @var DistributionEnrollmentService $enrollmentService */
        $enrollmentService = app()->make(DistributionEnrollmentService::class);
        $enrollmentService->ensureEveryoneDistributor($userId);

        $account = $this->distributor($userId);
        $settings = app()->make(DistributionConfigService::class)->settings();
        $qualification = $enrollmentService->qualificationSummary($userId);
        if ($account === null) {
            return [
                'enabled' => (bool) $settings['distribution_enabled'],
                'is_distributor' => false,
                'status' => 0,
                'message' => '暂未开通分销员资格',
                'qualification' => $qualification,
            ];
        }

        return [
            'enabled' => (bool) $settings['distribution_enabled'],
            'is_distributor' => true,
            'status' => (int) $account->status,
            'invite_code' => (string) $account->invite_code,
            'available_commission' => $this->centsToAmount((int) $account->available_commission_cents),
            'frozen_commission' => $this->centsToAmount((int) $account->frozen_commission_cents),
            'pending_withdraw' => $this->centsToAmount((int) $account->pending_withdraw_cents),
            'withdrawn_commission' => $this->centsToAmount((int) $account->withdrawn_commission_cents),
            'debt_commission' => $this->centsToAmount((int) $account->debt_commission_cents),
            'direct_user_count' => (int) $account->direct_user_count,
            'indirect_user_count' => (int) $account->indirect_user_count,
            'order_count' => (int) $account->order_count,
            'min_withdraw_amount' => $this->centsToAmount((int) $settings['min_withdraw_cents']),
            'qualification' => $qualification,
        ];
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function commissions(int $userId, array $where, int $page, int $limit): array
    {
        $this->assertDistributor($userId);
        $query = $this->model(DistributionOrderCommission::class)
            ->where('distributor_user_id', $userId)
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
        $total = (int) (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $list = array_map(fn(array $row): array => $this->formatCommission($row), $rows);

        return compact('total', 'list');
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function logs(int $userId, array $where, int $page, int $limit): array
    {
        $this->assertDistributor($userId);
        $query = $this->model(DistributionCommissionLog::class)
            ->where('user_id', $userId)
            ->when(($where['direction'] ?? '') !== '', function ($q) use ($where) {
                $q->where('direction', (string) $where['direction']);
            });
        $total = (int) (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $list = array_map(fn(array $row): array => $this->formatLog($row), $rows);

        return compact('total', 'list');
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function team(int $userId, int $level, int $page, int $limit): array
    {
        $this->assertDistributor($userId);
        $level = $level === 2 ? 2 : 1;
        $query = $this->model(DistributionRelation::class)
            ->when($level === 1, function ($q) use ($userId) {
                $q->where('parent_user_id', $userId);
            })
            ->when($level === 2, function ($q) use ($userId) {
                $q->where('grandparent_user_id', $userId);
            });
        $total = (int) (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $userIds = array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), $rows);
        $users = $this->usersByIds($userIds);
        $list = array_map(static function (array $row) use ($users, $level): array {
            $userId = (int) ($row['user_id'] ?? 0);
            return [
                'id' => (int) ($row['id'] ?? 0),
                'user_id' => $userId,
                'relation_level' => $level,
                'user' => $users[$userId] ?? null,
                'create_time' => (string) ($row['create_time'] ?? ''),
            ];
        }, $rows);

        return compact('total', 'list');
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function withdraws(int $userId, array $where, int $page, int $limit): array
    {
        $this->assertDistributor($userId);
        $query = $this->model(DistributionWithdraw::class)
            ->where('user_id', $userId)
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
        $total = (int) (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $list = array_map(fn(array $row): array => $this->formatWithdraw($row), $rows);

        return compact('total', 'list');
    }

    public function applyWithdraw(int $userId, string $amount, string $accountType, string $accountName, string $accountNo): int
    {
        $this->assertDistributor($userId);
        $settings = $this->assertDistributionEnabled(app()->make(DistributionConfigService::class)->settings());
        $amountCents = $this->amountToCents($amount);
        if ($amountCents < (int) $settings['min_withdraw_cents']) {
            throw new BusinessException('提现金额低于最低提现金额');
        }
        $accountName = mb_substr(trim($accountName), 0, 80);
        $accountNo = mb_substr(trim($accountNo), 0, 120);
        if ($accountName === '') {
            throw new BusinessException('请填写收款账户名');
        }
        if ($accountNo === '') {
            throw new BusinessException('请填写收款账号');
        }

        /** @var DistributionAccountService $accountService */
        $accountService = app()->make(DistributionAccountService::class);

        return (int) $this->transaction(function () use ($userId, $amountCents, $accountType, $accountName, $accountNo, $accountService): int {
            $sn = $this->withdrawSn();
            /** @var DistributionWithdraw $withdraw */
            $withdraw = $this->model(DistributionWithdraw::class);
            $withdraw->save([
                'sn' => $sn,
                'user_id' => $userId,
                'amount_cents' => $amountCents,
                'account_type' => mb_substr(trim($accountType) ?: 'offline', 0, 32),
                'account_name' => $accountName,
                'account_no' => $accountNo,
                'status' => DistributionWithdraw::STATUS_PENDING,
            ]);

            $accountService->holdWithdraw($userId, $amountCents, (int) $withdraw->id, $sn);
            return (int) $withdraw->id;
        });
    }

    public function bindInvite(int $userId, string $inviteCode): void
    {
        app()->make(DistributionRelationService::class)->bindByInviteCode($userId, $inviteCode, 'client');
    }

    /**
     * @param array<string,mixed> $attribution
     */
    public function bindInviteWithAttribution(int $userId, string $inviteCode, array $attribution): void
    {
        app()->make(DistributionRelationService::class)
            ->bindByInviteCode($userId, $inviteCode, 'client', $attribution);
    }

    public function applyDistributor(int $userId, string $realName, string $mobile, string $reason, string $proofImage = ''): int
    {
        return app()->make(DistributionEnrollmentService::class)
            ->apply($userId, $realName, $mobile, $reason, $proofImage);
    }

    public function withdrawApply(int $userId): void
    {
        app()->make(DistributionEnrollmentService::class)->withdrawPendingApply($userId);
    }

    /**
     * @return array<string,mixed>
     */
    public function shareInfo(int $userId, string $targetType, int $targetId, string $page = '', string $scene = 'share_link'): array
    {
        $this->assertDistributionEnabled();
        $account = $this->assertDistributor($userId);
        $targetType = mb_substr(trim($targetType), 0, 32);
        $targetId = max(0, $targetId);
        $page = mb_substr(trim($page), 0, 128);
        $scene = mb_substr(trim($scene), 0, 32) ?: 'share_link';
        $inviteCode = (string) $account->invite_code;
        $params = [
            'invite_code' => $inviteCode,
            'dist_scene' => $scene,
            'dist_target_type' => $targetType,
            'dist_target_id' => $targetId,
        ];
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $miniProgramPage = $this->miniProgramPage($page);

        return [
            'invite_code' => $inviteCode,
            'scene' => $scene,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'page' => $page,
            'query' => $query,
            'path' => $page === '' ? '' : $page . (str_contains($page, '?') ? '&' : '?') . $query,
            'mini_program_page' => $miniProgramPage,
            'mini_program_scene' => $this->miniProgramScene($inviteCode, $scene, $targetType, $targetId),
        ];
    }

    private function miniProgramPage(string $page): string
    {
        $path = trim(explode('?', $page, 2)[0]);
        return ltrim($path, '/');
    }

    private function miniProgramScene(string $inviteCode, string $scene, string $targetType, int $targetId): string
    {
        $parts = [
            $inviteCode,
            $this->miniProgramSceneAlias($scene),
        ];
        if ($targetType !== '' || $targetId > 0) {
            $parts[] = $this->miniProgramTargetAlias($targetType);
            $parts[] = $targetId > 0 ? strtolower(base_convert((string) $targetId, 10, 36)) : '0';
        }

        return implode('.', $parts);
    }

    private function miniProgramSceneAlias(string $scene): string
    {
        return match ($scene) {
            'poster' => 'p',
            'manual' => 'm',
            'share_link' => 'l',
            default => mb_substr($scene, 0, 3),
        };
    }

    private function miniProgramTargetAlias(string $targetType): string
    {
        return match ($targetType) {
            'goods' => 'g',
            'article' => 'a',
            'page' => 'p',
            default => mb_substr($targetType, 0, 4),
        };
    }

    /**
     * @param array<string,mixed>|null $settings
     * @return array<string,mixed>
     */
    private function assertDistributionEnabled(?array $settings = null): array
    {
        if ($settings === null) {
            $settings = app()->make(DistributionConfigService::class)->settings();
        }
        if (!$settings['distribution_enabled']) {
            throw new BusinessException('分销功能未开启');
        }
        return $settings;
    }

    private function assertDistributor(int $userId): DistributionDistributor
    {
        $this->assertDistributionEnabled();
        $account = $this->distributor($userId);
        if ($account === null || (int) $account->status !== DistributionDistributor::STATUS_ENABLED) {
            throw new BusinessException('暂未开通分销员资格');
        }
        return $account;
    }

    private function distributor(int $userId): ?DistributionDistributor
    {
        if ($userId <= 0) {
            throw new BusinessException('未登录');
        }
        /** @var DistributionDistributor|null $account */
        $account = $this->model()->where('user_id', $userId)->find();
        return $account;
    }

    private function formatCommission(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'order_sn' => (string) ($row['order_sn'] ?? ''),
            'relation_level' => (int) ($row['relation_level'] ?? 0),
            'base_amount' => $this->centsToAmount((int) ($row['base_amount_cents'] ?? 0)),
            'rate' => number_format((float) ($row['rate'] ?? 0), 2, '.', ''),
            'amount' => $this->centsToAmount((int) ($row['amount_cents'] ?? 0)),
            'recovered_amount' => $this->centsToAmount((int) ($row['recovered_cents'] ?? 0)),
            'status' => (int) ($row['status'] ?? 0),
            'status_text' => DistributionOrderCommission::statusText((int) ($row['status'] ?? 0)),
            'release_time' => (string) ($row['release_time'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
        ];
    }

    private function formatLog(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'biz_type' => (string) ($row['biz_type'] ?? ''),
            'biz_type_text' => DistributionCommissionLog::bizTypeText((string) ($row['biz_type'] ?? '')),
            'biz_id' => (string) ($row['biz_id'] ?? ''),
            'account_type' => (string) ($row['account_type'] ?? ''),
            'direction' => (string) ($row['direction'] ?? ''),
            'change_amount' => $this->centsToAmount((int) ($row['change_cents'] ?? 0)),
            'before_amount' => $this->centsToAmount((int) ($row['before_cents'] ?? 0)),
            'after_amount' => $this->centsToAmount((int) ($row['after_cents'] ?? 0)),
            'remark' => (string) ($row['remark'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
        ];
    }

    private function formatWithdraw(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'sn' => (string) ($row['sn'] ?? ''),
            'amount' => $this->centsToAmount((int) ($row['amount_cents'] ?? 0)),
            'account_type' => (string) ($row['account_type'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'account_no' => (string) ($row['account_no'] ?? ''),
            'status' => (int) ($row['status'] ?? 0),
            'status_text' => DistributionWithdraw::statusText((int) ($row['status'] ?? 0)),
            'admin_remark' => (string) ($row['admin_remark'] ?? ''),
            'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
        ];
    }

    /**
     * @param array<int> $ids
     * @return array<int,array<string,mixed>>
     */
    private function usersByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $rows = $this->model(User::class)
            ->whereIn('id', $ids)
            ->field('id,nickname,mobile,avatar')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'nickname' => (string) ($row['nickname'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'avatar' => $row['avatar'] ?? null,
            ];
        }
        return $map;
    }

    private function amountToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new BusinessException('金额格式不合法');
        }
        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $yuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
    }

    private function centsToAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function withdrawSn(): string
    {
        return 'DW' . date('ymdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
