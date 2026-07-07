<?php
declare(strict_types=1);

namespace app\service\admin\distribution;

use app\common\enum\OperatorType;
use app\model\distribution\DistributionApply;
use app\model\distribution\DistributionCommissionLog;
use app\model\distribution\DistributionCommissionRule;
use app\model\distribution\DistributionDistributor;
use app\model\distribution\DistributionLevel;
use app\model\distribution\DistributionOrderCommission;
use app\model\distribution\DistributionRelation;
use app\model\distribution\DistributionWithdraw;
use app\model\order\Order;
use app\model\user\User;
use app\service\admin\setting\SettingService;
use app\service\distribution\DistributionAccountService;
use app\service\distribution\DistributionConfigService;
use app\service\distribution\DistributionEnrollmentService;
use app\service\distribution\DistributionOrderEventService;
use app\service\upload\AssetHydrator;
use app\service\upload\AssetIdNormalizer;
use app\service\upload\AssetResolver;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 后台分销管理服务
 *
 * @extends BaseService<DistributionDistributor>
 */
class DistributionManagementService extends BaseService
{
    protected string $modelClass = DistributionDistributor::class;

    public function overview(): array
    {
        $distributorTotal = (int) $this->model()->count();
        $enabledDistributorTotal = (int) $this->model()->where('status', DistributionDistributor::STATUS_ENABLED)->count();
        $commissionTotal = (int) $this->model(DistributionOrderCommission::class)->count();
        $frozenCents = (int) $this->model()->sum('frozen_commission_cents');
        $availableCents = (int) $this->model()->sum('available_commission_cents');
        $pendingWithdrawCents = (int) $this->model()->sum('pending_withdraw_cents');

        return [
            'distributor_total' => $distributorTotal,
            'enabled_distributor_total' => $enabledDistributorTotal,
            'commission_total' => $commissionTotal,
            'frozen_commission' => $this->centsToAmount($frozenCents),
            'available_commission' => $this->centsToAmount($availableCents),
            'pending_withdraw' => $this->centsToAmount($pendingWithdrawCents),
            'trend' => $this->buildCommissionTrend(7),
            'region_distribution' => $this->buildRegionDistribution(),
            'status_distribution' => $this->buildStatusDistribution(),
        ];
    }

    public function settings(): array
    {
        return app()->make(SettingService::class)->getGroupConfig(DistributionConfigService::GROUP_CODE);
    }

    public function saveSettings(array $data): void
    {
        $settingService = app()->make(SettingService::class);
        $currentValues = $settingService->getGroupValues(DistributionConfigService::GROUP_CODE);
        $submitted = [];
        foreach (array_keys($currentValues) as $code) {
            if (array_key_exists($code, $data) && $data[$code] !== null) {
                $submitted[$code] = $data[$code];
            }
        }
        $data = array_replace($currentValues, $submitted);

        $configService = app()->make(DistributionConfigService::class);
        $distributionEnabled = $configService->normalizeSwitch((string) ($data['distribution_enabled'] ?? '0'));
        $secondLevelEnabled = $configService->normalizeSwitch((string) ($data['second_level_enabled'] ?? '0'));
        $firstRate = $configService->normalizeRate((string) ($data['global_first_rate'] ?? '0'));
        $secondRate = $configService->normalizeRate((string) ($data['global_second_rate'] ?? '0'));
        if ($distributionEnabled === '1' && $secondLevelEnabled === '1') {
            $configService->assertRatePair($firstRate, $secondRate);
        }
        $data['distributor_open_mode'] = $configService->normalizeOpenMode((string) ($data['distributor_open_mode'] ?? DistributionConfigService::OPEN_MODE_MANUAL));
        $data['auto_open_level_id'] = $configService->normalizeUnsignedInt((string) ($data['auto_open_level_id'] ?? '1'), '自动开通等级ID不合法');
        $data['distribution_enabled'] = $distributionEnabled;
        $data['second_level_enabled'] = $secondLevelEnabled;
        $data['self_purchase_enabled'] = $configService->normalizeSwitch((string) ($data['self_purchase_enabled'] ?? '0'));
        $data['relation_valid_days'] = $configService->normalizeUnsignedInt((string) ($data['relation_valid_days'] ?? '0'), '绑定关系有效期不合法');
        $data['settlement_days'] = $configService->normalizeUnsignedInt((string) ($data['settlement_days'] ?? '0'), '结算等待天数不合法');
        $data['min_withdraw_cents'] = $configService->normalizeCents((string) ($data['min_withdraw_cents'] ?? '0'), '最低提现金额不合法');
        $data['global_first_rate'] = $firstRate;
        $data['global_second_rate'] = $secondRate;
        $data['amount_open_threshold_cents'] = $configService->normalizeCents((string) ($data['amount_open_threshold_cents'] ?? '0'), '满额开通门槛不合法');
        $data['invite_reward_enabled'] = $configService->normalizeSwitch((string) ($data['invite_reward_enabled'] ?? '0'));
        $data['invite_reward_trigger'] = $configService->normalizeInviteRewardTrigger((string) ($data['invite_reward_trigger'] ?? DistributionConfigService::INVITE_REWARD_TRIGGER_FIRST_ORDER));
        $data['invite_reward_amount_cents'] = $configService->normalizeCents((string) ($data['invite_reward_amount_cents'] ?? '0'), '固定邀请奖励金额不合法');
        $data['attribution_enabled'] = $configService->normalizeSwitch((string) ($data['attribution_enabled'] ?? '1'));

        $settingService->saveGroupValuesWithValidation(
            DistributionConfigService::GROUP_CODE,
            $data
        );
    }

