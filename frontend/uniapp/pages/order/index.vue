<template>
  <view
    class="page"
    :class="[
      `theme-${decorateStore.resolvedThemeMode}`,
      { 'page--custom-tabbar': decorateStore.tabbarMode === 'custom' },
    ]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="我的订单" :back="false" :accent-line="false" />

    <!-- Tabs -->
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
        <!-- Card header: store + status -->
        <view class="order-card__header">
          <view v-if="getStoreName(order)" class="order-card__store">
            <view class="order-card__store-icon" />
            <text class="order-card__store-name">{{ getStoreName(order) }}</text>
          </view>
          <text v-else-if="order.sn" class="order-card__sn">订单号 {{ order.sn }}</text>
          <view v-else class="order-card__header-spacer" />
          <text class="order-card__status-text" :class="statusTagClass(order)">
            {{ statusLabel(order) }}
          </text>
        </view>
        <text v-if="order.status === 0 && getCountdownText(order)" class="order-card__countdown">
          剩余 {{ getCountdownText(order) }}
        </text>
        <view v-if="order.after_sale_tag_text" class="order-card__after-sale">
          <view class="order-card__after-sale-dot" />
          <text class="order-card__after-sale-text">售后{{ order.after_sale_tag_text }}</text>
          <text class="order-card__after-sale-tip">{{ afterSaleTip(order) }}</text>
        </view>

        <!-- Product items -->
        <view
          v-for="item in getOrderItems(order)"
          :key="item.id"
          class="order-card__item"
        >
          <image
            v-if="getOrderItemImage(item)"
            class="order-card__img"
            :src="getOrderItemImage(item)"
            mode="aspectFill"
          />
          <view v-else class="order-card__img order-card__img--placeholder">
            <view class="order-card__placeholder-box" />
          </view>
          <view class="order-card__info">
            <text class="order-card__name">{{ item.goods_name || item.name || '商品信息' }}</text>
            <text v-if="getItemSpec(item)" class="order-card__spec">{{ getItemSpec(item) }}</text>
            <view class="order-card__price-row">
              <mb-price :value="item.unit_price" size="sm" />
              <text class="order-card__qty">x{{ item.quantity || 1 }}</text>
            </view>
          </view>
        </view>

        <!-- Footer: total summary -->
        <view class="order-card__footer">
          <text class="order-card__total">
            共{{ getOrderQuantity(order) }}件商品 实付款
          </text>
          <mb-price
            :value="order.pay_amount || order.total_amount"
            size="md"
            color="var(--color-text-title, #191b23)"
          />
        </view>

        <!-- Action buttons -->
        <view
          v-if="getActions(order).length > 0"
          class="order-card__actions"
          @tap.stop
        >
          <mb-button
            v-for="act in getActions(order)"
            :key="act.key"
            class="order-card__action-button"
            :type="act.primary ? 'primary' : 'secondary'"
            size="small"
            :label="act.label"
            @click="handleAction(act.key, order)"
          />
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
      icon=""
      :text="'暂无' + currentTabLabel + '订单'"
      actionText="去逛逛"
      @action="goShopping"
    />

    <!-- 支付方式选择 -->
    <mb-pay-method-sheet
      :visible="sheetVisible"
      :methods="payMethods"
      :loading="payLoading"
      :amount="pendingPayOrder ? (pendingPayOrder.pay_amount || pendingPayOrder.total_amount) : ''"
      @select="onPayMethodSelect"
      @close="closeSheet"
    />

    <!-- 售后商品选择 -->
    <mb-refund-item-sheet
      :visible="refundSheetVisible"
      :items="refundSheetItems"
      @confirm="onRefundItemsConfirm"
      @close="closeRefundSheet"
    />

    <mb-custom-tabbar current="/pages/order/index" />
      <mb-floating-action />
</view>
</template>

<script setup>
import { ref, computed, onUnmounted } from 'vue'
import { onShow, onHide, onReachBottom } from '@dcloudio/uni-app'
import { getOrderList, cancelOrder, confirmReceive } from '@/api/order/order'
import { usePayFlow } from '@/utils/usePayFlow'
import config from '@/config/index'
import { isLoggedIn } from '@/utils/auth'
import { useDecorateStore } from '@/store/decorate'

const decorateStore = useDecorateStore()
const {
  sheetVisible,
  methods: payMethods,
  loading: payLoading,
  startPay,
  invokePay,
  closeSheet,
} = usePayFlow()

const pendingPayOrder = ref(null)
const refundSheetVisible = ref(false)
const refundSheetOrder = ref(null)
const refundSheetItems = ref([])

