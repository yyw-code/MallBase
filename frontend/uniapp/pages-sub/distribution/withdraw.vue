<script setup>
import { useDecorateStore } from "@/store/decorate";
import { computed, reactive, ref } from "vue";
import { onPullDownRefresh, onReachBottom } from "@dcloudio/uni-app";
import {
  applyDistributionWithdraw,
  getDistributionSummary,
  getDistributionWithdraws,
} from "@/api/distribution/distribution";

const decorateStore = useDecorateStore();

const statusTabs = [
  { key: "", label: "全部" },
  { key: 0, label: "待审核" },
  { key: 10, label: "已通过" },
  { key: 20, label: "已驳回" },
];

const summary = ref({
  available_commission: "0.00",
  min_withdraw_amount: "0.00",
  pending_withdraw: "0.00",
});
const form = reactive({
  account_name: "",
  account_no: "",
  account_type: "offline",
  amount: "",
});
const activeStatus = ref("");
const list = ref([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const submitting = ref(false);
const finished = ref(false);

const availableText = computed(() =>
  formatAmount(summary.value.available_commission),
);
const pendingText = computed(() => formatAmount(summary.value.pending_withdraw));
const minWithdrawText = computed(() =>
  formatAmount(summary.value.min_withdraw_amount),
);

fetchPage(true);

onPullDownRefresh(async () => {
  await fetchPage(true);
  uni.stopPullDownRefresh();
});

onReachBottom(() => {
  if (!finished.value && !loading.value) fetchWithdraws(false);
});

async function fetchPage(reset) {
  await Promise.all([fetchSummary(), fetchWithdraws(reset)]);
}

async function fetchSummary() {
  try {
    const data = await getDistributionSummary();
    summary.value = {
      ...summary.value,
      ...(data || {}),
    };
  } catch {
    summary.value = {
      available_commission: "0.00",
      min_withdraw_amount: "0.00",
      pending_withdraw: "0.00",
    };
  }
}

async function fetchWithdraws(reset) {
  if (loading.value) return;
  if (reset) {
    page.value = 1;
    finished.value = false;
  }

  loading.value = true;
  try {
    const data = await getDistributionWithdraws({
      page: page.value,
      limit: 20,
      status: activeStatus.value,
    });
    const rows = Array.isArray(data?.list) ? data.list : [];
    total.value = Number(data?.total || rows.length || 0);
    list.value = reset ? rows : list.value.concat(rows);
    finished.value = list.value.length >= total.value || rows.length === 0;
    if (!finished.value) page.value += 1;
  } catch {
    if (reset) list.value = [];
    finished.value = true;
  } finally {
    loading.value = false;
  }
}

function formatAmount(value) {
  return Number(value || 0).toFixed(2);
}

function switchStatus(status) {
  if (activeStatus.value === status) return;
  activeStatus.value = status;
  fetchWithdraws(true);
}

async function submitWithdraw() {
  if (submitting.value) return;
  if (!form.amount) {
    uni.showToast({ title: "请输入提现金额", icon: "none" });
    return;
  }
  if (!form.account_name.trim()) {
    uni.showToast({ title: "请输入账户名", icon: "none" });
    return;
  }
  if (!form.account_no.trim()) {
    uni.showToast({ title: "请输入账户号", icon: "none" });
    return;
  }

  submitting.value = true;
  try {
    await applyDistributionWithdraw({
      account_name: form.account_name,
      account_no: form.account_no,
      account_type: form.account_type,
      amount: form.amount,
    });
    uni.showToast({ title: "提交成功", icon: "success" });
    form.amount = "";
    form.account_name = "";
    form.account_no = "";
    await fetchPage(true);
  } finally {
    submitting.value = false;
  }
}

function statusColor(status) {
  if (Number(status) === 10) return "withdraw-row__status--success";
  if (Number(status) === 20) return "withdraw-row__status--danger";
  return "withdraw-row__status--warning";
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="佣金提现" bg-color="var(--color-bg, #ffffff)" />

    <view class="summary-card">
      <view>
        <text class="summary-card__label">可提现佣金</text>
        <view class="summary-card__amount">
          <text class="summary-card__symbol">¥</text>
          <text class="summary-card__value">{{ availableText }}</text>
        </view>
      </view>
      <view class="summary-card__side">
        <text class="summary-card__side-label">提现中</text>
        <text class="summary-card__side-value">¥{{ pendingText }}</text>
      </view>
    </view>

    <view class="form-card">
      <text class="form-card__title">提现申请</text>
      <view class="form-row">
        <text class="form-row__label">金额</text>
        <input
          v-model="form.amount"
          class="form-row__input"
          placeholder="请输入金额"
          placeholder-class="input-placeholder"
          type="digit"
        />
      </view>
      <view class="form-row">
        <text class="form-row__label">账户名</text>
        <input
          v-model="form.account_name"
          class="form-row__input"
          placeholder="请输入账户名"
          placeholder-class="input-placeholder"
        />
      </view>
      <view class="form-row">
        <text class="form-row__label">账户号</text>
        <input
          v-model="form.account_no"
          class="form-row__input"
          placeholder="请输入账户号"
          placeholder-class="input-placeholder"
        />
      </view>
      <text class="form-card__hint">最低提现 ¥{{ minWithdrawText }}</text>
      <view class="submit-btn" @tap="submitWithdraw">
        <text class="submit-btn__text">{{ submitting ? "提交中" : "提交申请" }}</text>
      </view>
    </view>

    <view class="tabs">
      <view
        v-for="item in statusTabs"
        :key="item.key"
        class="tab"
        :class="{ 'tab--active': activeStatus === item.key }"
        @tap="switchStatus(item.key)"
      >
        <text class="tab__text">{{ item.label }}</text>
      </view>
    </view>

    <view v-if="list.length" class="withdraw-list">
      <view
        v-for="item in list"
        :key="item.id || item.sn"
        class="withdraw-row"
      >
        <view class="withdraw-row__main">
          <text class="withdraw-row__title">{{ item.sn }}</text>
          <text class="withdraw-row__desc">
            {{ item.account_name }} · {{ item.account_no }}
          </text>
          <text v-if="item.admin_remark" class="withdraw-row__desc">
            {{ item.admin_remark }}
          </text>
        </view>
        <view class="withdraw-row__right">
          <text class="withdraw-row__amount">¥{{ formatAmount(item.amount) }}</text>
          <text class="withdraw-row__status" :class="statusColor(item.status)">
            {{ item.status_text }}
          </text>
        </view>
      </view>

      <view class="load-state">
        <text class="load-state__text">{{
          finished ? "没有更多记录了" : "加载中..."
        }}</text>
      </view>
    </view>

    <view v-else class="empty">
      <text class="empty__title">暂无提现记录</text>
    </view>

    <mb-floating-action />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 56rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.summary-card,
.form-card,
.tabs,
.withdraw-list,
.empty {
  margin-top: $mb-spacing-md;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.summary-card {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  padding: 32rpx;
}

.summary-card__label,
.summary-card__side-label,
.form-card__hint,
.withdraw-row__desc,
.load-state__text {
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
}

.summary-card__amount {
  display: flex;
  align-items: baseline;
  margin-top: 12rpx;
}

.summary-card__symbol {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-lg;
  font-weight: 700;
}

.summary-card__value {
  margin-left: 6rpx;
  color: var(--color-text-title, #191b23);
  font-size: 60rpx;
  font-weight: 700;
  line-height: 1;
}

.summary-card__side {
  text-align: right;
}

.summary-card__side-value {
  display: block;
  margin-top: 8rpx;
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-md;
  font-weight: 700;
}

.form-card {
  padding: 28rpx;
}

.form-card__title {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-md;
  font-weight: 700;
}

.form-row {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
  height: 88rpx;
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);
}

.form-row__label {
  width: 120rpx;
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-sm;
}

.form-row__input {
  flex: 1;
  color: var(--color-text, #111827);
  font-size: $mb-font-sm;
}

.input-placeholder {
  color: var(--color-text-tertiary, #737686);
}

.form-card__hint {
  display: block;
  margin-top: 18rpx;
}

.submit-btn {
  height: 76rpx;
  margin-top: 24rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-primary, #0d50d5);
  border-radius: $mb-radius-md;
}

.submit-btn__text {
  color: #ffffff;
  font-size: $mb-font-sm;
  font-weight: 700;
}

.tabs {
  display: flex;
  gap: $mb-spacing-sm;
  padding: 16rpx;
}

.tab {
  flex: 1;
  height: 56rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-full;
}

.tab--active {
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.1));
}

.tab__text {
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-xs;
}

.tab--active .tab__text {
  color: var(--color-primary, #0d50d5);
  font-weight: 700;
}

.withdraw-list {
  overflow: hidden;
}

.withdraw-row {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
  padding: 24rpx;
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);
}

.withdraw-row:last-child {
  border-bottom: 0;
}

.withdraw-row__main {
  flex: 1;
  min-width: 0;
}

.withdraw-row__title {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-sm;
  font-weight: 700;
}

.withdraw-row__desc {
  display: block;
  margin-top: 6rpx;
}

.withdraw-row__right {
  min-width: 150rpx;
  text-align: right;
}

.withdraw-row__amount {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-sm;
  font-weight: 700;
}

.withdraw-row__status {
  display: block;
  margin-top: 8rpx;
  font-size: $mb-font-xs;
}

.withdraw-row__status--warning {
  color: var(--color-warning, #faad14);
}

.withdraw-row__status--success {
  color: var(--color-success, #34c759);
}

.withdraw-row__status--danger {
  color: var(--color-danger, #ff4d4f);
}

.load-state {
  padding: 20rpx 0;
  text-align: center;
}

.empty {
  padding: 64rpx 24rpx;
  text-align: center;
}

.empty__title {
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
}
</style>