    public function releaseDue(int $limit = 500): array
    {
        return app()->make(DistributionOrderEventService::class)->releaseDueCommissions($limit);
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function distributorList(array $where, int $page, int $limit): array
    {
        $query = $this->buildDistributorListQuery($where);
        $total = (int) (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $list = $this->formatDistributors($rows);

        return compact('total', 'list');
    }

    public function distributorInfo(int $id): array
    {
        /** @var DistributionDistributor|null $row */
        $row = $this->model()->where('id', $id)->find();
        if ($row === null) {
            throw new BusinessException('分销员不存在');
        }
        $list = $this->formatDistributors([$row->toArray()]);
        return $list[0] ?? [];
    }

    public function userDistributionSummary(int $userId): array
    {
        $settings = app()->make(DistributionConfigService::class)->settings();
        /** @var DistributionDistributor|null $account */
        $account = $this->model()->where('user_id', $userId)->find();
        $distributor = null;
        if ($account !== null) {
            $rows = $this->formatDistributors([$account->toArray()]);
            $distributor = $rows[0] ?? null;
        }

        return [
            'enabled' => (bool) $settings['distribution_enabled'],
            'is_distributor' => $distributor !== null,
            'distributor' => $distributor,
            'relation' => $this->userRelationSummary($userId),
        ];
    }

    /**
     * @return array{total_amount:string,list:array<int,array<string,mixed>>}
     */
    public function orderDistributionCommissions(int $orderId): array
    {
        if ($orderId <= 0) {
            return ['total_amount' => '0.00', 'list' => []];
        }

        $rows = $this->model(DistributionOrderCommission::class)
            ->where('order_id', $orderId)
            ->order('relation_level', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $totalCents = array_reduce(
            $rows,
            static fn(int $total, array $row): int => $total + (int) ($row['amount_cents'] ?? 0),
            0
        );
        $list = array_map(fn(array $row): array => $this->formatCommission($row), $rows);
        $users = $this->usersByIds(
            array_map(
                static fn(array $row): int => (int) ($row['distributor_user_id'] ?? 0),
                $list
            )
        );
        foreach ($list as &$item) {
            $distributorUserId = (int) ($item['distributor_user_id'] ?? 0);
            $item['distributor_user'] = $users[$distributorUserId] ?? null;
        }
        unset($item);

        return [
            'total_amount' => $this->centsToAmount($totalCents),
            'list' => $list,
        ];
    }

    public function openDistributor(int $userId, int $levelId, int $adminId, string $remark = ''): int
    {
        return app()->make(DistributionAccountService::class)
            ->openDistributor($userId, $levelId, $adminId, $remark);
    }

    public function updateDistributorStatus(int $userId, int $status, int $adminId): void
    {
        app()->make(DistributionAccountService::class)->updateStatus($userId, $status, $adminId);
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function applyList(array $where, int $page, int $limit): array
    {
        $query = $this->buildApplyListQuery($where);
        $total = (int) (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $list = $this->formatApplies($rows);

        return compact('total', 'list');
    }

    public function approveApply(int $applyId, int $adminId, int $levelId, string $remark = ''): void
    {
        app()->make(DistributionEnrollmentService::class)
            ->approveApply($applyId, $adminId, $levelId, $remark);
    }

    public function rejectApply(int $applyId, int $adminId, string $remark): void
    {
        app()->make(DistributionEnrollmentService::class)
            ->rejectApply($applyId, $adminId, $remark);
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function levelList(array $where, int $page, int $limit): array
    {
        $query = $this->model(DistributionLevel::class)
            ->when(($where['keyword'] ?? '') !== '', function ($q) use ($where) {
                $q->whereLike('name', '%' . trim((string) $where['keyword']) . '%');
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
        $total = (int) (clone $query)->count();
        $rows = $query->order('sort', 'asc')->order('id', 'asc')->page($page, $limit)->select()->toArray();
        $list = array_map(fn(array $row): array => $this->formatLevel($row), $rows);

        return compact('total', 'list');
    }

    public function levelInfo(int $id): array
    {
        /** @var DistributionLevel|null $row */
        $row = $this->model(DistributionLevel::class)->find($id);
        if ($row === null) {
            throw new BusinessException('分销员等级不存在');
        }
        return $this->formatLevel($row->toArray());
    }

    public function createLevel(array $data): int
    {
        $payload = $this->normalizeLevelPayload($data);
        /** @var DistributionLevel $level */
        $level = $this->model(DistributionLevel::class);
        $level->save($payload);
        return (int) $level->id;
    }

    public function updateLevel(int $id, array $data): void
    {
        /** @var DistributionLevel|null $level */
        $level = $this->model(DistributionLevel::class)->find($id);
        if ($level === null) {
            throw new BusinessException('分销员等级不存在');
        }
        $level->save($this->normalizeLevelPayload($data));
    }

    public function deleteLevel(int $id): void
    {
        if ($id === 1) {
            throw new BusinessException('默认分销员等级不能删除');
        }
        if ($this->model()->where('level_id', $id)->count() > 0) {
            throw new BusinessException('该等级下已有分销员，不能删除');
        }
        $this->model(DistributionLevel::class)->where('id', $id)->delete();
    }

    public function updateLevelStatus(int $id, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态不合法');
        }
        $this->model(DistributionLevel::class)->where('id', $id)->update(['status' => $status]);
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function ruleList(array $where, int $page, int $limit): array
    {
        $query = $this->buildRuleListQuery($where);
        $total = (int) (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $list = array_map(fn(array $row): array => $this->formatRule($row), $rows);

        return compact('total', 'list');
    }

    public function ruleInfo(int $id): array
    {
        /** @var DistributionCommissionRule|null $row */
        $row = $this->model(DistributionCommissionRule::class)->find($id);
        if ($row === null) {
            throw new BusinessException('佣金规则不存在');
        }
        return $this->formatRule($row->toArray());
    }

    public function createRule(array $data): int
    {
        $payload = $this->normalizeRulePayload($data);
        /** @var DistributionCommissionRule $rule */
        $rule = $this->model(DistributionCommissionRule::class);
        $rule->save($payload);
        return (int) $rule->id;
    }

    public function updateRule(int $id, array $data): void
    {
        /** @var DistributionCommissionRule|null $rule */
        $rule = $this->model(DistributionCommissionRule::class)->find($id);
        if ($rule === null) {
            throw new BusinessException('佣金规则不存在');
        }
        $rule->save($this->normalizeRulePayload($data, $id));
    }

    public function deleteRule(int $id): void
    {
        $this->model(DistributionCommissionRule::class)->where('id', $id)->delete();
    }

    public function updateRuleStatus(int $id, int $status): void
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态不合法');
        }
        $this->model(DistributionCommissionRule::class)->where('id', $id)->update(['status' => $status]);
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function commissionList(array $where, int $page, int $limit): array
    {
        $query = $this->buildCommissionListQuery($where);
        $total = (int) (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $list = array_map(fn(array $row): array => $this->formatCommission($row), $rows);

        return compact('total', 'list');
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function logList(array $where, int $page, int $limit): array
    {
        $query = $this->model(DistributionCommissionLog::class)
            ->when(($where['user_id'] ?? 0) > 0, function ($q) use ($where) {
                $q->where('user_id', (int) $where['user_id']);
            })
            ->when(($where['biz_type'] ?? '') !== '', function ($q) use ($where) {
                $q->where('biz_type', (string) $where['biz_type']);
            })
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
    public function withdrawList(array $where, int $page, int $limit): array
    {
        $query = $this->model(DistributionWithdraw::class)
            ->when(($where['user_id'] ?? 0) > 0, function ($q) use ($where) {
                $q->where('user_id', (int) $where['user_id']);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
        $total = (int) (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $list = array_map(fn(array $row): array => $this->formatWithdraw($row), $rows);

        return compact('total', 'list');
    }

    public function approveWithdraw(int $withdrawId, int $adminId, string $remark = ''): void
    {
        $this->reviewWithdraw($withdrawId, DistributionWithdraw::STATUS_APPROVED, $adminId, $remark);
    }

    public function rejectWithdraw(int $withdrawId, int $adminId, string $remark = ''): void
    {
        if (trim($remark) === '') {
            throw new BusinessException('请填写驳回原因');
        }
        $this->reviewWithdraw($withdrawId, DistributionWithdraw::STATUS_REJECTED, $adminId, $remark);
    }

    public function adjustCommission(int $userId, string $direction, string $amount, string $remark, int $adminId): void
    {
        $amountCents = $this->amountToCents($amount);
        app()->make(DistributionAccountService::class)->adjust($userId, $direction, $amountCents, $remark, $adminId);
    }

    private function reviewWithdraw(int $withdrawId, int $status, int $adminId, string $remark): void
    {
        if ($adminId <= 0) {
            throw new BusinessException('管理员身份无效');
        }
        /** @var DistributionAccountService $accountService */
        $accountService = app()->make(DistributionAccountService::class);

        $this->transaction(function () use ($withdrawId, $status, $adminId, $remark, $accountService): void {
            /** @var DistributionWithdraw|null $withdraw */
            $withdraw = $this->model(DistributionWithdraw::class)
                ->where('id', $withdrawId)
                ->lock(true)
                ->find();
            if ($withdraw === null) {
                throw new BusinessException('提现申请不存在');
            }
            if ((int) $withdraw->status !== DistributionWithdraw::STATUS_PENDING) {
                throw new BusinessException('提现申请已处理');
            }

            if ($status === DistributionWithdraw::STATUS_APPROVED) {
                $accountService->approveWithdraw((int) $withdraw->user_id, (int) $withdraw->amount_cents, (int) $withdraw->id, (string) $withdraw->sn, $adminId);
            } else {
                $accountService->rejectWithdraw((int) $withdraw->user_id, (int) $withdraw->amount_cents, (int) $withdraw->id, (string) $withdraw->sn, $adminId);
            }

            $withdraw->status = $status;
            $withdraw->admin_id = $adminId;
            $withdraw->admin_remark = mb_substr(trim($remark), 0, 255);
            $withdraw->reviewed_at = date('Y-m-d H:i:s');
            $withdraw->save();
        });
    }

    private function buildDistributorListQuery(array $where)
    {
        $keyword = trim((string) ($where['keyword'] ?? ''));
        $userIds = [];
        if ($keyword !== '') {
            $userIds = $this->model(User::class)
                ->whereLike('nickname|mobile|email', '%' . $keyword . '%')
                ->column('id');
            if (ctype_digit($keyword)) {
                $userIds[] = (int) $keyword;
            }
            $userIds = array_values(array_unique(array_map('intval', $userIds)));
        }

        return $this->model()
            ->when($keyword !== '', function ($q) use ($userIds) {
                $q->whereIn('user_id', $userIds !== [] ? $userIds : [0]);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            })
            ->when(($where['level_id'] ?? 0) > 0, function ($q) use ($where) {
                $q->where('level_id', (int) $where['level_id']);
            });
    }

    private function buildApplyListQuery(array $where)
    {
        $keyword = trim((string) ($where['keyword'] ?? ''));
        $userIds = [];
        if ($keyword !== '') {
            $userIds = $this->model(User::class)
                ->whereLike('nickname|mobile|email', '%' . $keyword . '%')
                ->column('id');
            if (ctype_digit($keyword)) {
                $userIds[] = (int) $keyword;
            }
            $userIds = array_values(array_unique(array_map('intval', $userIds)));
        }

        return $this->model(DistributionApply::class)
            ->when($keyword !== '', function ($q) use ($userIds, $keyword) {
                $q->where(function ($query) use ($userIds, $keyword) {
                    $query->whereIn('user_id', $userIds !== [] ? $userIds : [0])
                        ->whereOr(function ($subQuery) use ($keyword) {
                            $subQuery->whereLike('real_name|mobile', '%' . $keyword . '%');
                        });
                });
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
    }

    private function buildRuleListQuery(array $where)
    {
        return $this->model(DistributionCommissionRule::class)
            ->when(($where['target_type'] ?? '') !== '', function ($q) use ($where) {
                $q->where('target_type', (string) $where['target_type']);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
    }

    private function buildCommissionListQuery(array $where)
    {
        return $this->model(DistributionOrderCommission::class)
            ->when(($where['order_sn'] ?? '') !== '', function ($q) use ($where) {
                $q->whereLike('order_sn', '%' . trim((string) $where['order_sn']) . '%');
            })
            ->when(($where['distributor_user_id'] ?? 0) > 0, function ($q) use ($where) {
                $q->where('distributor_user_id', (int) $where['distributor_user_id']);
            })
            ->when(($where['buyer_user_id'] ?? 0) > 0, function ($q) use ($where) {
                $q->where('buyer_user_id', (int) $where['buyer_user_id']);
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
    }

    /**
     * @return array{labels:array<int,string>,amount:array<int,float>,orders:array<int,int>}
     */
    private function buildCommissionTrend(int $days): array
    {
        $labels = [];
        $amount = [];
        $orders = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            [$start, $end] = $this->dayRange($offset);
            $query = $this->model(DistributionOrderCommission::class)
                ->whereBetweenTime('create_time', $start, $end);

            $labels[] = date('m-d', strtotime($start));
            $amount[] = $this->centsToFloat((int) (clone $query)->sum('amount_cents'));
            $orders[] = (int) (clone $query)->count('DISTINCT order_id');
        }

        return compact('labels', 'amount', 'orders');
    }

    /**
     * @return array<int,array{name:string,amount:float,order_count:int,commission_count:int}>
     */
    private function buildRegionDistribution(int $limit = 10): array
    {
        $orderTable = $this->model(Order::class)->getTable();
        $rows = $this->model(DistributionOrderCommission::class)
            ->alias('c')
            ->leftJoin($orderTable . ' o', 'o.id = c.order_id')
            ->field("IF(COALESCE(o.receiver_province, '') = '', '未知地区', o.receiver_province) AS name,SUM(c.amount_cents) AS amount_cents,COUNT(DISTINCT c.order_id) AS order_count,COUNT(c.id) AS commission_count")
            ->group('name')
            ->order('amount_cents', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return array_map(fn(array $row): array => [
            'name' => (string) ($row['name'] ?? '未知地区'),
            'amount' => $this->centsToFloat((int) ($row['amount_cents'] ?? 0)),
            'order_count' => (int) ($row['order_count'] ?? 0),
            'commission_count' => (int) ($row['commission_count'] ?? 0),
        ], $rows);
    }

    /**
     * @return array<int,array{status:int,name:string,value:int,amount:float}>
     */
    private function buildStatusDistribution(): array
    {
        $rows = $this->model(DistributionOrderCommission::class)
            ->field('status,COUNT(*) AS value,SUM(amount_cents) AS amount_cents')
            ->group('status')
            ->select()
            ->toArray();

        return array_map(fn(array $row): array => [
            'status' => (int) ($row['status'] ?? 0),
            'name' => DistributionOrderCommission::statusText((int) ($row['status'] ?? 0)),
            'value' => (int) ($row['value'] ?? 0),
            'amount' => $this->centsToFloat((int) ($row['amount_cents'] ?? 0)),
        ], $rows);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function formatDistributors(array $rows): array
    {
        $userIds = array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), $rows)));
        $levelIds = array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['level_id'] ?? 0), $rows)));
        $users = $this->usersByIds($userIds);
        $levels = $this->levelsByIds($levelIds);

        return array_map(function (array $row) use ($users, $levels): array {
            $userId = (int) ($row['user_id'] ?? 0);
            $levelId = (int) ($row['level_id'] ?? 0);
            return [
                'id' => (int) ($row['id'] ?? 0),
                'user_id' => $userId,
                'user' => $users[$userId] ?? null,
                'level_id' => $levelId,
                'level_name' => (string) ($levels[$levelId]['name'] ?? ''),
                'invite_code' => (string) ($row['invite_code'] ?? ''),
                'status' => (int) ($row['status'] ?? 0),
                'status_text' => (int) ($row['status'] ?? 0) === DistributionDistributor::STATUS_ENABLED ? '启用' : '禁用',
                'open_source' => (string) ($row['open_source'] ?? ''),
                'open_source_text' => $this->openSourceText((string) ($row['open_source'] ?? '')),
                'available_commission' => $this->centsToAmount((int) ($row['available_commission_cents'] ?? 0)),
                'frozen_commission' => $this->centsToAmount((int) ($row['frozen_commission_cents'] ?? 0)),
                'pending_withdraw' => $this->centsToAmount((int) ($row['pending_withdraw_cents'] ?? 0)),
                'withdrawn_commission' => $this->centsToAmount((int) ($row['withdrawn_commission_cents'] ?? 0)),
                'debt_commission' => $this->centsToAmount((int) ($row['debt_commission_cents'] ?? 0)),
                'total_commission' => $this->centsToAmount((int) ($row['total_commission_cents'] ?? 0)),
                'direct_user_count' => (int) ($row['direct_user_count'] ?? 0),
                'indirect_user_count' => (int) ($row['indirect_user_count'] ?? 0),
                'order_count' => (int) ($row['order_count'] ?? 0),
                'opened_at' => (string) ($row['opened_at'] ?? ''),
                'remark' => (string) ($row['remark'] ?? ''),
                'create_time' => (string) ($row['create_time'] ?? ''),
                'update_time' => (string) ($row['update_time'] ?? ''),
            ];
        }, $rows);
    }

    private function userRelationSummary(int $userId): ?array
    {
        /** @var DistributionRelation|null $row */
        $row = $this->model(DistributionRelation::class)
            ->where('user_id', $userId)
            ->find();
        if ($row === null) {
            return null;
        }

        $parentUserId = (int) $row->parent_user_id;
        $grandparentUserId = (int) $row->grandparent_user_id;
        $users = $this->usersByIds([$parentUserId, $grandparentUserId]);
        $expireTime = $row->expire_time === null ? '' : (string) $row->expire_time;

        return [
            'id' => (int) $row->id,
            'parent_user_id' => $parentUserId,
            'parent_user' => $users[$parentUserId] ?? null,
            'grandparent_user_id' => $grandparentUserId,
            'grandparent_user' => $users[$grandparentUserId] ?? null,
            'invite_code' => (string) $row->invite_code,
            'source' => (string) $row->source,
            'source_text' => $this->relationSourceText((string) $row->source),
            'expire_time' => $expireTime,
            'is_valid' => $expireTime === '' || strtotime($expireTime) >= time(),
            'attribution_scene' => (string) $row->attribution_scene,
            'attribution_page' => (string) $row->attribution_page,
            'attribution_target_type' => (string) $row->attribution_target_type,
            'attribution_target_id' => (int) $row->attribution_target_id,
            'invite_reward_status' => (int) $row->invite_reward_status,
            'invite_reward_amount' => $this->centsToAmount((int) $row->invite_reward_cents),
            'invite_reward_at' => (string) ($row->invite_reward_at ?? ''),
            'create_time' => (string) $row->create_time,
        ];
    }

    private function openSourceText(string $source): string
    {
        return match ($source) {
            'admin' => '后台开通',
            'amount' => '消费达标',
            'apply' => '申请审核',
            'everyone' => '自动开通',
            'manual' => '手动开通',
            default => $source !== '' ? $source : '-',
        };
    }

    private function relationSourceText(string $source): string
    {
        return match ($source) {
            'client' => '客户端绑定',
            'invite' => '邀请链接',
            'manual' => '手动绑定',
            default => $source !== '' ? $source : '-',
        };
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function formatApplies(array $rows): array
    {
        $userIds = array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), $rows)));
        $users = $this->usersByIds($userIds);
        /** @var AssetIdNormalizer $normalizer */
        $normalizer = app()->make(AssetIdNormalizer::class);
        $proofImages = array_map(static fn(array $row): mixed => $row['proof_image'] ?? '', $rows);
        $assetMap = app()->make(AssetResolver::class)->resolve($normalizer->collectAssetIds($proofImages));
        /** @var AssetHydrator $hydrator */
        $hydrator = app()->make(AssetHydrator::class);

        return array_map(static function (array $row) use ($users, $assetMap, $hydrator): array {
            $userId = (int) ($row['user_id'] ?? 0);
            $status = (int) ($row['status'] ?? 0);
            $proofImage = (string) ($row['proof_image'] ?? '');
            return [
                'id' => (int) ($row['id'] ?? 0),
                'user_id' => $userId,
                'user' => $users[$userId] ?? null,
                'real_name' => (string) ($row['real_name'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
                'proof_image' => $proofImage,
                'proof_image_full_url' => $hydrator->fullUrl($proofImage, $assetMap),
                'status' => $status,
                'status_text' => DistributionApply::statusText($status),
                'review_admin_id' => isset($row['review_admin_id']) ? (int) $row['review_admin_id'] : null,
                'review_remark' => (string) ($row['review_remark'] ?? ''),
                'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
                'create_time' => (string) ($row['create_time'] ?? ''),
                'update_time' => (string) ($row['update_time'] ?? ''),
            ];
        }, $rows);
    }

    private function formatLevel(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'first_rate' => number_format((float) ($row['first_rate'] ?? 0), 2, '.', ''),
            'second_rate' => number_format((float) ($row['second_rate'] ?? 0), 2, '.', ''),
            'sort' => (int) ($row['sort'] ?? 0),
            'status' => (int) ($row['status'] ?? 0),
            'remark' => (string) ($row['remark'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
            'update_time' => (string) ($row['update_time'] ?? ''),
        ];
    }

    private function formatRule(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'target_type' => (string) ($row['target_type'] ?? ''),
            'target_type_text' => DistributionCommissionRule::targetText((string) ($row['target_type'] ?? '')),
            'target_id' => (int) ($row['target_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'commission_type' => (string) ($row['commission_type'] ?? DistributionCommissionRule::COMMISSION_TYPE_RATE),
            'commission_type_text' => DistributionCommissionRule::commissionTypeText((string) ($row['commission_type'] ?? DistributionCommissionRule::COMMISSION_TYPE_RATE)),
            'first_rate' => number_format((float) ($row['first_rate'] ?? 0), 2, '.', ''),
            'second_rate' => number_format((float) ($row['second_rate'] ?? 0), 2, '.', ''),
            'first_fixed_amount' => $this->centsToAmount((int) ($row['first_fixed_cents'] ?? 0)),
            'second_fixed_amount' => $this->centsToAmount((int) ($row['second_fixed_cents'] ?? 0)),
            'status' => (int) ($row['status'] ?? 0),
            'remark' => (string) ($row['remark'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
            'update_time' => (string) ($row['update_time'] ?? ''),
        ];
    }

    private function formatCommission(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'order_id' => (int) ($row['order_id'] ?? 0),
            'order_sn' => (string) ($row['order_sn'] ?? ''),
            'order_item_id' => (int) ($row['order_item_id'] ?? 0),
            'buyer_user_id' => (int) ($row['buyer_user_id'] ?? 0),
            'distributor_user_id' => (int) ($row['distributor_user_id'] ?? 0),
            'relation_id' => (int) ($row['relation_id'] ?? 0),
            'relation_level' => (int) ($row['relation_level'] ?? 0),
            'goods_id' => (int) ($row['goods_id'] ?? 0),
            'sku_id' => (int) ($row['sku_id'] ?? 0),
            'base_amount' => $this->centsToAmount((int) ($row['base_amount_cents'] ?? 0)),
            'rate' => number_format((float) ($row['rate'] ?? 0), 2, '.', ''),
            'amount' => $this->centsToAmount((int) ($row['amount_cents'] ?? 0)),
            'recovered_amount' => $this->centsToAmount((int) ($row['recovered_cents'] ?? 0)),
            'rule_type' => (string) ($row['rule_type'] ?? ''),
            'rule_id' => (int) ($row['rule_id'] ?? 0),
            'attribution_scene' => (string) ($row['attribution_scene'] ?? ''),
            'attribution_target_type' => (string) ($row['attribution_target_type'] ?? ''),
            'attribution_target_id' => (int) ($row['attribution_target_id'] ?? 0),
            'status' => (int) ($row['status'] ?? 0),
            'status_text' => DistributionOrderCommission::statusText((int) ($row['status'] ?? 0)),
            'release_time' => (string) ($row['release_time'] ?? ''),
            'settled_at' => (string) ($row['settled_at'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
        ];
    }

    private function formatLog(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'commission_id' => isset($row['commission_id']) ? (int) $row['commission_id'] : null,
            'withdraw_id' => isset($row['withdraw_id']) ? (int) $row['withdraw_id'] : null,
            'biz_type' => (string) ($row['biz_type'] ?? ''),
            'biz_type_text' => DistributionCommissionLog::bizTypeText((string) ($row['biz_type'] ?? '')),
            'biz_id' => (string) ($row['biz_id'] ?? ''),
            'account_type' => (string) ($row['account_type'] ?? ''),
            'direction' => (string) ($row['direction'] ?? ''),
            'change_amount' => $this->centsToAmount((int) ($row['change_cents'] ?? 0)),
            'before_amount' => $this->centsToAmount((int) ($row['before_cents'] ?? 0)),
            'after_amount' => $this->centsToAmount((int) ($row['after_cents'] ?? 0)),
            'operator_type' => (int) ($row['operator_type'] ?? 0),
            'operator_id' => isset($row['operator_id']) ? (int) $row['operator_id'] : null,
            'remark' => (string) ($row['remark'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
        ];
    }

    private function formatWithdraw(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'sn' => (string) ($row['sn'] ?? ''),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'amount' => $this->centsToAmount((int) ($row['amount_cents'] ?? 0)),
            'account_type' => (string) ($row['account_type'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'account_no' => (string) ($row['account_no'] ?? ''),
            'status' => (int) ($row['status'] ?? 0),
            'status_text' => DistributionWithdraw::statusText((int) ($row['status'] ?? 0)),
            'admin_id' => isset($row['admin_id']) ? (int) $row['admin_id'] : null,
            'admin_remark' => (string) ($row['admin_remark'] ?? ''),
            'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
        ];
    }

    private function normalizeLevelPayload(array $data): array
    {
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 60);
        if ($name === '') {
            throw new BusinessException('等级名称不能为空');
        }
        $firstRate = app()->make(DistributionConfigService::class)->normalizeRate((string) ($data['first_rate'] ?? '0'));
        $secondRate = app()->make(DistributionConfigService::class)->normalizeRate((string) ($data['second_rate'] ?? '0'));
        app()->make(DistributionConfigService::class)->assertRatePair($firstRate, $secondRate);

        return [
            'name' => $name,
            'first_rate' => $firstRate,
            'second_rate' => $secondRate,
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? 1),
            'remark' => mb_substr(trim((string) ($data['remark'] ?? '')), 0, 255),
        ];
    }

    private function normalizeRulePayload(array $data, int $ignoreId = 0): array
    {
        $targetType = (string) ($data['target_type'] ?? '');
        if (!in_array($targetType, [DistributionCommissionRule::TARGET_CATEGORY, DistributionCommissionRule::TARGET_GOODS, DistributionCommissionRule::TARGET_SKU], true)) {
            throw new BusinessException('规则对象不合法');
        }
        $targetId = (int) ($data['target_id'] ?? 0);
        if ($targetId <= 0) {
            throw new BusinessException('规则对象ID不能为空');
        }
        $exists = $this->model(DistributionCommissionRule::class)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->when($ignoreId > 0, function ($q) use ($ignoreId) {
                $q->where('id', '<>', $ignoreId);
            })
            ->count();
        if ($exists > 0) {
            throw new BusinessException('该对象已配置佣金规则');
        }

        $config = app()->make(DistributionConfigService::class);
        $commissionType = (string) ($data['commission_type'] ?? DistributionCommissionRule::COMMISSION_TYPE_RATE);
        if (!in_array($commissionType, [DistributionCommissionRule::COMMISSION_TYPE_RATE, DistributionCommissionRule::COMMISSION_TYPE_FIXED], true)) {
            throw new BusinessException('计佣方式不合法');
        }
        $firstRate = $config->normalizeRate((string) ($data['first_rate'] ?? '0'));
        $secondRate = $config->normalizeRate((string) ($data['second_rate'] ?? '0'));
        $config->assertRatePair($firstRate, $secondRate);
        $firstFixedCents = $this->amountToCents((string) ($data['first_fixed_amount'] ?? '0'));
        $secondFixedCents = $this->amountToCents((string) ($data['second_fixed_amount'] ?? '0'));
        if ($commissionType === DistributionCommissionRule::COMMISSION_TYPE_FIXED && $firstFixedCents <= 0 && $secondFixedCents <= 0) {
            throw new BusinessException('固定金额规则至少填写一个佣金金额');
        }

        return [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'name' => mb_substr(trim((string) ($data['name'] ?? '')), 0, 100),
            'commission_type' => $commissionType,
            'first_rate' => $firstRate,
            'second_rate' => $secondRate,
            'first_fixed_cents' => $firstFixedCents,
            'second_fixed_cents' => $secondFixedCents,
            'status' => (int) ($data['status'] ?? 1),
            'remark' => mb_substr(trim((string) ($data['remark'] ?? '')), 0, 255),
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
            ->field('id,nickname,mobile,email,avatar')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'nickname' => (string) ($row['nickname'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'avatar' => $row['avatar'] ?? null,
            ];
        }
        return $map;
    }

    /**
     * @param array<int> $ids
     * @return array<int,array<string,mixed>>
     */
    private function levelsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $rows = $this->model(DistributionLevel::class)
            ->whereIn('id', $ids)
            ->field('id,name')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }
        return $map;
    }

    private function centsToAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function centsToFloat(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function dayRange(int $offset): array
    {
        $date = strtotime("-{$offset} days");
        return [
            date('Y-m-d 00:00:00', $date),
            date('Y-m-d 23:59:59', $date),
        ];
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
}
