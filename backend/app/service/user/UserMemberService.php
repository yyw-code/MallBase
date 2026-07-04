<?php
declare(strict_types=1);

namespace app\service\user;

use app\common\enum\OperatorType;
use app\model\order\Order;
use app\model\user\MemberLevel;
use app\model\user\User;
use app\model\user\UserMember;
use app\model\user\UserMemberGrowthLog;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 用户会员账户服务
 *
 * @extends BaseService<UserMember>
 */
class UserMemberService extends BaseService
{
    private const BENEFIT_MODE_GLOBAL = 'global';
    private const BENEFIT_MODE_DISABLED = 'disabled';
    private const BENEFIT_MODE_LEVEL_DISCOUNT = 'level_discount';
    private const BENEFIT_MODE_SKU_PRICE = 'sku_price';
    private const LEVEL_SOURCE_AUTO = 'auto';
    private const LEVEL_SOURCE_MANUAL = 'manual';

    protected string $modelClass = UserMember::class;

    /**
     * @return array{
     *   enabled:bool,level_enabled:bool,price_enabled:bool,discount_amount:string,
     *   item_discounts:array<int,string>,level:array{id:int,name:string,growth_min:int,discount_percent:string}|null
     * }
     */
    public function pricingQuote(int $userId, array $items): array
    {
        $itemCount = count($items);
        $empty = [
            'enabled' => false,
            'level_enabled' => false,
            'price_enabled' => false,
            'discount_amount' => '0.00',
            'item_discounts' => array_fill(0, $itemCount, '0.00'),
            'level' => null,
        ];

        if ($userId <= 0 || !$this->isMemberEnabled() || $items === []) {
            return $empty;
        }

        $levelEnabled = $this->isLevelEnabled();
        $priceEnabled = $this->isMemberPriceEnabled();
        $member = $this->ensureMember($userId);
        $level = $levelEnabled ? $this->accountLevel($member) : null;
        $levelDiscountBasis = $level !== null ? $this->discountBasis((string) $level['discount_percent']) : 10000;

        $totalDiscountCents = 0;
        $itemDiscounts = [];
        foreach ($items as $index => $item) {
            $subtotalCents = $this->decimalToCents((string) ($item['unit_price'] ?? '0.00')) * max(0, (int) ($item['quantity'] ?? 0));
            $discountCents = 0;
            $mode = $this->normalizeBenefitMode((string) ($item['member_benefit_mode'] ?? self::BENEFIT_MODE_GLOBAL));

            if ($mode === self::BENEFIT_MODE_SKU_PRICE && $priceEnabled) {
                $memberUnitCents = $this->decimalToCents((string) ($item['member_price'] ?? '0.00'));
                if ($memberUnitCents > 0) {
                    $memberSubtotalCents = $memberUnitCents * max(0, (int) ($item['quantity'] ?? 0));
                    $discountCents = max(0, $subtotalCents - $memberSubtotalCents);
                }
            } elseif (in_array($mode, [self::BENEFIT_MODE_GLOBAL, self::BENEFIT_MODE_LEVEL_DISCOUNT], true)
                && $levelEnabled
                && $levelDiscountBasis < 10000
            ) {
                $memberSubtotalCents = intdiv($subtotalCents * $levelDiscountBasis + 5000, 10000);
                $discountCents = max(0, $subtotalCents - $memberSubtotalCents);
            }

            $itemDiscounts[$index] = $this->centsToDecimal($discountCents);
            $totalDiscountCents += $discountCents;
        }

        return [
            'enabled' => true,
            'level_enabled' => $levelEnabled,
            'price_enabled' => $priceEnabled,
            'discount_amount' => $this->centsToDecimal($totalDiscountCents),
            'item_discounts' => $itemDiscounts,
            'level' => $level,
        ];
    }

