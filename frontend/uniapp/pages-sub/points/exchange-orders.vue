<script setup>
import { useDecorateStore } from '@/store/decorate'
import { computed, ref } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { cancelPointsExchangeOrder, getPointsExchangeOrders } from '@/api/points/mall'
import { isPointsEnabled, leavePointsPage } from '@/utils/points-feature'

const decorateStore = useDecorateStore()

const tabs = [
  { key: '', label: '全部' },
  { key: 10, label: '待发货' },
  { key: 20, label: '已发货' },
  { key: 30, label: '已完成' },
  { key: 90, label: '已关闭' },
]

const activeStatus = ref('')
const list = ref([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const finished = ref(false)
const pointsEnabled = ref(true)
const cancellingId = ref(0)

const emptyText = computed(() => {
  const tab = tabs.find((item) => item.key === activeStatus.value)
  return tab?.label && tab.key !== '' ? `暂无${tab.label}兑换单` : '暂无兑换记录'
})

onLoad(async (query) => {
  const status = query?.status
  activeStatus.value = status === undefined || status === '' ? '' : Number(status)
  if (await ensurePointsEnabled()) {
    fetchList(true)
  }
})

onPullDownRefresh(async () => {
  if (await ensurePointsEnabled()) {
    await fetchList(true)
  }
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  if (!pointsEnabled.value || loading.value || finished.value) return
  fetchList(false)
})

async function ensurePointsEnabled() {
  pointsEnabled.value = await isPointsEnabled()
  if (!pointsEnabled.value) {
    leavePointsPage()
    return false
  }
  return true
}

async function fetchList(reset) {
  if (loading.value) return
  if (reset) {
    page.value = 1
    finished.value = false
  }

  loading.value = true
  try {
    const data = await getPointsExchangeOrders({
      page: page.value,
      limit: 10,
      status: activeStatus.value,
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

function switchStatus(status) {
  if (activeStatus.value === status) return
  activeStatus.value = status
  fetchList(true)
}

function goDetail(item) {
  uni.navigateTo({ url: `/pages-sub/points/exchange-detail?id=${item.id}` })
}

function goMall() {
  uni.navigateTo({ url: '/pages-sub/points/mall' })
}

function confirmCancel(item) {
  if (!item || item.status !== 10 || cancellingId.value) return
  uni.showModal({
    title: '取消兑换',
    content: '确认取消该兑换单？积分和库存会原路返还。',
    confirmText: '确认取消',
    success: async (res) => {
      if (!res.confirm) return
      cancellingId.value = Number(item.id)
      try {
        await cancelPointsExchangeOrder(item.id)
        uni.showToast({ title: '已取消', icon: 'success' })
        await fetchList(true)
      } finally {
        cancellingId.value = 0
      }
    },
  })
}

function imageUrl(item) {
  return item.goods_image_full_url || item.goods_image || ''
}

function statusClass(status) {
  if (status === 10) return 'status--pending'
  if (status === 20) return 'status--shipped'
  if (status === 30) return 'status--completed'
  return 'status--closed'
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="兑换记录" bg-color="var(--color-bg, #ffffff)" />

    <scroll-view class="tabs" scroll-x>
      <view
        v-for="tab in tabs"
        :key="String(tab.key)"
        class="tab"
        :class="{ 'tab--active': activeStatus === tab.key }"
        @tap="switchStatus(tab.key)"
      >
        <text class="tab__text">{{ tab.label }}</text>
      </view>
    </scroll-view>

    <view v-if="list.length" class="order-list">
      <view
        v-for="item in list"
        :key="item.id"
        class="order-card"
        @tap="goDetail(item)"
      >
        <view class="order-card__header">
          <text class="order-card__sn">{{ item.sn }}</text>
          <text class="order-card__status" :class="statusClass(item.status)">
            {{ item.status_text }}
          </text>
        </view>
        <view class="order-card__body">
          <image class="order-card__image" :src="imageUrl(item)" mode="aspectFill" />
          <view class="order-card__main">
            <text class="order-card__name">{{ item.goods_name }}</text>
            <text class="order-card__spec">{{ item.sku_spec || '默认规格' }}</text>
            <view class="order-card__bottom">
              <text class="order-card__points">{{ item.total_points }} 积分</text>
              <text class="order-card__qty">x{{ item.quantity }}</text>
            </view>
          </view>
        </view>
        <view class="order-card__footer">
          <text class="order-card__time">{{ item.create_time }}</text>
          <view class="order-card__actions">
            <view
              v-if="item.status === 10"
              class="cancel-button"
              :class="{ 'cancel-button--disabled': cancellingId === item.id }"
              @tap.stop="confirmCancel(item)"
            >
              <text class="cancel-button__text">
                {{ cancellingId === item.id ? '取消中' : '取消兑换' }}
              </text>
            </view>
            <text class="order-card__arrow">&#10095;</text>
          </view>
        </view>
      </view>

      <view class="load-state">
        <text class="load-state__text">
          {{ finished ? '没有更多记录了' : '加载中...' }}
        </text>
      </view>
    </view>

    <view v-else class="empty">
      <text class="empty__icon">P</text>
      <text class="empty__title">{{ emptyText }}</text>
      <text class="empty__desc">兑换成功后会显示在这里</text>
      <view class="empty__button" @tap="goMall">
        <text class="empty__button-text">去兑换</text>
      </view>
    </view>
    <mb-copyright-footer />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 48rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.tabs {
  width: 100%;
  margin-top: $mb-spacing-md;
  white-space: nowrap;
}

.tab {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 66rpx;
  padding: 0 28rpx;
  margin-right: 14rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: 999rpx;
}

.tab--active {
  background: var(--color-primary, #0d50d5);
  border-color: var(--color-primary, #0d50d5);
}

.tab__text {
  color: var(--color-text, #111827);
  font-size: 26rpx;
  font-weight: 700;
}

.tab--active .tab__text {
  color: #ffffff;
}

.order-list {
  margin-top: 20rpx;
}

.order-card {
  padding: 24rpx;
  margin-bottom: 18rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.order-card__header,
.order-card__body,
.order-card__bottom,
.order-card__footer {
  display: flex;
  align-items: center;
}

.order-card__header,
.order-card__bottom,
.order-card__footer {
  justify-content: space-between;
}

.order-card__sn {
  color: var(--color-text, #111827);
  font-size: 26rpx;
  font-weight: 800;
}

.order-card__status {
  font-size: 24rpx;
  font-weight: 800;
}

.status--pending {
  color: #f97316;
}

.status--shipped {
  color: var(--color-primary, #0d50d5);
}

.status--completed {
  color: #16a34a;
}

.status--closed {
  color: var(--color-text-muted, #6b7280);
}

.order-card__body {
  gap: 18rpx;
  margin-top: 20rpx;
}

.order-card__image {
  flex-shrink: 0;
  width: 140rpx;
  height: 140rpx;
  background: var(--color-bg-surface, #f8fafc);
  border-radius: $mb-radius-md;
}

.order-card__main {
  display: flex;
  flex: 1;
  min-width: 0;
  flex-direction: column;
}

.order-card__name {
  color: var(--color-text, #111827);
  font-size: 28rpx;
  font-weight: 800;
  line-height: 1.35;
}

.order-card__spec,
.order-card__time,
.order-card__qty,
.load-state__text,
.empty__desc {
  color: var(--color-text-muted, #6b7280);
  font-size: 24rpx;
}

.order-card__spec {
  margin-top: 8rpx;
}

.order-card__bottom {
  margin-top: 24rpx;
}

.order-card__points {
  color: var(--color-primary, #0d50d5);
  font-size: 30rpx;
  font-weight: 900;
}

.order-card__footer {
  margin-top: 20rpx;
  padding-top: 18rpx;
  border-top: 1rpx solid var(--color-divider, #f0f2f5);
}

.order-card__actions {
  display: flex;
  align-items: center;
  gap: 18rpx;
}

.order-card__arrow {
  color: var(--color-text-muted, #6b7280);
  font-size: 26rpx;
}

.cancel-button {
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 140rpx;
  height: 54rpx;
  padding: 0 18rpx;
  border: 1rpx solid #f97316;
  border-radius: $mb-radius-md;
}

.cancel-button--disabled {
  opacity: 0.55;
}

.cancel-button__text {
  color: #f97316;
  font-size: 24rpx;
  font-weight: 800;
}

.load-state {
  padding: 24rpx 0;
  text-align: center;
}

.empty {
  display: flex;
  align-items: center;
  flex-direction: column;
  margin-top: 140rpx;
  text-align: center;
}

.empty__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 92rpx;
  height: 92rpx;
  color: #ffffff;
  font-size: 40rpx;
  font-weight: 900;
  background: var(--color-primary, #0d50d5);
  border-radius: 50%;
}

.empty__title {
  margin-top: 22rpx;
  color: var(--color-text, #111827);
  font-size: 30rpx;
  font-weight: 800;
}

.empty__desc {
  margin-top: 8rpx;
}

.empty__button {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 220rpx;
  height: 72rpx;
  margin-top: 28rpx;
  background: var(--color-primary, #0d50d5);
  border-radius: 999rpx;
}

.empty__button-text {
  color: #ffffff;
  font-size: 26rpx;
  font-weight: 800;
}
</style>
