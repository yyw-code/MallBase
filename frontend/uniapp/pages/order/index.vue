<template>
  <view class="page">
    <!-- Navbar -->
    <view class="page__navbar">
      <view class="page__status-bar" :style="{ height: statusBarHeight + 'px' }" />
      <view class="page__nav-content">
        <text class="page__nav-title">我的订单</text>
        <view class="page__nav-icons">
          <text class="page__nav-icon" @tap="onSearch">&#x2315;</text>
          <text class="page__nav-icon page__nav-icon--dots" @tap="onMore">&#x22EF;</text>
        </view>
      </view>
    </view>

    <!-- Tabs -->
    <view class="page__tabs-wrap">
      <view class="page__tabs-bar" :style="{ height: statusBarHeight + 'px' }" />
      <view class="page__tabs-nav" />
      <view class="page__tabs">
        <view
          v-for="tab in tabs"
          :key="tab.key"
          :class="['page__tab', { 'page__tab--active': currentTab === tab.key }]"
          @tap="switchTab(tab.key)"
        >
          <text class="page__tab-label">{{ tab.label }}</text>
          <view v-if="currentTab === tab.key" class="page__tab-indicator" />
        </view>
      </view>
    </view>

    <!-- Placeholder for fixed header -->
    <view class="page__placeholder" :style="{ height: headerHeight + 'px' }" />

    <!-- Loading skeleton -->
    <view v-if="loading && orderList.length === 0" class="page__loading">
      <mb-skeleton v-for="i in 3" :key="i" type="card" />
    </view>

    <!-- Order list -->
    <view v-else-if="orderList.length > 0" class="page__list">
      <view
        v-for="order in orderList"
        :key="order.id"
        class="order-card"
        @tap="goDetail(order.id)"
      >
        <!-- Card header: order number + status -->
        <view class="order-card__header">
          <text class="order-card__sn">订单号：{{ order.sn }}</text>
          <view
            class="order-card__status-tag"
            :class="statusTagClass(order.status)"
          >
            <text class="order-card__status-text" :class="statusTagClass(order.status)">
              {{ statusLabel(order.status) }}
            </text>
          </view>
        </view>

        <!-- Product items -->
        <view
          v-for="item in order.items"
          :key="item.id"
          class="order-card__item"
        >
          <image
            class="order-card__img"
            :src="item.goods_image || item.cover"
            mode="aspectFill"
          />
          <view class="order-card__info">
            <text class="order-card__name">{{ item.goods_name || item.name }}</text>
            <text v-if="item.sku_spec" class="order-card__spec">{{ item.sku_spec }}</text>
            <view class="order-card__price-row">
              <mb-price :value="item.unit_price" size="sm" />
              <text v-if="item.quantity > 1" class="order-card__qty">&times;{{ item.quantity }}</text>
            </view>
          </view>
        </view>

        <!-- Footer: total summary -->
        <view class="order-card__footer">
          <text class="order-card__total">
            共{{ order.total_quantity || order.items?.length || 0 }}件 合计
          </text>
          <mb-price
            :value="order.total_amount"
            size="md"
            color="var(--color-text-title, #131b2e)"
          />
        </view>

        <!-- Action buttons -->
        <view v-if="getActions(order).length > 0" class="order-card__actions">
          <view
            v-for="act in getActions(order)"
            :key="act.key"
            :class="['order-card__btn', { 'order-card__btn--primary': act.primary }]"
            @tap.stop="handleAction(act.key, order)"
          >
            <text
              :class="['order-card__btn-text', { 'order-card__btn-text--primary': act.primary }]"
            >{{ act.label }}</text>
          </view>
        </view>
      </view>

      <!-- Load more -->
      <view v-if="loadingMore" class="page__load-more">
        <text class="page__load-text">加载中...</text>
      </view>
      <view v-else-if="noMore" class="page__load-more">
        <text class="page__load-text">没有更多了</text>
      </view>
    </view>

    <!-- Empty state -->
    <mb-empty-state
      v-else
      icon="📋"
      :text="'暂无' + currentTabLabel + '订单'"
      actionText="去逛逛"
      @action="goShopping"
    />
  </view>
</template>

<script setup>
import { ref, computed } from 'vue'
import { onShow, onReachBottom } from '@dcloudio/uni-app'
import { getOrderList, payOrder, cancelOrder, confirmReceive } from '@/api/order/order'
import { isLoggedIn } from '@/utils/auth'

const systemInfo = uni.getSystemInfoSync()
const statusBarHeight = systemInfo.statusBarHeight || 0
const navContentPx = uni.upx2px(88)
const tabBarPx = uni.upx2px(88)
const headerHeight = statusBarHeight + navContentPx + tabBarPx

const STATUS_MAP = {
  0:  { label: '待付款', theme: 'primary' },
  10: { label: '待发货', theme: 'warning' },
  20: { label: '待收货', theme: 'warning' },
  30: { label: '已收货', theme: 'success' },
  40: { label: '已完成', theme: 'success' },
  90: { label: '已关闭', theme: 'muted' },
}