    public function rewardOrderCompleted(Order $order): void
    {
        if (!$this->isGrowthEnabled()) {
            return;
        }

        $userId = (int) ($order->user_id ?? 0);
        $orderSn = (string) ($order->sn ?? '');
        if ($userId <= 0 || $orderSn === '') {
            return;
        }
        if ($this->model(UserMemberGrowthLog::class)
            ->where('biz_type', UserMemberGrowthLog::BIZ_ORDER_COMPLETE)
            ->where('biz_id', $orderSn)
            ->count() > 0
        ) {
            return;
        }

        $growth = $this->growthForOrder($order);
        if ($growth <= 0) {
            return;
        }

        $this->transaction(function () use ($userId, $orderSn, $growth): void {
            if ($this->model(UserMemberGrowthLog::class)
                ->where('biz_type', UserMemberGrowthLog::BIZ_ORDER_COMPLETE)
                ->where('biz_id', $orderSn)
                ->lock(true)
                ->count() > 0
            ) {
                return;
            }

            $member = $this->lockedMember($userId);
            $beforeGrowth = (int) $member->growth_value;
            $beforeLevelId = (int) $member->level_id;
            $afterGrowth = $beforeGrowth + $growth;
            $currentLevel = $this->accountLevel($member);
            $matchedLevel = $this->isLevelEnabled() ? $this->matchLevel($afterGrowth) : null;
            $afterLevel = $this->shouldAutoUpgrade($member, $currentLevel, $matchedLevel)
                ? $matchedLevel
                : $currentLevel;
            $afterLevelId = (int) ($afterLevel['id'] ?? $beforeLevelId);

            $member->growth_value = $afterGrowth;
            $member->total_growth_value = (int) $member->total_growth_value + $growth;
            if ($afterLevel !== null) {
                $member->level_id = $afterLevelId;
                $member->level_name = (string) $afterLevel['name'];
                if ((int) $afterLevel['id'] !== $beforeLevelId) {
                    $member->level_source = self::LEVEL_SOURCE_AUTO;
                    $member->level_lock_until = null;
                }
            }
            $member->save();

            $this->model(UserMemberGrowthLog::class)->save([
                'user_id' => $userId,
                'biz_type' => UserMemberGrowthLog::BIZ_ORDER_COMPLETE,
                'biz_id' => $orderSn,
                'direction' => UserMemberGrowthLog::DIRECTION_INCOME,
                'change_growth' => $growth,
                'before_growth' => $beforeGrowth,
                'after_growth' => $afterGrowth,
                'before_level_id' => $beforeLevelId,
                'after_level_id' => $afterLevelId,
                'operator_type' => OperatorType::SYSTEM,
                'operator_id' => null,
                'remark' => '订单完成累计成长值',
            ]);
        });
    }

    /**
     * @return array{id:int,name:string,growth_min:int,discount_percent:string}|null
     */
    public function currentLevelOfUser(int $userId): ?array
    {
        if ($userId <= 0 || !$this->isMemberEnabled() || !$this->isLevelEnabled()) {
            return null;
        }
        $member = $this->ensureMember($userId);

        return $this->accountLevel($member);
    }

    /**
     * @return array{
     *   enabled:bool,level_enabled:bool,price_enabled:bool,growth_enabled:bool,
     *   account:array<string,mixed>|null,level:array{id:int,name:string,growth_min:int,discount_percent:string}|null,
     *   next_level:array{id:int,name:string,growth_min:int,discount_percent:string}|null,
     *   growth_value:int,total_growth_value:int,growth_to_next:int,progress_percent:int,
     *   discount_percent:string,discount_text:string,level_locked:bool
     * }
     */
    public function clientSummary(int $userId): array
    {
        $empty = [
            'enabled' => false,
            'level_enabled' => false,
            'price_enabled' => false,
            'growth_enabled' => false,
            'account' => null,
            'level' => null,
            'next_level' => null,
            'growth_value' => 0,
            'total_growth_value' => 0,
            'growth_to_next' => 0,
            'progress_percent' => 0,
            'discount_percent' => '100.00',
            'discount_text' => '',
            'level_locked' => false,
        ];
        if ($userId <= 0 || !$this->isMemberEnabled()) {
            return $empty;
        }

        $member = $this->ensureMember($userId);
        $account = $this->formatMemberAccount($member->toArray());
        $level = $this->isLevelEnabled() ? $this->accountLevel($member) : null;
        $nextLevel = $level !== null
            ? $this->nextLevelAfterGrowthMin((int) $level['growth_min'])
            : $this->nextLevelAfterGrowthMin(-1);
        $growthValue = (int) $account['growth_value'];
        $levelGrowthMin = (int) ($level['growth_min'] ?? 0);
        $nextGrowthMin = (int) ($nextLevel['growth_min'] ?? 0);
        $growthToNext = $nextLevel !== null ? max(0, $nextGrowthMin - $growthValue) : 0;
        $progressPercent = $this->growthProgressPercent($growthValue, $levelGrowthMin, $nextGrowthMin);
        $discountPercent = (string) ($level['discount_percent'] ?? '100.00');

        return [
            'enabled' => true,
            'level_enabled' => $this->isLevelEnabled(),
            'price_enabled' => $this->isMemberPriceEnabled(),
            'growth_enabled' => $this->isGrowthEnabled(),
            'account' => $account,
            'level' => $level,
            'next_level' => $nextLevel,
            'growth_value' => $growthValue,
            'total_growth_value' => (int) $account['total_growth_value'],
            'growth_to_next' => $growthToNext,
            'progress_percent' => $progressPercent,
            'discount_percent' => $discountPercent,
            'discount_text' => $this->discountText($discountPercent),
            'level_locked' => $this->isLevelLocked($member),
        ];
    }