function redirectToPayResult(order, payResult) {
  if (!order) return
  const status = payResult.status === 'success'
    ? 'success'
    : payResult.status === 'pending'
      ? 'pending'
      : 'fail'
  const query = [
    `sn=${encodeURIComponent(order.sn || '')}`,
    `order_id=${encodeURIComponent(order.id || '')}`,
    `status=${status}`,
  ]
  const message = String(payResult.message || '').trim()
  if (message) {
    query.push(`message=${encodeURIComponent(message.slice(0, 160))}`)
  }
  uni.navigateTo({
    url: `/pages-sub/order/pay-result?${query.join('&')}`,
  })
}

async function onPayMethodSelect(code) {
  const order = pendingPayOrder.value
  if (!order) return
  const payResult = await invokePay(code)
  if (payResult) redirectToPayResult(order, payResult)
}

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
const nowTs = ref(Date.now())
let countdownTimer = null
let lastExpiredRefreshAt = 0

const currentTabLabel = computed(() => {
  const t = tabs.find((t) => t.key === currentTab.value)
  return t && t.key !== 'all' ? t.label : ''
})

const currentStatuses = computed(() => {
  const t = tabs.find((t) => t.key === currentTab.value)
  return t ? t.statuses : []
})

function isRefundCompletedOrder(order) {
  return Number(order?.status) === 90 && Number(order?.after_sale?.status) === 10
}

function statusLabel(order) {
  if (isRefundCompletedOrder(order)) return '退款完成'
  return STATUS_MAP[order?.status]?.label || '未知'
}

function statusTagClass(order) {
  const theme = isRefundCompletedOrder(order)
    ? 'success'
    : STATUS_MAP[order?.status]?.theme || 'muted'
  return `order-card__status--${theme}`
}

function normalizeImageUrl(url) {
  if (!url) return ''
  const value = String(url)
  if (/^(https?:)?\/\//.test(value) || value.startsWith('data:') || value.startsWith('blob:')) {
    return value.startsWith('//') ? `https:${value}` : value
  }
  if (value.startsWith('/') && config.baseUrl) {
    return `${config.baseUrl}${value}`
  }
  return value
}

function getOrderItems(order) {
  if (Array.isArray(order?.items)) return order.items
  if (Array.isArray(order?.order_items)) return order.order_items
  return []
}

function getOrderItemImage(item) {
  return normalizeImageUrl(
    item?.goods_image_full_url
      || item?.goods_image_url
      || item?.main_image_full_url
      || item?.image_full_url
      || item?.cover_full_url
      || item?.goods_image
      || item?.main_image
      || item?.cover
      || '',
  )
}

function getItemSpec(item) {
  return item?.sku_spec_text || item?.sku_spec || item?.spec_text || item?.spec || ''
}

function getOrderItemId(item) {
  return item?.id || item?.order_item_id || ''
}

function getRefundableQuantity(item) {
  const explicit = Number(item?.refundable_quantity)
  if (Number.isFinite(explicit)) return Math.max(0, explicit)
  return Math.max(0, Number(item?.quantity || 0) - Number(item?.refunded_quantity || 0))
}

function navigateToRefund(order, selections) {
  const selectedItems = (Array.isArray(selections) ? selections : [])
    .map((row) => {
      const item = row?.item || row
      const orderItemId = row?.order_item_id || getOrderItemId(item)
      const quantity = Math.max(1, Number(row?.quantity || 1))
      return orderItemId ? { order_item_id: orderItemId, quantity } : null
    })
    .filter(Boolean)

  if (selectedItems.length === 0) {
    uni.showToast({ title: '请选择要申请售后的商品', icon: 'none' })
    return
  }

  const query = [
    `order_id=${order.id}`,
    `selected_items=${encodeURIComponent(JSON.stringify(selectedItems))}`,
  ].filter(Boolean).join('&')
  uni.navigateTo({ url: `/pages-sub/refund/apply?${query}` })
}

function openRefundSheet(order, items) {
  refundSheetOrder.value = order
  refundSheetItems.value = items
  refundSheetVisible.value = true
}

function closeRefundSheet() {
  refundSheetVisible.value = false
  refundSheetOrder.value = null
  refundSheetItems.value = []
}

function onRefundItemsConfirm(selections) {
  const order = refundSheetOrder.value
  closeRefundSheet()
  if (!order) return
  navigateToRefund(order, selections)
}

function getStoreName(order) {
  return String(order?.store_name || order?.shop_name || order?.merchant_name || '').trim()
}

function getOrderQuantity(order) {
  const items = getOrderItems(order)
  if (Number(order?.total_quantity) > 0) return Number(order.total_quantity)
  return items.reduce((sum, item) => sum + Number(item.quantity || 0), 0) || items.length
}

