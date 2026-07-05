<script setup>
import { useDecorateStore } from '@/store/decorate'
import { computed, ref } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { getWalletLogs } from '@/api/user/wallet'
const decorateStore = useDecorateStore()

const tabs = [
  { key: '', label: '全部' },
  { key: 'income', label: '收入' },
  { key: 'expense', label: '支出' },
]
const ranges = [
  { key: 'month', label: '本月' },
  { key: 'three_months', label: '近三月' },
  { key: 'custom', label: '自定义' },
]

const activeType = ref('')
const activeBizType = ref('')
const activeRange = ref('month')
const list = ref([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const finished = ref(false)

const groupedList = computed(() => {
  const groups = []
  const map = {}
  list.value.forEach((item) => {
    const label = dateLabel(item.create_time || item.time)
    if (!map[label]) {
      map[label] = { label, items: [] }
      groups.push(map[label])
    }
    map[label].items.push(item)
  })
  return groups
})

onLoad((query) => {
  activeType.value = query?.type || ''
  activeBizType.value = query?.biz_type || ''
  fetchLogs(true)
})

onPullDownRefresh(async () => {
  await fetchLogs(true)
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  if (!finished.value && !loading.value) fetchLogs(false)
})

async function fetchLogs(reset) {
  if (loading.value) return
  if (reset) {
    page.value = 1
    finished.value = false
  }

  loading.value = true
  try {
    const data = await getWalletLogs({
      page: page.value,
      limit: 20,
      type: activeType.value,
      biz_type: activeBizType.value,
      range: activeRange.value,
    })
    const rows = Array.isArray(data?.list) ? data.list : []
    total.value = Number(data?.total || rows.length || 0)
    list.value = reset ? rows : list.value.concat(rows)
    finished.value = list.value.length >= total.value || rows.length === 0
    if (!finished.value) page.value += 1
  } catch {
    if (reset) list.value = []
    finished.value = true
  } finally {
    loading.value = false
  }
}

function switchType(type) {
  if (activeType.value === type) return
  activeType.value = type
  fetchLogs(true)
}

function switchRange(range) {
  activeRange.value = range
  fetchLogs(true)
}

function resetFilter() {
  activeType.value = ''
  activeBizType.value = ''
  activeRange.value = 'month'
  fetchLogs(true)
}

function dateLabel(value) {
  if (!value) return '更早'
  const date = String(value).slice(0, 10)
  const now = new Date()
  const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
  if (date === today) return '今天'
  const [, month, day] = date.split('-')
  return `${month}月${day}日`
}

function pad(value) {
  return String(value).padStart(2, '0')
}

function signedAmount(item) {
  const amount = Number(item.change_amount ?? item.amount ?? 0)
  const direction = String(item.direction || '')
  const isIncome = direction === 'income' || amount > 0
  return {
    text: `${isIncome ? '+' : '-'}¥${Math.abs(amount).toFixed(2)}`,
    income: isIncome,
  }
}

function titleOf(item) {
  return item.title || item.biz_type_text || item.remark || '余额变动'
}

function descOf(item) {
  return item.biz_sn || item.order_sn || item.create_time || ''
}

function formatAmount(value) {
  return Number(value || 0).toFixed(2)
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="余额明细" bg-color="var(--color-bg, #ffffff)" />

    <view class="tabs">
      <view
        v-for="tab in tabs"
        :key="tab.key"
        class="tab"
        :class="{ 'tab--active': activeType === tab.key }"
        @tap="switchType(tab.key)"
      >
        <text class="tab__text">{{ tab.label }}</text>
      </view>
    </view>

    <view class="filter-row">
      <view
        v-for="range in ranges"
        :key="range.key"
        class="filter-chip"
        :class="{ 'filter-chip--active': activeRange === range.key }"
        @tap="switchRange(range.key)"
      >
        <text class="filter-chip__text">{{ range.label }}</text>
      </view>
    </view>

    <view v-if="groupedList.length" class="record-list">
      <view v-for="group in groupedList" :key="group.label" class="record-group">
        <text class="record-group__date">{{ group.label }}</text>
        <view class="record-card">
          <view v-for="item in group.items" :key="item.id || item.create_time" class="record-row">
            <view class="record-row__icon" :class="{ 'record-row__icon--income': signedAmount(item).income }">
              <text class="record-row__icon-text">{{ signedAmount(item).income ? '+' : '-' }}</text>
            </view>
            <view class="record-row__main">
              <text class="record-row__title">{{ titleOf(item) }}</text>
              <text class="record-row__desc">{{ descOf(item) }}</text>
            </view>
            <view class="record-row__right">
              <text
                class="record-row__amount"
                :class="{ 'record-row__amount--income': signedAmount(item).income }"
              >
                {{ signedAmount(item).text }}
              </text>
              <text v-if="item.after_amount !== undefined" class="record-row__balance">
                余额 ¥{{ formatAmount(item.after_amount) }}
              </text>
            </view>
          </view>
        </view>
      </view>

      <view class="load-state">
        <text class="load-state__text">{{ finished ? '没有更多记录了' : '加载中...' }}</text>
      </view>
    </view>

    <view v-else class="empty">
      <view class="empty__wallet">
        <text class="empty__wallet-icon">¥</text>
      </view>
      <text class="empty__title">暂无余额记录</text>
      <text class="empty__desc">换个时间范围试试</text>

      <view class="empty-filter">
        <view class="empty-filter__row">
          <text class="empty-filter__label">类型</text>
          <text class="empty-filter__value">
            {{ tabs.find((item) => item.key === activeType)?.label || '全部' }}
          </text>
        </view>
        <view class="empty-filter__row">
          <text class="empty-filter__label">时间</text>
          <text class="empty-filter__value">
            {{ ranges.find((item) => item.key === activeRange)?.label || '本月' }}
          </text>
        </view>
        <view class="empty-filter__actions">
          <view class="empty-filter__btn empty-filter__btn--ghost" @tap="resetFilter">
            <text class="empty-filter__btn-text empty-filter__btn-text--ghost">重置</text>
          </view>
          <view class="empty-filter__btn empty-filter__btn--primary" @tap="fetchLogs(true)">
            <text class="empty-filter__btn-text empty-filter__btn-text--primary">查询</text>
          </view>
        </view>
      </view>
    </view>
      <mb-copyright-footer />
      <mb-floating-action />
</view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 56rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.tabs {
  display: flex;
  margin-top: $mb-spacing-md;
  padding: 6rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-md;
}

.tab {
  flex: 1;
  height: 64rpx;
  border-radius: $mb-radius-sm;
  display: flex;
  align-items: center;
  justify-content: center;
}

.tab--active {
  background: var(--color-primary, #0d50d5);
}

.tab__text {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
  font-weight: 600;
}

.tab--active .tab__text {
  color: var(--color-text-inverse, #ffffff);
}

.filter-row {
  display: flex;
  gap: 12rpx;
  margin-top: $mb-spacing-md;
}

.filter-chip {
  height: 56rpx;
  padding: 0 24rpx;
  border-radius: $mb-radius-full;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  display: flex;
  align-items: center;
}

.filter-chip--active {
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.08));
  border-color: var(--color-primary-border, rgba(13, 80, 213, 0.3));
}

.filter-chip__text {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
}

.filter-chip--active .filter-chip__text {
  color: var(--color-primary, #0d50d5);
  font-weight: 600;
}

.record-list {
  margin-top: $mb-spacing-lg;
}

.record-group {
  margin-bottom: $mb-spacing-lg;
}

.record-group__date {
  display: block;
  margin-bottom: $mb-spacing-sm;
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}

.record-card {
  overflow: hidden;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.record-row {
  display: flex;
  align-items: center;
  padding: 26rpx $mb-spacing-lg;

  & + & {
    border-top: 1rpx solid var(--color-divider, #f0f2f5);
  }
}

.record-row__icon {
  width: 64rpx;
  height: 64rpx;
  border-radius: $mb-radius-md;
  background: var(--color-warning-soft, rgba(240, 173, 78, 0.12));
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: $mb-spacing-md;
}

.record-row__icon--income {
  background: var(--color-success-soft, rgba(52, 199, 89, 0.1));
}

.record-row__icon-text {
  font-size: 34rpx;
  color: #d97706;
  font-weight: 700;
}

.record-row__icon--income .record-row__icon-text {
  color: var(--color-success, #34c759);
}

.record-row__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.record-row__title {
  font-size: $mb-font-md;
  color: var(--color-text-title, #191b23);
  font-weight: 600;
}

.record-row__desc,
.record-row__balance,
.load-state__text,
.empty__desc,
.empty-filter__label {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}

.record-row__right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8rpx;
}

.record-row__amount {
  font-size: $mb-font-md;
  color: var(--color-text-title, #191b23);
  font-weight: 700;
}

.record-row__amount--income {
  color: var(--color-success, #34c759);
}

.load-state {
  padding: 16rpx 0 0;
  display: flex;
  justify-content: center;
}

.empty {
  min-height: 68vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.empty__wallet {
  width: 116rpx;
  height: 116rpx;
  border-radius: $mb-radius-lg;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  display: flex;
  align-items: center;
  justify-content: center;
}

.empty__wallet-icon {
  font-size: 52rpx;
  color: var(--color-text-tertiary, #737686);
  font-weight: 700;
}

.empty__title {
  margin-top: 24rpx;
  font-size: $mb-font-lg;
  color: var(--color-text-title, #191b23);
  font-weight: 600;
}

.empty__desc {
  margin-top: 8rpx;
}

.empty-filter {
  width: 100%;
  margin-top: 44rpx;
  padding: $mb-spacing-lg;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.empty-filter__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18rpx 0;
}

.empty-filter__value {
  font-size: $mb-font-sm;
  color: var(--color-text-title, #191b23);
  font-weight: 600;
}

.empty-filter__actions {
  display: flex;
  gap: $mb-spacing-md;
  margin-top: $mb-spacing-md;
}

.empty-filter__btn {
  flex: 1;
  height: 76rpx;
  border-radius: $mb-radius-md;
  display: flex;
  align-items: center;
  justify-content: center;
}

.empty-filter__btn--ghost {
  background: var(--color-bg-surface, #f3f3fe);
}

.empty-filter__btn--primary {
  background: var(--color-primary, #0d50d5);
}

.empty-filter__btn-text {
  font-size: $mb-font-md;
  font-weight: 600;
}

.empty-filter__btn-text--ghost {
  color: var(--color-text-secondary, #434654);
}

.empty-filter__btn-text--primary {
  color: var(--color-text-inverse, #ffffff);
}
</style>