    /**
     * @return array{
     *   level_id:int,level_name:string,level_source:string,level_lock_until:?string,
     *   level_remark:string,growth_value:int,total_growth_value:int
     * }
     */
    public function adminSetLevel(
        int $userId,
        int $levelId,
        bool $locked,
        ?string $lockUntil,
        string $remark,
        int $adminId
    ): array {
        /** @var User|null $user */
        $user = $this->model(User::class)->where('id', $userId)->whereNull('delete_time')->find();
        if ($user === null) {
            throw new BusinessException('用户不存在');
        }

        /** @var MemberLevel|null $level */
        $level = $this->model(MemberLevel::class)
            ->where('id', $levelId)
            ->where('status', 1)
            ->find();
        if ($level === null) {
            throw new BusinessException('会员等级不存在或已禁用');
        }

        $remark = mb_substr(trim($remark), 0, 255);
        if ($remark === '') {
            throw new BusinessException('请填写调整原因');
        }

        $normalizedLockUntil = $locked ? $this->normalizeLockUntil($lockUntil) : null;

        return $this->transaction(function () use ($userId, $level, $locked, $normalizedLockUntil, $remark, $adminId): array {
            $member = $this->lockedMember($userId);
            $beforeGrowth = (int) $member->growth_value;
            $beforeLevelId = (int) $member->level_id;

            $member->level_id = (int) $level->id;
            $member->level_name = (string) $level->name;
            $member->level_source = $locked ? self::LEVEL_SOURCE_MANUAL : self::LEVEL_SOURCE_AUTO;
            $member->level_lock_until = $normalizedLockUntil;
            $member->level_remark = $remark;
            $member->save();

            $this->model(UserMemberGrowthLog::class)->save([
                'user_id' => $userId,
                'biz_type' => UserMemberGrowthLog::BIZ_ADMIN_ADJUST,
                'biz_id' => $this->memberLevelAdjustBizId($userId),
                'direction' => UserMemberGrowthLog::DIRECTION_INCOME,
                'change_growth' => 0,
                'before_growth' => $beforeGrowth,
                'after_growth' => $beforeGrowth,
                'before_level_id' => $beforeLevelId,
                'after_level_id' => (int) $level->id,
                'operator_type' => OperatorType::ADMIN,
                'operator_id' => $adminId > 0 ? $adminId : null,
                'remark' => mb_substr('后台设置会员等级：' . (string) $level->name . '；' . $remark, 0, 255),
            ]);

            return $this->formatMemberAccount($member->toArray());
        });
    }

    public function isMemberEnabled(): bool
    {
        return $this->settingBool('member_enabled', false);
    }

    public function isGrowthEnabled(): bool
    {
        return $this->isMemberEnabled();
    }

    public function isLevelEnabled(): bool
    {
        return $this->isMemberEnabled();
    }

    public function isMemberPriceEnabled(): bool
    {
        return $this->isMemberEnabled();
    }