const tabs = [
  { key: 'all',         label: '全部',   statuses: [] },
  { key: 'pending_pay', label: '待付款', statuses: [0] },
  { key: 'paid',        label: '待发货', statuses: [10] },
  { key: 'shipped',     label: '待收货', statuses: [20] },
  { key: 'completed',   label: '已完成', statuses: [30, 40] },
]

const currentTab = ref('all')
const orderList = ref([])
const page = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const noMore = ref(false)
const limit = 10

const currentTabLabel = computed(() => {
  const t = tabs.find((t) => t.key === currentTab.value)
  return t && t.key !== 'all' ? t.label : ''
})

const currentStatuses = computed(() => {
  const t = tabs.find((t) => t.key === currentTab.value)
  return t ? t.statuses : []
})

function statusLabel(status) {
  return STATUS_MAP[status]?.label || '未知'
}

function statusTagClass(status) {
  const theme = STATUS_MAP[status]?.theme || 'muted'
  return `order-card__status--${theme}`
}

function getActions(order) {
  const actions = []
  if (order.status === 0) {
    actions.push({ key: 'cancel', label: '取消订单', primary: false })
    actions.push({ key: 'pay', label: '去付款', primary: true })
  } else if (order.status === 10) {
    actions.push({ key: 'logistics', label: '查看物流', primary: false })
    actions.push({ key: 'confirm', label: '确认收货', primary: true })
  } else if (order.status === 20) {
    actions.push({ key: 'refund', label: '申请售后', primary: false })
    actions.push({ key: 'review', label: '评价', primary: false })
    actions.push({ key: 'rebuy', label: '再次购买', primary: true })
  } else if (order.status === 30 || order.status === 40) {
    actions.push({ key: 'review', label: '去评价', primary: false })
    actions.push({ key: 'rebuy', label: '再次购买', primary: true })
  }
  return actions
}

