<script setup>
import { useDecorateStore } from "@/store/decorate";
import { computed, ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { getPointsInfo } from "@/api/points/points";
import { isPointsEnabled, leavePointsPage } from "@/utils/points-feature";

const decorateStore = useDecorateStore();

const loading = ref(false);
const points = ref({
  balance_points: 0,
  frozen_points: 0,
  debt_points: 0,
  total_income_points: 0,
  total_expense_points: 0,
  month_income_points: 0,
  month_expense_points: 0,
  next_release_time: "",
});
const pointsEnabled = ref(true);

const balanceText = computed(() => Number(points.value.balance_points || 0));
const frozenText = computed(() => Number(points.value.frozen_points || 0));
const debtText = computed(() => Number(points.value.debt_points || 0));
const totalIncomeText = computed(() =>
  Number(points.value.total_income_points || 0),
);
const totalExpenseText = computed(() =>
  Number(points.value.total_expense_points || 0),
);
const monthIncomeText = computed(() =>
  Number(points.value.month_income_points || 0),
);
const monthExpenseText = computed(() =>
  Number(points.value.month_expense_points || 0),
);
const nextReleaseTime = computed(() => points.value.next_release_time || "");

const useActions = [
  { label: "积分商城", primary: true, tap: goMall },
  { label: "兑换记录", tap: goExchangeOrders },
];

onShow(async () => {
  if (await ensurePointsEnabled()) {
    fetchPoints();
  }
});

async function ensurePointsEnabled() {
  pointsEnabled.value = await isPointsEnabled();
  if (!pointsEnabled.value) {
    leavePointsPage();
    return false;
  }
  return true;
}

async function fetchPoints() {
  loading.value = true;
  try {
    const data = await getPointsInfo();
    points.value = {
      ...points.value,
      ...(data || {}),
    };
  } catch {
    points.value = {
      balance_points: 0,
      frozen_points: 0,
      debt_points: 0,
      total_income_points: 0,
      total_expense_points: 0,
      month_income_points: 0,
      month_expense_points: 0,
      next_release_time: "",
    };
  } finally {
    loading.value = false;
  }
}

function goRecords(params = {}) {
  const query = Object.entries(params)
    .filter(([, value]) => value !== undefined && value !== "")
    .map(([key, value]) => `${key}=${encodeURIComponent(value)}`)
    .join("&");
  uni.navigateTo({
    url: `/pages-sub/points/records${query ? `?${query}` : ""}`,
  });
}

function goFrozenRecords() {
  goRecords({ biz_type: "order_complete", range: "custom" });
}

function goIncomeRecords(range = "custom") {
  goRecords({ type: "income", range });
}

function goExpenseRecords(range = "custom") {
  goRecords({ type: "expense", range });
}

function goMall() {
  uni.navigateTo({ url: "/pages-sub/points/mall" });
}

function goExchangeOrders() {
  uni.navigateTo({ url: "/pages-sub/points/exchange-orders" });
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="我的积分" bg-color="var(--color-bg, #ffffff)" />

    <view class="points-card">
      <view class="points-card__top">
        <text class="points-card__label">可用积分</text>
        <text class="points-card__status">{{
          loading ? "同步中" : "账户正常"
        }}</text>
      </view>
      <view class="points-card__amount">
        <text class="points-card__value">{{ balanceText }}</text>
        <text class="points-card__unit">积分</text>
      </view>
      <view class="points-card__summary">
        <view
          class="points-summary points-summary--link"
          hover-class="points-summary--active"
          @tap="goFrozenRecords"
        >
          <view class="points-summary__head">
            <text class="points-summary__label">冻结积分</text>
            <view class="points-summary__arrow" />
          </view>
          <text class="points-summary__value">{{ frozenText }}</text>
        </view>
        <view class="points-summary">
          <view class="points-summary__head">
            <text class="points-summary__label">欠账积分</text>
          </view>
          <text class="points-summary__value">{{ debtText }}</text>
        </view>
        <view
          class="points-summary points-summary--link"
          hover-class="points-summary--active"
          @tap="goIncomeRecords('custom')"
        >
          <view class="points-summary__head">
            <text class="points-summary__label">累计获得</text>
            <view class="points-summary__arrow" />
          </view>
          <text class="points-summary__value">{{ totalIncomeText }}</text>
        </view>
        <view
          class="points-summary points-summary--link"
          hover-class="points-summary--active"
          @tap="goExpenseRecords('custom')"
        >
          <view class="points-summary__head">
            <text class="points-summary__label">累计使用</text>
            <view class="points-summary__arrow" />
          </view>
          <text class="points-summary__value">{{ totalExpenseText }}</text>
        </view>
      </view>
      <view class="points-card__stats">
        <view
          class="points-stat points-stat--link"
          hover-class="points-stat--active"
          @tap="goIncomeRecords('month')"
        >
          <view class="points-stat__head">
            <text class="points-stat__label">本月获得</text>
            <view class="points-stat__arrow" />
          </view>
          <text class="points-stat__value">+{{ monthIncomeText }}</text>
        </view>
        <view
          class="points-stat points-stat--link"
          hover-class="points-stat--active"
          @tap="goExpenseRecords('month')"
        >
          <view class="points-stat__head">
            <text class="points-stat__label">本月使用</text>
            <view class="points-stat__arrow" />
          </view>
          <text class="points-stat__value">-{{ monthExpenseText }}</text>
        </view>
      </view>
      <text v-if="nextReleaseTime" class="points-card__hint">
        最近解冻：{{ nextReleaseTime }}
      </text>
    </view>

    <view class="entry-section">
      <text class="section__title">使用积分</text>
      <view class="action-grid action-grid--use">
        <mb-button
          v-for="item in useActions"
          :key="item.label"
          class="action-button"
          :type="item.primary ? 'primary' : 'secondary'"
          size="medium"
          block
          @click="item.tap()"
        >
          {{ item.label }}
        </mb-button>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 48rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.points-card {
  margin-top: $mb-spacing-md;
  padding: 32rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.points-card__top,
.points-card__stats {
  display: flex;
  align-items: center;
}

.points-card__top {
  justify-content: space-between;
}

.points-card__label,
.section__title {
  color: var(--color-text, #111827);
  font-size: 28rpx;
  font-weight: 700;
}

.points-card__status,
.points-stat__label {
  color: var(--color-text-muted, #6b7280);
  font-size: 24rpx;
}

.points-card__amount {
  display: flex;
  align-items: baseline;
  gap: 12rpx;
  margin-top: 28rpx;
}

.points-card__value {
  color: var(--color-primary, #0d50d5);
  font-size: 72rpx;
  font-weight: 800;
  line-height: 1;
}

.points-card__unit {
  color: var(--color-text-secondary, #4b5563);
  font-size: 26rpx;
  font-weight: 600;
}

.points-card__stats {
  gap: 20rpx;
  margin-top: 28rpx;
}

.points-card__summary {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14rpx;
  margin-top: 28rpx;
}

.points-summary {
  min-width: 0;
  padding: 18rpx 20rpx;
  background: var(--color-bg-surface, #f8fafc);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-md;
}

.points-summary--link,
.points-stat--link {
  transition: opacity 120ms ease, transform 120ms ease;
}

.points-summary--active,
.points-stat--active {
  opacity: 0.86;
  transform: scale(0.98);
}

.points-summary__head,
.points-stat__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12rpx;
}

.points-summary__label {
  color: var(--color-text-muted, #6b7280);
  font-size: 23rpx;
}

.points-summary__arrow,
.points-stat__arrow {
  flex-shrink: 0;
  width: 14rpx;
  height: 14rpx;
  border-top: 3rpx solid var(--color-text-tertiary, #737686);
  border-right: 3rpx solid var(--color-text-tertiary, #737686);
  transform: rotate(45deg);
}

.points-summary__value {
  display: block;
  margin-top: 8rpx;
  color: var(--color-text, #111827);
  font-size: 30rpx;
  font-weight: 800;
}

.points-card__hint {
  display: block;
  margin-top: 18rpx;
  color: var(--color-text-muted, #6b7280);
  font-size: 24rpx;
}

.points-stat {
  flex: 1;
  padding: 20rpx;
  background: var(--color-bg-surface, #f8fafc);
  border-radius: $mb-radius-md;
}

.points-stat__value {
  display: block;
  margin-top: 8rpx;
  color: var(--color-text, #111827);
  font-size: 30rpx;
  font-weight: 700;
}

.action-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16rpx;
  margin-top: 20rpx;
}

.action-grid--use {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.action-button {
  min-width: 0;
}

.entry-section {
  margin-top: 24rpx;
}
</style>