    private function ensureMember(int $userId): UserMember
    {
        /** @var UserMember|null $member */
        $member = $this->model()->where('user_id', $userId)->find();
        if ($member !== null) {
            return $member;
        }

        $level = $this->matchLevel(0);
        /** @var UserMember $created */
        $created = $this->model();
        try {
            $created->save([
                'user_id' => $userId,
                'growth_value' => 0,
                'total_growth_value' => 0,
                'level_id' => (int) ($level['id'] ?? 0),
                'level_name' => (string) ($level['name'] ?? ''),
                'level_source' => self::LEVEL_SOURCE_AUTO,
                'level_lock_until' => null,
                'level_remark' => null,
            ]);
        } catch (\Throwable) {
            /** @var UserMember|null $concurrent */
            $concurrent = $this->model()->where('user_id', $userId)->find();
            if ($concurrent !== null) {
                return $concurrent;
            }
            throw new BusinessException('会员账户初始化失败');
        }

        return $created;
    }

    private function lockedMember(int $userId): UserMember
    {
        $this->ensureMember($userId);
        /** @var UserMember|null $member */
        $member = $this->model()->where('user_id', $userId)->lock(true)->find();
        if ($member === null) {
            throw new BusinessException('会员账户不存在');
        }

        return $member;
    }

    /**
     * @return array{id:int,name:string,growth_min:int,discount_percent:string}|null
     */
    private function accountLevel(UserMember $member): ?array
    {
        $currentLevel = $this->levelById((int) $member->level_id);
        if ($currentLevel !== null) {
            return $currentLevel;
        }

        return $this->matchLevel((int) $member->growth_value);
    }

    /**
     * @return array{id:int,name:string,growth_min:int,discount_percent:string}|null
     */
    private function matchLevel(int $growthValue): ?array
    {
        /** @var MemberLevel|null $level */
        $level = $this->model(MemberLevel::class)
            ->where('status', 1)
            ->where('growth_min', '<=', max(0, $growthValue))
            ->order('growth_min', 'desc')
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->find();

        return $level !== null ? $this->formatLevel($level->toArray()) : null;
    }

    /**
     * @return array{id:int,name:string,growth_min:int,discount_percent:string}|null
     */
    private function levelById(int $levelId): ?array
    {
        if ($levelId <= 0) {
            return null;
        }

        /** @var MemberLevel|null $level */
        $level = $this->model(MemberLevel::class)->where('id', $levelId)->find();

        return $level !== null ? $this->formatLevel($level->toArray()) : null;
    }

