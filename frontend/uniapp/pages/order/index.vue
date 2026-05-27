<template>
  <view class="page">
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
          <view class="order-card__store">
            <view class="order-card__store-icon" />
            <text class="order-card__store-name">{{ getStoreName(order) }}</text>
          </view>
          <text class="order-card__status-text" :class="statusTagClass(order.status)">
            {{ statusLabel(order.status) }}
          </text>
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
  </view>
</template>

<script setup>
import { ref, computed } from 'vue'
import { onShow, onReachBottom } from '@dcloudio/uni-app'
import { getOrderList, cancelOrder, confirmReceive } from '@/api/order/order'
import { usePayFlow } from '@/utils/usePayFlow'
import config from '@/config/index'
import { isLoggedIn } from '@/utils/auth'

const {
  sheetVisible,
  methods: payMethods,
  loading: payLoading,
  startPay,
  invokePay,
  closeSheet,
} = usePayFlow()

const pendingPayOrder = ref(null)

function redirectToPayResult(order, payResult) {
  if (!order) return
  const status = payResult.status === 'success'
    ? 'success'
    : payResult.status === 'pending'
      ? 'pending'
      : 'fail'
  uni.navigateTo({
    url: `/pages-sub/order/pay-result?sn=${order.sn}&order_id=${order.id}&status=${status}`,
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

function getRefundItemLabel(item) {
  const name = item?.goods_name || item?.name || '商品'
  const spec = getItemSpec(item)
  return spec ? `${name} ${spec}` : name
}

function navigateToRefund(order, item) {
  const orderItemId = getOrderItemId(item)
  if (!orderItemId) {
    uni.showToast({ title: '请选择要申请售后的商品', icon: 'none' })
    return
  }
  const query = [
    `order_id=${order.id}`,
    `order_item_id=${orderItemId}`,
    item?.goods_name ? `goods_name=${encodeURIComponent(item.goods_name)}` : '',
    getOrderItemImage(item) ? `goods_image=${encodeURIComponent(getOrderItemImage(item))}` : '',
    getItemSpec(item) ? `sku_spec_text=${encodeURIComponent(getItemSpec(item))}` : '',
    item?.unit_price ? `price=${encodeURIComponent(item.unit_price)}` : '',
    item?.quantity ? `quantity=${encodeURIComponent(item.quantity)}` : '',
  ].filter(Boolean).join('&')
  uni.navigateTo({ url: `/pages-sub/refund/apply?${query}` })
}

function getStoreName(order) {
  return order?.store_name || order?.shop_name || 'Mall Official Store'
}

function getOrderQuantity(order) {
  const items = getOrderItems(order)
  if (Number(order?.total_quantity) > 0) return Number(order.total_quantity)
  return items.reduce((sum, item) => sum + Number(item.quantity || 0), 0) || items.length
}

function getActions(order) {
  const actions = []
  if (order.status === 0) {
    actions.push({ key: 'cancel', label: '取消订单', primary: false })
    actions.push({ key: 'pay', label: '去付款', primary: true })
  } else if (order.status === 10) {
    actions.push({ key: 'refund', label: '申请售后', primary: false })
  } else if (order.status === 20) {
    actions.push({ key: 'logistics', label: '查看物流', primary: false })
    actions.push({ key: 'confirm', label: '确认收货', primary: true })
  } else if (order.status === 30 || order.status === 40) {
    actions.push({ key: 'refund', label: '申请售后', primary: false })
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
    pendingPayOrder.value = order
    const payResult = await startPay(order.id)
    if (payResult) redirectToPayResult(order, payResult)
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
    uni.navigateTo({ url: `/pages-sub/logistics/detail?order_id=${order.id}` })
  } else if (key === 'refund') {
    const items = getOrderItems(order)
    if (items.length === 0) {
      uni.showToast({ title: '请选择要申请售后的商品', icon: 'none' })
      return
    }
    if (items.length === 1) {
      navigateToRefund(order, items[0])
      return
    }
    uni.showActionSheet({
      itemList: items.map(getRefundItemLabel),
      success(res) {
        navigateToRefund(order, items[res.tapIndex])
      },
    })
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
  background: #faf8ff;
}

.page__tabs {
  display: flex;
  height: 88rpx;
  align-items: stretch;
  padding: 0 $mb-spacing-sm;
  border-bottom: 1rpx solid rgba(25, 27, 35, 0.06);
  background: $mb-color-bg;
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
  color: $mb-color-primary;
  font-weight: 600;
}

.page__tab-indicator {
  position: absolute;
  bottom: 0;
  width: 48rpx;
  height: 4rpx;
  background: $mb-color-primary;
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
  color: $mb-color-text-tertiary;
}

/* ============================================================
   Order card
   ============================================================ */
.order-card {
  background: $mb-color-bg;
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
  color: $mb-color-text-title;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.order-card__status-text {
  flex-shrink: 0;
  font-size: $mb-font-sm;
  font-weight: 600;
}

/* Status theme variants */
.order-card__status--primary {
  color: $mb-color-primary;
}

.order-card__status--warning {
  color: $mb-color-primary;
}

.order-card__status--success {
  color: $mb-color-text-tertiary;
}

.order-card__status--muted {
  color: $mb-color-text-tertiary;
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
  background: linear-gradient(135deg, rgba(13, 80, 213, 0.14), rgba(25, 27, 35, 0.06));
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
  color: $mb-color-text-title;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.4;
}

.order-card__spec {
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
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
  color: $mb-color-text-tertiary;
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
  color: $mb-color-text-secondary;
}

/* --- Action buttons ---------------------------------------- */
.order-card__actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 14rpx;
  margin-top: 18rpx;
}

.order-card__btn {
  min-width: 132rpx;
  height: 58rpx;
  padding: 0 24rpx;
  border-radius: 18rpx;
  border: 1rpx solid rgba(13, 80, 213, 0.45);
  background: $mb-color-bg;
  display: flex;
  align-items: center;
  justify-content: center;

  &:active {
    opacity: 0.7;
  }
}

.order-card__btn--primary {
  background: $mb-color-primary;
  border-color: $mb-color-primary;
}

.order-card__btn-text {
  font-size: $mb-font-sm;
  font-weight: 500;
  color: $mb-color-primary;
  line-height: 1;
}

.order-card__btn-text--primary {
  color: $mb-color-text-inverse;
}
</style>
