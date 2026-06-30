<script setup>
import { useDecorateStore } from '@/store/decorate'
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getWalletInfo, getWalletLogs } from '@/api/user/wallet'
const decorateStore = useDecorateStore()

const loading = ref(false)
const wallet = ref({
  balance: '0.00',
  frozen_amount: '0.00',
  total_recharge: '0.00',
  total_consume: '0.00',
  month_consume: '0.00',
})
const recentLogs = ref([])

const balanceText = computed(() => formatAmount(wallet.value.balance))
const frozenText = computed(() => formatAmount(wallet.value.frozen_amount))
const monthConsumeText = computed(() => formatAmount(wallet.value.month_consume || wallet.value.total_consume))

onShow(() => {
  fetchWallet()
})

async function fetchWallet() {
  loading.value = true
  try {
    const data = await getWalletInfo()
    wallet.value = {
      ...wallet.value,
      ...(data || {}),
    }
  } catch {
    // 余额接口未接入时保留页面结构，不阻塞个人中心链路预览
  }

  try {
    const data = await getWalletLogs({ page: 1, limit: 3 })
    recentLogs.value = Array.isArray(data?.list) ? data.list : []
  } catch {
    recentLogs.value = []
  } finally {
    loading.value = false
  }
}

function formatAmount(value) {
  const n = Number(value || 0)
  return n.toFixed(2)
}

function signedAmount(item) {
  const amount = Number(item.change_amount ?? item.amount ?? 0)
  const direction = String(item.direction || '')
  const isIncome = direction === 'income' || amount > 0
  return `${isIncome ? '+' : '-'}¥${Math.abs(amount).toFixed(2)}`
}

function logTitle(item) {
  return item.title || item.biz_type_text || item.remark || '余额变动'
}

function logTime(item) {
  return item.create_time || item.time || ''
}

function goRecords(params = {}) {
  const query = Object.entries(params)
    .filter(([, value]) => value !== undefined && value !== '')
    .map(([key, value]) => `${key}=${encodeURIComponent(value)}`)
    .join('&')
  uni.navigateTo({ url: `/pages-sub/wallet/records${query ? `?${query}` : ''}` })
}