async function fetchOrders(reset = false) {
  if (!isLoggedIn()) return

  if (reset) {
    page.value = 1
    noMore.value = false
    loading.value = true
  } else {
    if (noMore.value) return
    loadingMore.value = true
  }

  try {
    const params = { page: page.value, limit }
    const statuses = currentStatuses.value
    if (statuses.length === 1) {
      params.status = statuses[0]
    }

    const data = await getOrderList(params)
    const list = Array.isArray(data?.list)
      ? data.list
      : (Array.isArray(data) ? data : [])

    if (statuses.length > 1) {
      const filtered = list.filter((o) => statuses.includes(o.status))
      orderList.value = reset ? filtered : [...orderList.value, ...filtered]
    } else {
      orderList.value = reset ? list : [...orderList.value, ...list]
    }

    if (list.length < limit) {
      noMore.value = true
    } else {
      page.value += 1
    }
  } catch {
    if (reset) orderList.value = []
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function switchTab(key) {
  if (currentTab.value === key) return
  currentTab.value = key
  fetchOrders(true)
}

function onSearch() {
  // Placeholder for search navigation
}

function onMore() {
  // Placeholder for more options
}

async function handleAction(key, order) {
  if (key === 'cancel') {
    uni.showModal({
      title: '提示',
      content: '确定要取消该订单吗？',
      success: async (res) => {
        if (!res.confirm) return
        try {
          await cancelOrder(order.id)
          uni.showToast({ title: '已取消', icon: 'none' })
          fetchOrders(true)
        } catch { /* handled */ }
      },
    })
  } else if (key === 'pay') {
    try {
      await payOrder(order.sn)
      uni.navigateTo({
        url: `/pages-sub/order/pay-result?sn=${order.sn}&order_id=${order.id}&status=success`,
      })
    } catch {
      uni.navigateTo({
        url: `/pages-sub/order/pay-result?sn=${order.sn}&order_id=${order.id}&status=fail`,
      })
    }
  } else if (key === 'confirm') {
    uni.showModal({
      title: '提示',
      content: '确认已收到商品？',
      success: async (res) => {
        if (!res.confirm) return
        try {
          await confirmReceive(order.id)
          uni.showToast({ title: '已确认收货', icon: 'none' })
          fetchOrders(true)
        } catch { /* handled */ }
      },
    })
  } else if (key === 'review') {
    uni.navigateTo({ url: `/pages-sub/review/post?order_id=${order.id}` })
  } else if (key === 'logistics') {
    uni.navigateTo({ url: `/pages-sub/order/logistics?order_id=${order.id}` })
  } else if (key === 'refund') {
    uni.navigateTo({ url: `/pages-sub/order/refund?order_id=${order.id}` })
  } else if (key === 'rebuy') {
    // Re-add items to cart
    uni.showToast({ title: '已加入购物车', icon: 'none' })
  }
}

function goDetail(id) {
  uni.navigateTo({ url: `/pages-sub/order/detail?id=${id}` })
}

function goShopping() {
  uni.switchTab({ url: '/pages/index/index' })
}

onShow(() => {
  const initialTab = uni.getStorageSync('order_initial_tab')
  if (initialTab) {
    uni.removeStorageSync('order_initial_tab')
    if (tabs.some((t) => t.key === initialTab) && currentTab.value !== initialTab) {
      currentTab.value = initialTab
    }
  }
  fetchOrders(true)
})

onReachBottom(() => {
  fetchOrders(false)
})
</script>

<style lang="scss" scoped>
/* ============================================================
   Page shell
   ============================================================ */
.page {
  min-height: 100vh;
  background: $mb-color-bg-secondary;
}

/* --- Fixed navbar ------------------------------------------ */
.page__navbar {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 100;
  background: $mb-color-bg;
}

.page__nav-content {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 88rpx;
  padding: 0 $mb-spacing-lg;
}

.page__nav-title {
  font-size: $mb-font-lg;
  font-weight: 700;
  color: $mb-color-text-title;
}

.page__nav-icons {
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
}

.page__nav-icon {
  font-size: 40rpx;
  color: $mb-color-text;
  line-height: 1;
}

.page__nav-icon--dots {
  font-size: 36rpx;
  letter-spacing: -2rpx;
}

/* --- Fixed tab bar ----------------------------------------- */
.page__tabs-wrap {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 99;
  background: $mb-color-bg;
}

.page__tabs-nav {
  height: 88rpx; // navbar content height spacer
}

.page__tabs {
  display: flex;
  height: 88rpx;
  align-items: stretch;
  padding: 0 $mb-spacing-sm;
  border-bottom: 1rpx solid $mb-color-divider;
}

.page__tab {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  position: relative;
}

.page__tab-label {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  line-height: 1;
  transition: color 0.2s;
}

.page__tab--active .page__tab-label {
  color: $mb-color-text;
  font-weight: 600;
}

.page__tab-indicator {
  position: absolute;
  bottom: 0;
  width: 48rpx;
  height: 4rpx;
  background: $mb-color-text;
  border-radius: 2rpx;
}

/* --- Placeholder ------------------------------------------- */
.page__placeholder {
  flex-shrink: 0;
}

/* --- Loading ----------------------------------------------- */
.page__loading {
  padding: $mb-spacing-md;
  display: flex;
  flex-direction: column;
  gap: $mb-spacing-md;
}

/* --- List container ---------------------------------------- */
.page__list {
  padding: $mb-spacing-md;
  padding-bottom: 120rpx;
  display: flex;
  flex-direction: column;
  gap: $mb-spacing-md;
}

.page__load-more {
  padding: $mb-spacing-md 0;
  display: flex;
  justify-content: center;
}

.page__load-text {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

/* ============================================================
   Order card
   ============================================================ */
.order-card {
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg;
  box-shadow: 0 2rpx 12rpx $mb-color-bg-surface;
}

/* --- Header: order number + status tag --------------------- */
.order-card__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: $mb-spacing-md;
}

.order-card__sn {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

.order-card__status-tag {
  display: inline-flex;
  align-items: center;
}

.order-card__status-text {
  font-size: $mb-font-sm;
  font-weight: 600;
}

/* Status theme variants */
.order-card__status--primary {
  color: $mb-color-primary;
}

.order-card__status--warning {
  color: $mb-color-warning;
}

.order-card__status--success {
  color: $mb-color-success;
}

.order-card__status--muted {
  color: $mb-color-text-tertiary;
}

/* --- Product item row -------------------------------------- */
.order-card__item {
  display: flex;
  gap: $mb-spacing-md;
  padding: $mb-spacing-sm 0;

  & + & {
    border-top: 1rpx solid $mb-color-divider;
  }
}

.order-card__img {
  width: 160rpx;
  height: 160rpx;
  border-radius: $mb-radius-md;
  background: #2c2c2e; // dark placeholder matching design
  flex-shrink: 0;
}

.order-card__info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: $mb-spacing-xs;
  overflow: hidden;
}

.order-card__name {
  font-size: 26rpx;
  font-weight: 500;
  color: $mb-color-text;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.4;
}

.order-card__spec {
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
  line-height: 1.4;
}

.order-card__price-row {
  display: flex;
  align-items: baseline;
  gap: $mb-spacing-xs;
  margin-top: auto;
}

.order-card__qty {
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
}

/* --- Footer: total row ------------------------------------- */
.order-card__footer {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: $mb-spacing-xs;
  padding-top: $mb-spacing-sm;
  margin-top: $mb-spacing-xs;
  border-top: 1rpx solid $mb-color-divider;
}

.order-card__total {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
}

/* --- Action buttons ---------------------------------------- */
.order-card__actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: $mb-spacing-sm;
  margin-top: $mb-spacing-md;
}

.order-card__btn {
  padding: 14rpx 36rpx;
  border-radius: $mb-radius-full;
  border: 2rpx solid $mb-color-border;
  background: transparent;

  &:active {
    opacity: 0.7;
  }
}

.order-card__btn--primary {
  background: $mb-color-text;
  border-color: $mb-color-text;
}

.order-card__btn-text {
  font-size: $mb-font-sm;
  font-weight: 500;
  color: $mb-color-text;
  line-height: 1;
}

.order-card__btn-text--primary {
  color: $mb-color-text-inverse;
}
</style>