    /**
     * @return array{id:int,name:string,growth_min:int,discount_percent:string}|null
     */
    private function nextLevelAfterGrowthMin(int $growthMin): ?array
    {
        /** @var MemberLevel|null $level */
        $level = $this->model(MemberLevel::class)
            ->where('status', 1)
            ->where('growth_min', '>', max(-1, $growthMin))
            ->order('growth_min', 'asc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->find();

        return $level !== null ? $this->formatLevel($level->toArray()) : null;
    }

    /**
     * @param array{id:int,name:string,growth_min:int,discount_percent:string}|null $currentLevel
     * @param array{id:int,name:string,growth_min:int,discount_percent:string}|null $matchedLevel
     */
    private function shouldAutoUpgrade(UserMember $member, ?array $currentLevel, ?array $matchedLevel): bool
    {
        if ($matchedLevel === null || $this->isLevelLocked($member)) {
            return false;
        }
        if ($currentLevel === null) {
            return true;
        }

        return (int) $matchedLevel['growth_min'] > (int) $currentLevel['growth_min'];
    }

    private function isLevelLocked(UserMember $member): bool
    {
        if ((string) ($member->level_source ?? self::LEVEL_SOURCE_AUTO) !== self::LEVEL_SOURCE_MANUAL) {
            return false;
        }

        $lockUntil = trim((string) ($member->level_lock_until ?? ''));
        if ($lockUntil === '') {
            return true;
        }

        $timestamp = strtotime($lockUntil);
        return $timestamp === false || $timestamp >= time();
    }

    private function normalizeLockUntil(?string $lockUntil): ?string
    {
        $lockUntil = trim((string) $lockUntil);
        if ($lockUntil === '') {
            return null;
        }

        $timestamp = strtotime($lockUntil);
        if ($timestamp === false) {
            throw new BusinessException('锁定到期时间格式不正确');
        }
        if ($timestamp <= time()) {
            throw new BusinessException('锁定到期时间不能早于当前时间');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function memberLevelAdjustBizId(int $userId): string
    {
        return 'member_level:' . $userId . ':' . date('YmdHis') . ':' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,name:string,growth_min:int,discount_percent:string}
     */
    private function formatLevel(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'growth_min' => (int) ($row['growth_min'] ?? 0),
            'discount_percent' => number_format((float) ($row['discount_percent'] ?? 100), 2, '.', ''),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   level_id:int,level_name:string,level_source:string,level_lock_until:?string,
     *   level_remark:string,growth_value:int,total_growth_value:int
     * }
     */
    private function formatMemberAccount(array $row): array
    {
        $lockUntil = (string) ($row['level_lock_until'] ?? '');

        return [
            'growth_value' => (int) ($row['growth_value'] ?? 0),
            'total_growth_value' => (int) ($row['total_growth_value'] ?? 0),
            'level_id' => (int) ($row['level_id'] ?? 0),
            'level_name' => (string) ($row['level_name'] ?? ''),
            'level_source' => (string) ($row['level_source'] ?? self::LEVEL_SOURCE_AUTO),
            'level_lock_until' => $lockUntil !== '' ? $lockUntil : null,
            'level_remark' => (string) ($row['level_remark'] ?? ''),
        ];
    }

    private function growthForOrder(Order $order): int
    {
        $basisCents = max(
            0,
            $this->decimalToCents((string) ($order->total_amount ?? '0.00'))
            - $this->decimalToCents((string) ($order->discount_amount ?? '0.00'))
        );
        $payCents = $this->decimalToCents((string) ($order->pay_amount ?? '0.00'));
        $basisCents = min($basisCents, $payCents);
        if ($basisCents <= 0) {
            return 0;
        }

        $rateBasis = $this->decimalPercentBasis((string) getSystemSetting('member_growth_points_per_yuan', '1'));
        if ($rateBasis <= 0) {
            return 0;
        }

        return intdiv($basisCents * $rateBasis, 10000);
    }

    private function normalizeBenefitMode(string $mode): string
    {
        return in_array($mode, [
            self::BENEFIT_MODE_GLOBAL,
            self::BENEFIT_MODE_DISABLED,
            self::BENEFIT_MODE_LEVEL_DISCOUNT,
            self::BENEFIT_MODE_SKU_PRICE,
        ], true) ? $mode : self::BENEFIT_MODE_GLOBAL;
    }

    private function discountBasis(string $discountPercent): int
    {
        return max(0, min(10000, $this->decimalPercentBasis($discountPercent)));
    }

    private function growthProgressPercent(int $growthValue, int $levelGrowthMin, int $nextGrowthMin): int
    {
        if ($nextGrowthMin <= 0) {
            return 100;
        }
        $range = max(1, $nextGrowthMin - max(0, $levelGrowthMin));
        $current = max(0, $growthValue - max(0, $levelGrowthMin));

        return max(0, min(100, (int) floor($current * 100 / $range)));
    }

    private function discountText(string $discountPercent): string
    {
        $percent = (float) $discountPercent;
        if ($percent <= 0 || $percent >= 100) {
            return '';
        }

        $fold = rtrim(rtrim(number_format($percent / 10, 2, '.', ''), '0'), '.');
        return $fold . '折';
    }

    private function decimalPercentBasis(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return 0;
        }

        return (int) round(((float) $value) * 100);
    }

    private function settingBool(string $code, bool $default): bool
    {
        if (!function_exists('getSystemSetting')) {
            return $default;
        }

        $value = getSystemSetting($code, $default ? '1' : '0');

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '' || !is_numeric($amount)) {
            return 0;
        }

        return max(0, (int) round(((float) $amount) * 100));
    }

    private function centsToDecimal(int $amountCents): string
    {
        $amountCents = max(0, $amountCents);

        return sprintf('%d.%02d', intdiv($amountCents, 100), $amountCents % 100);
    }
}