function goRecharge() {
  uni.navigateTo({ url: '/pages-sub/wallet/recharge' })
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="我的余额" bg-color="var(--color-bg, #ffffff)" />

    <view class="balance-card">
      <view class="balance-card__top">
        <text class="balance-card__label">可用余额</text>
        <text class="balance-card__status">{{ loading ? '同步中' : '账户正常' }}</text>
      </view>
      <view class="balance-card__amount">
        <text class="balance-card__symbol">¥</text>
        <text class="balance-card__value">{{ balanceText }}</text>
      </view>
      <view class="balance-card__stats">
        <view class="balance-stat">
          <text class="balance-stat__label">冻结金额</text>
          <text class="balance-stat__value">¥{{ frozenText }}</text>
        </view>
        <view class="balance-stat">
          <text class="balance-stat__label">本月支出</text>
          <text class="balance-stat__value">¥{{ monthConsumeText }}</text>
        </view>
      </view>
    </view>

    <view class="action-grid">
      <view class="action-item action-item--primary" @tap="goRecharge">
        <text class="action-item__label">充值</text>
      </view>
      <view class="action-item" @tap="goRecords()">
        <text class="action-item__label">余额明细</text>
      </view>
      <view class="action-item" @tap="goRecords({ biz_type: 'refund', type: 'income' })">
        <text class="action-item__label">退款记录</text>
      </view>
    </view>

    <view class="section">
      <view class="section__header">
        <text class="section__title">最近记录</text>
        <text class="section__more" @tap="goRecords()">查看全部</text>
      </view>

      <view v-if="recentLogs.length" class="log-list">
        <view v-for="item in recentLogs" :key="item.id || item.create_time" class="log-row">
          <view class="log-row__icon">
            <text class="log-row__icon-text">¥</text>
          </view>
          <view class="log-row__main">
            <text class="log-row__title">{{ logTitle(item) }}</text>
            <text class="log-row__time">{{ logTime(item) }}</text>
          </view>
          <view class="log-row__right">
            <text
              class="log-row__amount"
              :class="{ 'log-row__amount--income': signedAmount(item).startsWith('+') }"
            >
              {{ signedAmount(item) }}
            </text>
            <text v-if="item.after_amount !== undefined" class="log-row__balance">
              余额 ¥{{ formatAmount(item.after_amount) }}
            </text>
          </view>
        </view>
      </view>

      <view v-else class="empty">
        <text class="empty__icon">≡</text>
        <text class="empty__title">暂无余额记录</text>
        <text class="empty__desc">完成支付或退款后会显示在这里</text>
      </view>
    </view>
      <mb-floating-action />
</view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 48rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.balance-card {
  margin-top: $mb-spacing-md;
  padding: 32rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.balance-card__top,
.balance-card__stats,
.section__header,
.log-row {
  display: flex;
  align-items: center;
}

.balance-card__top {
  justify-content: space-between;
}

.balance-card__label {
  font-size: $mb-font-md;
  color: var(--color-text-secondary, #434654);
}

.balance-card__status {
  padding: 6rpx 14rpx;
  border-radius: $mb-radius-full;
  background: var(--color-success-soft, rgba(52, 199, 89, 0.1));
  color: var(--color-success, #34c759);
  font-size: $mb-font-xs;
}

.balance-card__amount {
  display: flex;
  align-items: baseline;
  margin-top: 18rpx;
}

.balance-card__symbol {
  font-size: $mb-font-xl;
  color: var(--color-text-title, #191b23);
  font-weight: 700;
}

.balance-card__value {
  margin-left: 6rpx;
  font-size: 72rpx;
  line-height: 1;
  color: var(--color-text-title, #191b23);
  font-weight: 700;
}

.balance-card__stats {
  gap: $mb-spacing-md;
  margin-top: 28rpx;
}

.balance-stat {
  flex: 1;
  padding: 20rpx;
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-md;
}

.balance-stat__label,
.log-row__time,
.log-row__balance,
.empty__desc {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}

.balance-stat__value {
  display: block;
  margin-top: 8rpx;
  font-size: $mb-font-md;
  color: var(--color-text-title, #191b23);
  font-weight: 600;
}

.action-grid {
  display: flex;
  gap: $mb-spacing-sm;
  margin-top: $mb-spacing-md;
  padding: 20rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.action-item {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  height: $mb-btn-height-sm;
  padding: 0 $mb-btn-padding-x-sm;
  border-radius: $mb-radius-full;
  background: var(--color-bg-surface, #f3f3fe);
}

.action-item--primary {
  background: var(--color-primary, #0d50d5);
}

.action-item__label {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
  font-weight: 600;
}

.action-item--primary .action-item__label {
  color: var(--color-text-inverse, #ffffff);
}

.section {
  margin-top: $mb-spacing-md;
  padding: $mb-spacing-lg;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.section__header {
  justify-content: space-between;
  margin-bottom: $mb-spacing-md;
}

.section__title {
  font-size: $mb-font-lg;
  color: var(--color-text-title, #191b23);
  font-weight: 600;
}

.section__more {
  font-size: $mb-font-sm;
  color: var(--color-primary, #0d50d5);
}

.log-row {
  padding: 22rpx 0;

  & + & {
    border-top: 1rpx solid var(--color-divider, #f0f2f5);
  }
}

.log-row__icon {
  width: 64rpx;
  height: 64rpx;
  border-radius: $mb-radius-md;
  background: var(--color-warning-soft, rgba(240, 173, 78, 0.12));
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: $mb-spacing-md;
}

.log-row__icon-text {
  font-size: 30rpx;
  color: #d97706;
  font-weight: 700;
}

.log-row__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.log-row__title {
  font-size: $mb-font-md;
  color: var(--color-text-title, #191b23);
  font-weight: 500;
}

.log-row__right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8rpx;
}

.log-row__amount {
  font-size: $mb-font-md;
  color: var(--color-text-title, #191b23);
  font-weight: 700;
}

.log-row__amount--income {
  color: var(--color-success, #34c759);
}

.empty {
  padding: 72rpx 0 48rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.empty__icon {
  width: 96rpx;
  height: 96rpx;
  border-radius: $mb-radius-lg;
  background: var(--color-bg-surface, #f3f3fe);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-text-tertiary, #737686);
  font-size: 44rpx;
}

.empty__title {
  margin-top: 22rpx;
  font-size: $mb-font-md;
  color: var(--color-text-title, #191b23);
  font-weight: 600;
}

.empty__desc {
  margin-top: 8rpx;
}
</style>