function getExpireAtTs(order) {
  const expireAt = order?.expire_at || ''
  if (!expireAt) return 0
  const ts = Date.parse(String(expireAt).replace(/-/g, '/'))
  return Number.isFinite(ts) ? ts : 0
}

function getRemainingSeconds(order) {
  const expireAtTs = getExpireAtTs(order)
  if (!expireAtTs) return 0
  return Math.max(0, Math.floor((expireAtTs - nowTs.value) / 1000))
}

function isPendingPayExpired(order) {
  return Number(order?.status) === 0 && getExpireAtTs(order) > 0 && getRemainingSeconds(order) <= 0
}

function getCountdownText(order) {
  const seconds = getRemainingSeconds(order)
  if (seconds <= 0) return ''
  const mm = String(Math.floor(seconds / 60)).padStart(2, '0')
  const ss = String(seconds % 60).padStart(2, '0')
  return `${mm}:${ss}`
}

function canApplyRefund(order) {
  return order?.can_refund !== false
}

function isActiveAfterSaleStatus(status) {
  return [0, 1, 2].includes(Number(status))
}

function hasActiveAfterSale(order) {
  return isActiveAfterSaleStatus(order?.after_sale?.status)
}

function afterSaleTip(order) {
  if (isRefundCompletedOrder(order)) {
    return '退款已完成，订单因全额退款结束'
  }
  return '处理期间暂不可重复申请或确认收货'
}

function getActions(order) {
  const actions = []
  if (order.status === 0) {
    if (!isPendingPayExpired(order)) {
      actions.push({ key: 'cancel', label: '取消订单', primary: false })
      actions.push({ key: 'pay', label: '去付款', primary: true })
    }
  } else if (order.status === 10) {
    if (canApplyRefund(order)) {
      actions.push({ key: 'refund', label: '申请售后', primary: false })
    }
  } else if (order.status === 20) {
    if (canApplyRefund(order)) {
      actions.push({ key: 'refund', label: '申请售后', primary: false })
    }
    actions.push({ key: 'logistics', label: '查看物流', primary: false })
    if (!hasActiveAfterSale(order)) {
      actions.push({ key: 'confirm', label: '确认收货', primary: true })
    }
  } else if (order.status === 30 || order.status === 40) {
    if (canApplyRefund(order)) {
      actions.push({ key: 'refund', label: '申请售后', primary: false })
    }
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
    if (isPendingPayExpired(order)) {
      uni.showToast({ title: '订单已超时，请重新下单', icon: 'none' })
      fetchOrders(true)
      return
    }
    pendingPayOrder.value = order
    const payResult = await startPay(order.id)
    if (payResult) redirectToPayResult(order, payResult)
  } else if (key === 'confirm') {
    if (hasActiveAfterSale(order)) {
      uni.showToast({ title: '售后处理中，暂不能确认收货', icon: 'none' })
      return
    }
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
    uni.navigateTo({ url: `/pages-sub/logistics/detail?order_id=${order.id}` })
  } else if (key === 'refund') {
    if (!canApplyRefund(order)) {
      uni.showToast({
        title: hasActiveAfterSale(order) ? '剩余商品暂无可申请售后' : '订单已超过售后申请期限',
        icon: 'none',
      })
      return
    }
    const items = getOrderItems(order)
    if (items.length === 0) {
      uni.showToast({ title: '请选择要申请售后的商品', icon: 'none' })
      return
    }
    openRefundSheet(order, items)
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

function startCountdownTimer() {
  if (countdownTimer) return
  countdownTimer = setInterval(() => {
    nowTs.value = Date.now()
    if (orderList.value.some(isPendingPayExpired) && Date.now() - lastExpiredRefreshAt > 5000) {
      lastExpiredRefreshAt = Date.now()
      fetchOrders(true)
    }
  }, 1000)
}

function stopCountdownTimer() {
  if (!countdownTimer) return
  clearInterval(countdownTimer)
  countdownTimer = null
}

onShow(() => {
  nowTs.value = Date.now()
  startCountdownTimer()
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

onHide(() => {
  stopCountdownTimer()
})

onUnmounted(() => {
  stopCountdownTimer()
})
</script>

<style lang="scss" scoped>
/* ============================================================
   Page shell
   ============================================================ */
.page {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

.page__tabs {
  display: flex;
  height: 88rpx;
  align-items: stretch;
  padding: 0 $mb-spacing-sm;
  border-bottom: 1rpx solid rgba(25, 27, 35, 0.06);
  background: var(--color-bg, #ffffff);
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
  color: var(--color-text-secondary, #434654);
  line-height: 1;
  transition: color 0.2s;
}

.page__tab--active .page__tab-label {
  color: var(--color-primary, #0d50d5);
  font-weight: 600;
}

.page__tab-indicator {
  position: absolute;
  bottom: 0;
  width: 48rpx;
  height: 4rpx;
  background: var(--color-primary, #0d50d5);
  border-radius: 2rpx;
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
  padding: 18rpx $mb-spacing-md;
  padding-bottom: 120rpx;
  display: flex;
  flex-direction: column;
  gap: 18rpx;
}

.page__load-more {
  padding: $mb-spacing-md 0;
  display: flex;
  justify-content: center;
}

.page__load-text {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}

/* ============================================================
   Order card
   ============================================================ */
.order-card {
  background: var(--color-bg, #ffffff);
  border-radius: 16rpx;
  padding: 18rpx;
  border: 1rpx solid rgba(25, 27, 35, 0.06);
}

/* --- Header: order number + status tag --------------------- */
.order-card__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: $mb-spacing-sm;
  margin-bottom: 18rpx;
}

.order-card__store {
  display: flex;
  align-items: center;
  gap: 10rpx;
  min-width: 0;
}

.order-card__store-icon {
  position: relative;
  flex-shrink: 0;
  width: 26rpx;
  height: 24rpx;
  border: 2rpx solid #1f2430;
  border-radius: 4rpx;
  box-sizing: border-box;
}

.order-card__store-icon::before {
  content: '';
  position: absolute;
  left: 4rpx;
  right: 4rpx;
  top: -8rpx;
  height: 8rpx;
  border: 2rpx solid #1f2430;
  border-bottom: 0;
  border-radius: 6rpx 6rpx 0 0;
}

.order-card__store-name {
  flex: 1;
  min-width: 0;
  font-size: $mb-font-sm;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.order-card__sn {
  flex: 1;
  min-width: 0;
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.order-card__header-spacer {
  flex: 1;
  min-width: 0;
}

.order-card__status-text {
  flex-shrink: 0;
  font-size: $mb-font-sm;
  font-weight: 600;
}

/* Status theme variants */
.order-card__status--primary {
  color: var(--color-primary, #0d50d5);
}

.order-card__status--warning {
  color: var(--color-primary, #0d50d5);
}

.order-card__status--success {
  color: var(--color-text-tertiary, #737686);
}

.order-card__status--muted {
  color: var(--color-text-tertiary, #737686);
}

.order-card__countdown {
  display: block;
  margin: -8rpx 0 14rpx;
  font-size: $mb-font-xs;
  color: var(--color-primary, #0d50d5);
}

.order-card__after-sale {
  display: flex;
  align-items: center;
  gap: 10rpx;
  padding: 14rpx 16rpx;
  margin: -4rpx 0 14rpx;
  border-radius: 12rpx;
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.06));
  border: 1rpx solid var(--color-primary-border, rgba(13, 80, 213, 0.12));
}

.order-card__after-sale-dot {
  width: 10rpx;
  height: 10rpx;
  border-radius: 50%;
  background: var(--color-primary, #0d50d5);
  flex-shrink: 0;
}

.order-card__after-sale-text {
  font-size: $mb-font-sm;
  font-weight: 700;
  color: var(--color-primary, #0d50d5);
  flex-shrink: 0;
}

.order-card__after-sale-tip {
  min-width: 0;
  flex: 1;
  font-size: $mb-font-xs;
  color: var(--color-text-secondary, #434654);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* --- Product item row -------------------------------------- */
.order-card__item {
  display: flex;
  gap: 18rpx;
  padding: 10rpx 0 16rpx;

  & + & {
    border-top: 1rpx solid rgba(25, 27, 35, 0.06);
  }
}

.order-card__img {
  width: 144rpx;
  height: 144rpx;
  border-radius: 10rpx;
  background: #f3f5f9;
  flex-shrink: 0;
}

.order-card__img--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.order-card__placeholder-box {
  width: 54rpx;
  height: 38rpx;
  border-radius: 8rpx;
  background: linear-gradient(
    135deg,
    var(--color-primary-soft, rgba(13, 80, 213, 0.14)),
    var(--color-divider, rgba(25, 27, 35, 0.06))
  );
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
  font-size: 27rpx;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.4;
}

.order-card__spec {
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.order-card__price-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: $mb-spacing-xs;
  margin-top: auto;
}

.order-card__qty {
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
}

/* --- Footer: total row ------------------------------------- */
.order-card__footer {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: $mb-spacing-xs;
  padding-top: 14rpx;
  margin-top: 0;
  border-top: 1rpx solid rgba(25, 27, 35, 0.06);
}

.order-card__total {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
}

/* --- Action buttons ---------------------------------------- */
.order-card__actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 14rpx;
  margin-top: 18rpx;
}

.order-card__action-button {
  min-width: 132rpx;
}
</style>
