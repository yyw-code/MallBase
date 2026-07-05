<template>
  <view
    class="detail-page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="订单详情" />

    <!-- Loading skeleton -->
    <view v-if="loading" class="detail-page__loading">
      <view class="card">
        <mb-skeleton type="lines" :count="2" />
      </view>
      <view class="card">
        <mb-skeleton type="avatar-lines" />
      </view>
      <view class="card">
        <mb-skeleton type="card" />
      </view>
      <view class="card">
        <mb-skeleton type="lines" :count="4" />
      </view>
    </view>

    <!-- Error / empty -->
    <mb-empty-state
      v-else-if="!order"
      text="订单不存在"
      action-text="返回订单列表"
      @action="goBack"
    />

    <!-- Main content -->
    <template v-else>
      <!-- Status section -->
      <view class="status-section">
        <view class="status-section__content">
          <text class="status-section__title">{{ statusText }}</text>
          <text class="status-section__desc">{{ statusDesc }}</text>
        </view>
        <view class="status-section__icon">
          <view class="status-truck">
            <view class="status-truck__body" />
            <view class="status-truck__cab" />
            <view class="status-truck__wheel status-truck__wheel--front" />
            <view class="status-truck__wheel status-truck__wheel--rear" />
          </view>
        </view>
      </view>

      <!-- Logistics preview (when shipped) -->
      <view v-if="order.status === 20 && (isVirtualDelivery(order) || order.logistics_info)" class="logistics-preview">
        <view class="logistics-preview__icon">
          <view class="truck-icon">
            <view class="truck-icon__body" />
            <view class="truck-icon__cab" />
            <view class="truck-icon__wheel truck-icon__wheel--front" />
            <view class="truck-icon__wheel truck-icon__wheel--rear" />
          </view>
        </view>
        <view class="logistics-preview__content">
          <text class="logistics-preview__text">
            {{ isVirtualDelivery(order) ? (order.delivery_note || '虚拟商品已发货') : (order.logistics_info.latest_desc || '物流信息加载中') }}
          </text>
          <text class="logistics-preview__time">
            {{ isVirtualDelivery(order) ? (order.shipped_at || '') : (order.logistics_info.latest_time || '') }}
          </text>
        </view>
      </view>

      <!-- After-sale preview -->
      <view v-if="order.after_sale" class="after-sale-card" @tap="goRefundDetail">
        <view class="after-sale-card__left">
          <view v-if="afterSalePreviewItems.length > 0" class="after-sale-card__images">
            <view
              v-for="(item, index) in afterSalePreviewItems"
              :key="item.id || item.order_item_id || index"
              class="after-sale-card__image-wrap"
              :style="getAfterSaleThumbStyle(index)"
            >
              <image
                v-if="getAfterSaleItemImage(item)"
                class="after-sale-card__image"
                :src="getAfterSaleItemImage(item)"
                mode="aspectFill"
                lazy-load
              />
              <view v-else class="after-sale-card__image after-sale-card__image--placeholder" />
            </view>
            <view v-if="afterSaleMoreCount > 0" class="after-sale-card__more">
              <text class="after-sale-card__more-text">+{{ afterSaleMoreCount }}</text>
            </view>
          </view>
          <view v-else class="after-sale-card__icon">
            <view class="after-sale-card__icon-dot" />
          </view>
          <view class="after-sale-card__content">
            <view class="after-sale-card__title-row">
              <text class="after-sale-card__title">售后{{ order.after_sale.status_text || order.after_sale_tag_text }}</text>
              <text class="after-sale-card__type">{{ getAfterSaleBadgeText(order.after_sale) }}</text>
            </view>
            <text v-if="getAfterSaleGoodsText(order.after_sale)" class="after-sale-card__goods">
              {{ getAfterSaleGoodsText(order.after_sale) }}
            </text>
            <text class="after-sale-card__desc">{{ getAfterSaleDesc(order.after_sale) }}</text>
          </view>
        </view>
        <text class="after-sale-card__arrow">{{ getAfterSaleActionText(order.after_sale) }}</text>
      </view>

      <!-- Address card -->
      <view class="card address-card">
        <view class="address-card__icon">
          <view class="pin">
            <view class="pin__head" />
            <view class="pin__body" />
          </view>
        </view>
        <view class="address-card__info">
          <view class="address-card__top">
            <text class="address-card__name">{{ receiverName }}</text>
            <text class="address-card__phone">{{ maskPhone(receiverPhone) }}</text>
          </view>
          <text class="address-card__detail">{{ fullAddress }}</text>
        </view>
      </view>

      <!-- Goods list -->
      <view class="card goods-card">
        <view
          v-for="item in orderItems"
          :key="item.id"
          class="goods-item"
        >
          <image
            v-if="getOrderItemImage(item)"
            class="goods-item__img"
            :src="getOrderItemImage(item)"
            mode="aspectFill"
            lazy-load
          />
          <view v-else class="goods-item__img goods-item__img--placeholder">
            <view class="goods-item__placeholder-box" />
          </view>
          <view class="goods-item__info">
            <text class="goods-item__name">{{ item.goods_name || item.name || '商品信息' }}</text>
            <text v-if="getItemSpec(item)" class="goods-item__spec">{{ getItemSpec(item) }}</text>
            <view class="goods-item__bottom">
              <mb-price :value="item.unit_price" size="sm" color="var(--color-text-title)" />
              <text class="goods-item__qty">x{{ item.quantity || 1 }}</text>
            </view>
            <view v-if="getGoodsAfterSaleTag(item)" class="goods-item__after-sale">
              <text class="goods-item__after-sale-text">{{ getGoodsAfterSaleTag(item) }}</text>
              <text v-if="getGoodsAfterSaleAmount(item)" class="goods-item__after-sale-amount">
                退款 ¥{{ getGoodsAfterSaleAmount(item) }}
              </text>
            </view>
          </view>
        </view>
      </view>

      <!-- Buyer remark -->
      <view v-if="order.buyer_remark" class="card remark-card">
        <text class="remark-card__label">买家留言</text>
        <text class="remark-card__value">{{ order.buyer_remark }}</text>
      </view>

      <!-- Price summary -->
      <view class="card summary-card">
        <view class="summary-row">
          <text class="summary-row__label">商品总额</text>
          <mb-price :value="goodsTotal" size="sm" color="var(--color-text)" />
        </view>
        <view class="summary-row">
          <text class="summary-row__label">运费</text>
          <mb-price :value="order.shipping_fee || order.freight_amount || 0" size="sm" color="var(--color-text)" />
        </view>
        <view class="summary-row">
          <text class="summary-row__label">优惠减免</text>
          <text class="summary-row__discount">-¥{{ order.discount_amount || '0.00' }}</text>
        </view>
        <view class="summary-divider" />
        <view class="summary-row summary-row--total">
          <text class="summary-row__label">合计</text>
          <mb-price :value="order.pay_amount || order.total_amount" size="md" color="var(--color-text-title)" />
        </view>
      </view>

      <!-- Order info -->
      <view class="card info-card">
        <view class="info-row">
          <text class="info-row__label">订单编号</text>
          <view class="info-row__value-wrap">
            <text class="info-row__value">{{ order.sn }}</text>
            <view class="info-row__copy" @tap="copySn">
              <!-- CSS clipboard icon -->
              <view class="clipboard-icon">
                <view class="clipboard-icon__board" />
                <view class="clipboard-icon__clip" />
              </view>
            </view>
          </view>
        </view>
        <view v-if="order.pay_type_text" class="info-row">
          <text class="info-row__label">支付方式</text>
          <text class="info-row__value">{{ order.pay_type_text }}</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">下单时间</text>
          <text class="info-row__value">{{ order.create_time || '-' }}</text>
        </view>
      </view>

      <!-- Bottom spacer -->
      <mb-copyright-footer />
      <view class="bottom-spacer" />
    </template>

    <!-- Bottom action bar -->
    <view v-if="order" class="action-bar">
      <view class="action-bar__inner">
        <view class="action-bar__tools">
          <view class="action-bar__tool" @tap="openCustomerService">
            <view class="action-bar__icon-service" />
            <text class="action-bar__tool-label">客服</text>
          </view>
        </view>
        <view v-if="actions.length > 0" class="action-bar__actions">
          <mb-button
            v-for="act in actions"
            :key="act.key"
            class="action-bar__button"
            :type="act.primary ? 'primary' : 'secondary'"
            size="medium"
            :label="act.label"
            @click="handleAction(act.key)"
          />
        </view>
      </view>
    </view>

    <!-- 支付方式选择 -->
    <mb-pay-method-sheet
      :visible="sheetVisible"
      :methods="payMethods"
      :loading="payLoading"
      :amount="order ? (order.pay_amount || order.total_amount) : ''"
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
      <mb-floating-action />
</view>
</template>

<script setup>
import { useDecorateStore } from '@/store/decorate'
import { ref, computed, onUnmounted } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getOrderDetail, cancelOrder, confirmReceive } from '@/api/order/order'
import { usePayFlow } from '@/utils/usePayFlow'
import { multiplyPrice, sumPrices } from '@/utils/price'
import { openCustomerService } from '@/utils/customer-service'
import config from '@/config/index'
const decorateStore = useDecorateStore()

const {
  sheetVisible,
  methods: payMethods,
  loading: payLoading,
  startPay,
  invokePay,
  closeSheet,
} = usePayFlow()

function redirectToPayResult(payResult) {
  if (!order.value) return
  const status = payResult.status === 'success'
    ? 'success'
    : payResult.status === 'pending'
      ? 'pending'
      : 'fail'
  const query = [
    `sn=${encodeURIComponent(order.value.sn || '')}`,
    `order_id=${encodeURIComponent(order.value.id || '')}`,
    `status=${status}`,
  ]
  const message = String(payResult.message || '').trim()
  if (message) {
    query.push(`message=${encodeURIComponent(message.slice(0, 160))}`)
  }
  uni.redirectTo({
    url: `/pages-sub/order/pay-result?${query.join('&')}`,
  })
}

async function onPayMethodSelect(code) {
  const payResult = await invokePay(code)
  if (payResult) redirectToPayResult(payResult)
}

const STATUS_MAP = {
  0: { label: '待付款' },
  10: { label: '待发货' },
  20: { label: '待收货' },
  30: { label: '已收货' },
  40: { label: '已完成' },
  90: { label: '已关闭' },
}

const loading = ref(true)
const order = ref(null)
const orderId = ref('')
const nowTs = ref(Date.now())
const refundSheetVisible = ref(false)
const refundSheetItems = ref([])
let countdownTimer = null
let expiredRefreshed = false

onLoad((query) => {
  orderId.value = query?.id || ''
  startCountdownTimer()
  if (orderId.value) {
    fetchDetail(orderId.value)
  } else {
    loading.value = false
  }
})

async function fetchDetail(id) {
  loading.value = true
  try {
    const res = await getOrderDetail(id)
    order.value = res ?? null
  } catch {
    order.value = null
  } finally {
    loading.value = false
  }
}

// --- Computed ---

const statusText = computed(() => {
  if (!order.value) return ''
  if (isRefundCompletedOrder(order.value)) return '退款完成'
  return STATUS_MAP[order.value.status]?.label || '未知'
})

const statusDesc = computed(() => {
  if (!order.value) return ''
  if (isRefundCompletedOrder(order.value)) {
    return '售后退款已完成，订单已结束'
  }
  if (order.value.status === 0) {
    const text = countdownText.value
    return text ? `请在 ${text} 内完成支付` : '订单已超时，正在更新状态'
  }
  const map = {
    10: '商家正在为你处理订单',
    20: '你的包裹正在路上，请保持电话畅通',
    30: '订单已签收，期待你的评价',
    40: '订单已完成，感谢你的购买',
    90: '订单已关闭',
  }
  return map[order.value.status] || '订单状态已更新'
})

const orderItems = computed(() => getOrderItems(order.value))
const afterSalePreviewItems = computed(() => getAfterSaleItems(order.value?.after_sale).slice(0, 3))
const afterSaleMoreCount = computed(() => Math.max(0, getAfterSaleItems(order.value?.after_sale).length - 3))

const receiverName = computed(() => {
  if (!order.value) return ''
  return order.value.receiver_name || order.value.consignee || order.value.name || '收货人'
})

const receiverPhone = computed(() => {
  if (!order.value) return ''
  return order.value.receiver_phone || order.value.receiver_mobile || order.value.mobile || order.value.phone || ''
})

const fullAddress = computed(() => {
  if (!order.value) return ''
  const o = order.value
  const snapshotAddress = [
    o.receiver_province,
    o.receiver_city,
    o.receiver_district,
    o.receiver_address,
  ].filter(Boolean).join(' ')
  const regionAddress = [
    o.region_path_text,
    o.province_name,
    o.city_name,
    o.district_name,
    o.address_detail,
  ].filter(Boolean).join(' ')
  return snapshotAddress || regionAddress || o.address || '暂无收货地址'
})

const goodsTotal = computed(() => {
  if (!orderItems.value.length) return '0.00'
  return sumPrices(orderItems.value.map((item) => multiplyPrice(item.unit_price, item.quantity)))
})

const actions = computed(() => {
  if (!order.value) return []
  const list = []
  if (order.value.status === 0) {
    if (!isPendingPayExpired(order.value)) {
      list.push({ key: 'cancel', label: '取消订单', primary: false })
      list.push({ key: 'pay', label: '去付款', primary: true })
    }
  } else if (order.value.status === 10) {
    if (canApplyRefund(order.value)) {
      list.push({ key: 'refund', label: '申请售后', primary: false })
    }
  } else if (order.value.status === 20) {
    if (canApplyRefund(order.value)) {
      list.push({ key: 'refund', label: '申请售后', primary: false })
    }
    if (!isVirtualDelivery(order.value)) {
      list.push({ key: 'logistics', label: '查看物流', primary: false })
    }
    if (!hasActiveAfterSale(order.value)) {
      list.push({ key: 'confirm', label: '确认收货', primary: true })
    }
  } else if (order.value.status === 30 || order.value.status === 40) {
    if (canApplyRefund(order.value)) {
      list.push({ key: 'refund', label: '申请售后', primary: false })
    }
    list.push({ key: 'review', label: '去评价', primary: false })
  }
  return list
})

// --- Helpers ---

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

function isVirtualDelivery(order) {
  return String(order?.delivery_type || '') === 'virtual'
}

function getOrderItems(source) {
  if (Array.isArray(source?.items)) return source.items
  if (Array.isArray(source?.order_items)) return source.order_items
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

function getAfterSaleItems(refund) {
  return Array.isArray(refund?.items) ? refund.items : []
}

function getAfterSaleItemImage(item) {
  return normalizeImageUrl(
    item?.goods_image_full_url
      || item?.goods_image_url
      || item?.goods_image
      || '',
  )
}

function getAfterSaleThumbStyle(index) {
  return {
    left: `${index * 34}rpx`,
    zIndex: 4 - index,
  }
}

function getAfterSaleBadgeText(refund) {
  const total = Number(refund?.total || getAfterSaleItems(refund).length || 0)
  return total > 1 ? `${total}个售后` : (refund?.type_text || '售后')
}

function getAfterSaleActionText(refund) {
  const total = Number(refund?.total || getAfterSaleItems(refund).length || 0)
  return total > 1 ? '查看全部' : '查看详情'
}

function getAfterSaleGoodsText(refund) {
  const items = getAfterSaleItems(refund)
  if (items.length === 0) return ''
  const firstName = items[0]?.goods_name || '售后商品'
  return items.length > 1 ? `${firstName}等 ${items.length} 件商品` : firstName
}

function getGoodsAfterSaleInfo(item) {
  return item?.after_sale || null
}

function getGoodsAfterSaleTag(item) {
  const refund = getGoodsAfterSaleInfo(item)
  if (!refund) return ''
  return `售后${refund.status_text || '处理中'}`
}

function getGoodsAfterSaleAmount(item) {
  const amount = getGoodsAfterSaleInfo(item)?.refund_amount
  return amount !== undefined && amount !== null && amount !== '' ? String(amount) : ''
}

function getExpireAtTs(source) {
  const expireAt = source?.expire_at || ''
  if (!expireAt) return 0
  const ts = Date.parse(String(expireAt).replace(/-/g, '/'))
  return Number.isFinite(ts) ? ts : 0
}

function getRemainingSeconds(source) {
  const expireAtTs = getExpireAtTs(source)
  if (!expireAtTs) return 0
  return Math.max(0, Math.floor((expireAtTs - nowTs.value) / 1000))
}

function isPendingPayExpired(source) {
  return Number(source?.status) === 0 && getExpireAtTs(source) > 0 && getRemainingSeconds(source) <= 0
}

const countdownText = computed(() => {
  const seconds = getRemainingSeconds(order.value)
  if (seconds <= 0) return ''
  const mm = String(Math.floor(seconds / 60)).padStart(2, '0')
  const ss = String(seconds % 60).padStart(2, '0')
  return `${mm}:${ss}`
})

function canApplyRefund(source) {
  return source?.can_refund !== false
}

function isActiveAfterSaleStatus(status) {
  return [0, 1, 2].includes(Number(status))
}

function hasActiveAfterSale(source) {
  return isActiveAfterSaleStatus(source?.after_sale?.status)
}

function isRefundCompletedOrder(source) {
  return Number(source?.status) === 90 && Number(source?.after_sale?.status) === 10
}

function getAfterSaleDesc(refund) {
  if (!refund) return ''
  const parts = []
  const total = Number(refund?.total || getAfterSaleItems(refund).length || 0)
  const amount = total > 1 ? refund.total_refund_amount : refund.refund_amount
  if (amount) parts.push(`${total > 1 ? '合计退款' : '退款'} ¥${amount}`)
  if (refund.receive_status_text) parts.push(refund.receive_status_text)
  if (
    Number(refund.receive_status) === 0
    && refund.intercept_status_text
    && refund.intercept_status !== 'none'
    && refund.intercept_status_text !== '无需拦截'
  ) {
    parts.push(`物流拦截：${refund.intercept_status_text}`)
  }
  return parts.join('，') || '查看售后处理进度'
}

function goRefundDetail() {
  const refund = order.value?.after_sale
  const total = Number(refund?.total || getAfterSaleItems(refund).length || 0)
  if (total > 1 && order.value?.id) {
    uni.navigateTo({ url: `/pages-sub/refund/list?order_id=${order.value.id}` })
    return
  }
  const id = refund?.id
  if (!id) return
  uni.navigateTo({ url: `/pages-sub/refund/detail?id=${id}` })
}

function navigateToRefund(selections) {
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
    `order_id=${order.value.id}`,
    `selected_items=${encodeURIComponent(JSON.stringify(selectedItems))}`,
  ].filter(Boolean).join('&')
  uni.navigateTo({ url: `/pages-sub/refund/apply?${query}` })
}

function openRefundSheet(items) {
  refundSheetItems.value = items
  refundSheetVisible.value = true
}

function closeRefundSheet() {
  refundSheetVisible.value = false
  refundSheetItems.value = []
}

function onRefundItemsConfirm(selections) {
  closeRefundSheet()
  navigateToRefund(selections)
}

function maskPhone(phone) {
  if (!phone || phone.length < 7) return phone || ''
  return phone.slice(0, 3) + '****' + phone.slice(-4)
}

function copySn() {
  if (!order.value?.sn) return
  uni.setClipboardData({
    data: order.value.sn,
    success() {
      uni.showToast({ title: '已复制', icon: 'success' })
    },
  })
}

// --- Actions ---

async function handleAction(key) {
  if (key === 'cancel') {
    uni.showModal({
      title: '提示',
      content: '确定要取消该订单吗？',
      success: async (res) => {
        if (!res.confirm) return
        try {
          await cancelOrder(order.value.id)
          uni.showToast({ title: '已取消', icon: 'none' })
          fetchDetail(order.value.id)
        } catch { /* handled by request interceptor */ }
      },
    })
  } else if (key === 'pay') {
    if (isPendingPayExpired(order.value)) {
      uni.showToast({ title: '订单已超时，请重新下单', icon: 'none' })
      fetchDetail(order.value.id)
      return
    }
    const payResult = await startPay(order.value.id)
    if (payResult) redirectToPayResult(payResult)
  } else if (key === 'confirm') {
    if (hasActiveAfterSale(order.value)) {
      uni.showToast({ title: '售后处理中，暂不能确认收货', icon: 'none' })
      return
    }
    uni.showModal({
      title: '提示',
      content: '确认已收到商品？',
      success: async (res) => {
        if (!res.confirm) return
        try {
          await confirmReceive(order.value.id)
          uni.showToast({ title: '已确认收货', icon: 'none' })
          fetchDetail(order.value.id)
        } catch { /* handled by request interceptor */ }
      },
    })
  } else if (key === 'extend') {
    uni.showToast({ title: '已延长收货', icon: 'none' })
  } else if (key === 'logistics') {
    uni.navigateTo({
      url: `/pages-sub/logistics/detail?order_id=${order.value.id}`,
    })
  } else if (key === 'review') {
    uni.navigateTo({
      url: `/pages-sub/review/post?order_id=${order.value.id}`,
    })
  } else if (key === 'refund') {
    if (!canApplyRefund(order.value)) {
      uni.showToast({
        title: hasActiveAfterSale(order.value) ? '剩余商品暂无可申请售后' : '订单已超过售后申请期限',
        icon: 'none',
      })
      return
    }
    const items = orderItems.value
    if (items.length === 0) {
      uni.showToast({ title: '请选择要申请售后的商品', icon: 'none' })
      return
    }
    openRefundSheet(items)
  }
}

function startCountdownTimer() {
  if (countdownTimer) return
  countdownTimer = setInterval(() => {
    nowTs.value = Date.now()
    if (isPendingPayExpired(order.value) && !expiredRefreshed) {
      expiredRefreshed = true
      fetchDetail(order.value.id)
    }
  }, 1000)
}

function stopCountdownTimer() {
  if (!countdownTimer) return
  clearInterval(countdownTimer)
  countdownTimer = null
}

function goBack() {
  const pages = getCurrentPages()
  if (pages.length > 1) {
    uni.navigateBack()
  } else {
    uni.switchTab({ url: '/pages/order/index' })
  }
}

onUnmounted(() => {
  stopCountdownTimer()
})
</script>

<style lang="scss" scoped>
.detail-page {
  min-height: 100vh;
  background-color: var(--color-bg-secondary, #faf8ff);
  padding: 0 $mb-spacing-page $mb-spacing-lg;
}

// ---- Loading ----
.detail-page__loading {
  padding-top: $mb-spacing-md;
}

// ---- Card base ----
.card {
  background: var(--color-bg, #ffffff);
  border-radius: 16rpx;
  padding: $mb-spacing-lg;
  margin-bottom: $mb-spacing-md;
  border: 1rpx solid rgba(25, 27, 35, 0.06);
}

// ---- Status section ----
.status-section {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-lg;
  margin: 0 (-$mb-spacing-page) $mb-spacing-md;
  padding: 34rpx $mb-spacing-page 42rpx;
  background: var(--color-primary, #0d50d5);
  color: var(--color-text-inverse, #ffffff);
}

.status-section__content {
  flex: 1;
  min-width: 0;
}

.status-section__title {
  display: block;
  font-size: 34rpx;
  font-weight: 700;
  color: var(--color-text-inverse, #ffffff);
  line-height: 1.2;
}

.status-section__desc {
  display: block;
  margin-top: 10rpx;
  font-size: $mb-font-sm;
  color: rgba(255, 255, 255, 0.82);
  line-height: 1.4;
}

.status-section__icon {
  flex-shrink: 0;
  width: 76rpx;
  height: 76rpx;
  border-radius: 18rpx;
  border: 3rpx solid rgba(255, 255, 255, 0.88);
  display: flex;
  align-items: center;
  justify-content: center;
}

.status-truck {
  position: relative;
  width: 46rpx;
  height: 32rpx;
}

.status-truck__body,
.status-truck__cab,
.status-truck__wheel {
  position: absolute;
  background: var(--color-text-inverse, #ffffff);
}

.status-truck__body {
  left: 0;
  bottom: 8rpx;
  width: 28rpx;
  height: 18rpx;
  border-radius: 4rpx;
}

.status-truck__cab {
  right: 0;
  bottom: 8rpx;
  width: 16rpx;
  height: 14rpx;
  border-radius: 2rpx 6rpx 4rpx 2rpx;
}

.status-truck__wheel {
  bottom: 0;
  width: 10rpx;
  height: 10rpx;
  border-radius: 50%;
  box-shadow: inset 0 0 0 3rpx rgba(13, 80, 213, 0.5);
}

.status-truck__wheel--rear { left: 6rpx; }
.status-truck__wheel--front { right: 6rpx; }

// ---- Logistics preview ----
.logistics-preview {
  display: flex;
  align-items: flex-start;
  gap: $mb-spacing-md;
  padding: $mb-spacing-md $mb-spacing-lg;
  margin-bottom: $mb-spacing-md;
  background: var(--color-bg, #ffffff);
  border-radius: 16rpx;
  border: 1rpx solid rgba(25, 27, 35, 0.06);
}

.logistics-preview__icon {
  flex-shrink: 0;
  width: 64rpx;
  height: 64rpx;
  border-radius: $mb-radius-md;
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.1));
  display: flex;
  align-items: center;
  justify-content: center;
}

// ---- Truck icon (pure CSS) ----
.truck-icon {
  position: relative;
  width: 32rpx;
  height: 24rpx;

  &__body, &__cab, &__wheel { position: absolute; background: var(--color-primary, #0d50d5); }
  &__body { left: 0; bottom: 6rpx; width: 20rpx; height: 14rpx; border-radius: 2rpx; }
  &__cab { right: 0; bottom: 6rpx; width: 12rpx; height: 10rpx; border-radius: 0 3rpx 3rpx 0; }
  &__wheel { bottom: 0; width: 8rpx; height: 8rpx; border-radius: 50%; box-shadow: inset 0 0 0 2rpx rgba(255, 255, 255, 0.8); }
  &__wheel--rear { left: 4rpx; }
  &__wheel--front { right: 4rpx; }
}

.logistics-preview__content { flex: 1; min-width: 0; }

.logistics-preview__text {
  font-size: $mb-font-md;
  color: var(--color-text, #191b23);
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.logistics-preview__time {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  margin-top: 8rpx;
}

// ---- After-sale card ----
.after-sale-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-md;
  padding: 18rpx $mb-spacing-lg;
  margin-bottom: $mb-spacing-md;
  background: var(--color-bg, #ffffff);
  border-radius: 16rpx;
  border: 1rpx solid rgba(13, 80, 213, 0.14);
}

.after-sale-card__left {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
}

.after-sale-card__icon {
  flex-shrink: 0;
  width: 56rpx;
  height: 56rpx;
  border-radius: 16rpx;
  background: rgba(13, 80, 213, 0.08);
  display: flex;
  align-items: center;
  justify-content: center;
}

.after-sale-card__icon-dot {
  width: 20rpx;
  height: 20rpx;
  border-radius: 50%;
  border: 6rpx solid rgba(13, 80, 213, 0.18);
  background: var(--color-primary, #0d50d5);
  box-sizing: content-box;
}

.after-sale-card__images {
  position: relative;
  flex-shrink: 0;
  width: 166rpx;
  height: 64rpx;
}

.after-sale-card__image-wrap {
  position: absolute;
  top: 0;
  width: 64rpx;
  height: 64rpx;
  border-radius: 14rpx;
  border: 3rpx solid var(--color-bg, #ffffff);
  overflow: hidden;
  background: #f3f5f9;
  box-sizing: border-box;
}

.after-sale-card__image {
  width: 100%;
  height: 100%;
  display: block;
}

.after-sale-card__image--placeholder {
  background: linear-gradient(135deg, rgba(13, 80, 213, 0.12), rgba(25, 27, 35, 0.06));
}

.after-sale-card__more {
  position: absolute;
  top: 0;
  left: 102rpx;
  z-index: 6;
  width: 64rpx;
  height: 64rpx;
  border-radius: 14rpx;
  border: 3rpx solid var(--color-bg, #ffffff);
  background: rgba(25, 27, 35, 0.72);
  display: flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
}

.after-sale-card__more-text {
  font-size: $mb-font-xs;
  font-weight: 700;
  color: var(--color-text-inverse, #ffffff);
}

.after-sale-card__content {
  flex: 1;
  min-width: 0;
}

.after-sale-card__title-row {
  display: flex;
  align-items: center;
  gap: 10rpx;
}

.after-sale-card__title {
  font-size: $mb-font-md;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.after-sale-card__type {
  padding: 4rpx 12rpx;
  border-radius: 999rpx;
  background: rgba(13, 80, 213, 0.08);
  font-size: $mb-font-xs;
  color: var(--color-primary, #0d50d5);
}

.after-sale-card__goods {
  display: block;
  margin-top: 6rpx;
  font-size: $mb-font-xs;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.after-sale-card__desc {
  display: block;
  margin-top: 4rpx;
  font-size: $mb-font-xs;
  color: var(--color-text-secondary, #434654);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.after-sale-card__arrow {
  flex-shrink: 0;
  font-size: $mb-font-sm;
  font-weight: 600;
  color: var(--color-primary, #0d50d5);
}

// ---- Address card ----
.address-card {
  display: flex;
  align-items: flex-start;
  gap: $mb-spacing-md;
  margin-bottom: $mb-spacing-md;
  padding: 20rpx $mb-spacing-lg;
}

.address-card__icon {
  flex-shrink: 0;
  width: 58rpx;
  height: 58rpx;
  border-radius: 14rpx;
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.08));
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 4rpx;
}

.pin {
  position: relative;
  width: 28rpx;
  height: 36rpx;
}

.pin__head {
  width: 28rpx;
  height: 28rpx;
  border-radius: 50% 50% 50% 0;
  transform: rotate(-45deg);
  background: var(--color-primary, #0d50d5);
  position: absolute;
  top: 0;
  left: 0;

  &::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 10rpx;
    height: 10rpx;
    border-radius: 50%;
    background: var(--color-bg, #ffffff);
  }
}

.pin__body {
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 4rpx;
  height: 10rpx;
  background: var(--color-primary, #0d50d5);
  border-radius: 0 0 2rpx 2rpx;
}

.address-card__info { flex: 1; min-width: 0; }

.address-card__top {
  display: flex;
  align-items: baseline;
  gap: $mb-spacing-sm;
  margin-bottom: 8rpx;
}

.address-card__name { font-size: $mb-font-lg; font-weight: 700; color: var(--color-text-title, #191b23); }
.address-card__phone { font-size: $mb-font-sm; color: var(--color-text-secondary, #434654); }

.address-card__detail {
  font-size: $mb-font-md;
  color: var(--color-text-secondary, #434654);
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

// ---- Goods card ----
.goods-card {
  padding: 14rpx $mb-spacing-lg;
}

.goods-item {
  display: flex;
  gap: 18rpx;
  padding: 14rpx 0 18rpx;

  &:first-child {
    padding-top: $mb-spacing-xs;
  }

  & + & {
    border-top: 1rpx solid var(--color-divider, #f0f2f5);
  }
}

.goods-item__img {
  flex-shrink: 0;
  width: 150rpx;
  height: 150rpx;
  border-radius: 12rpx;
  background: #f3f5f9;
}

.goods-item__img--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.goods-item__placeholder-box {
  width: 58rpx;
  height: 42rpx;
  border-radius: 8rpx;
  background: linear-gradient(135deg, rgba(13, 80, 213, 0.14), rgba(25, 27, 35, 0.06));
}

.goods-item__info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-width: 0;
  padding: 4rpx 0;
}

.goods-item__name {
  font-size: $mb-font-md;
  font-weight: 500;
  color: var(--color-text-title, #191b23);
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.goods-item__spec {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  background: #f5f7fb;
  border-radius: 8rpx;
  padding: 5rpx 12rpx;
  align-self: flex-start;
  margin-top: 8rpx;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.goods-item__bottom {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-top: auto;
}

.goods-item__qty { font-size: $mb-font-sm; color: var(--color-text-tertiary, #737686); }

.goods-item__after-sale {
  display: flex;
  align-items: center;
  gap: 10rpx;
  align-self: flex-start;
  margin-top: 10rpx;
  padding: 6rpx 12rpx;
  border-radius: 999rpx;
  background: rgba(13, 80, 213, 0.08);
}

.goods-item__after-sale-text {
  font-size: $mb-font-xs;
  font-weight: 700;
  color: var(--color-primary, #0d50d5);
}

.goods-item__after-sale-amount {
  font-size: $mb-font-xs;
  color: var(--color-text-secondary, #434654);
}

// ---- Remark card ----
.remark-card {
  display: flex;
  align-items: flex-start;
  gap: $mb-spacing-md;
  padding: $mb-spacing-md $mb-spacing-lg;
}

.remark-card__label {
  flex-shrink: 0;
  font-size: $mb-font-md;
  font-weight: 500;
  color: var(--color-text-title, #191b23);
}

.remark-card__value {
  flex: 1;
  font-size: $mb-font-md;
  color: var(--color-text-secondary, #434654);
  line-height: 1.5;
  text-align: right;
}

// ---- Summary card ----
.summary-card {
  padding: $mb-spacing-md $mb-spacing-lg;
}

.summary-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14rpx 0;
}

.summary-row__label { font-size: $mb-font-md; color: var(--color-text-secondary, #434654); }

.summary-row__discount {
  font-size: $mb-font-sm;
  color: #c2410c;
}

.summary-divider { height: 1rpx; background: var(--color-divider, #f0f2f5); margin: 8rpx 0; }

.summary-row--total {
  padding-top: 20rpx;

  .summary-row__label {
    font-size: $mb-font-lg;
    font-weight: 600;
    color: var(--color-text-title, #191b23);
  }
}

// ---- Order info card ----
.info-card {
  padding: $mb-spacing-md $mb-spacing-lg;
}

.info-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14rpx 0;
}

.info-row__label {
  font-size: $mb-font-md;
  color: var(--color-text-tertiary, #737686);
  flex-shrink: 0;
}

.info-row__value-wrap {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
  flex: 1;
  justify-content: flex-end;
  min-width: 0;
}

.info-row__value {
  font-size: $mb-font-md;
  color: var(--color-text, #191b23);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.info-row__copy {
  flex-shrink: 0;
  width: 40rpx;
  height: 40rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}

// ---- Clipboard icon (pure CSS) ----
.clipboard-icon {
  position: relative;
  width: 24rpx;
  height: 28rpx;

  &__board {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 20rpx;
    height: 24rpx;
    border: 2rpx solid var(--color-primary, #0d50d5);
    border-radius: 3rpx;
  }

  &__clip {
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 10rpx;
    height: 6rpx;
    border-radius: 2rpx 2rpx 0 0;
    background: var(--color-primary, #0d50d5);

    &::after {
      content: '';
      position: absolute;
      top: 4rpx;
      left: -3rpx;
      width: 16rpx;
      height: 2rpx;
      background: var(--color-primary, #0d50d5);
    }
  }
}

// ---- Bottom action bar ----
.action-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 100;
  background: var(--color-bg, #ffffff);
  border-top: 1rpx solid rgba(25, 27, 35, 0.08);
}

.action-bar__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-md;
  padding: $mb-spacing-sm $mb-spacing-page;
  padding-bottom: calc(#{$mb-spacing-sm} + env(safe-area-inset-bottom));
}

.action-bar__tools {
  display: flex;
  align-items: center;
  gap: $mb-spacing-lg;
  flex-shrink: 0;
}

.action-bar__tool {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4rpx;
  min-width: 64rpx;
}

.action-bar__icon-service {
  width: 44rpx;
  height: 44rpx;
  background-image: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0OCIgaGVpZ2h0PSI0OCIgdmlld0JveD0iMCAtOTYwIDk2MCA5NjAiPjxwYXRoIGZpbGw9IiM0MzQ2NTQiIGQ9Ik00ODItNDB2LTYwaDI5OHYtNTRINjMydi0yOTZoMTQ4di02OHEwLTEyNC04Ny0yMTMuNVQ0ODItODIxcS0xMjQgMC0yMTMgODkuNVQxODAtNTE4djY4aDE0OHYyOTZIMTgwcS0yNCAwLTQyLTE4dC0xOC00MnYtMzA0cTAtNzQuNzMgMjguNS0xNDAuODhRMTc3LTcyNS4wMyAyMjYtNzc0LjUxIDI3NS04MjQgMzQxLjItODUyLjVxNjYuMjEtMjguNSAxNDEtMjguNSA3NC44IDAgMTQwLjMgMjguNVE2ODgtODI0IDczNi4wNS03NzQuNTFxNDguMDUgNDkuNDggNzYgMTE1LjYzUTg0MC01OTIuNzMgODQwLTUxOHY0MThxMCAyNC0xOCA0MnQtNDIgMThINDgyWk0xODAtMjE0aDg4di0xNzZoLTg4djE3NlptNTEyIDBoODh2LTE3NmgtODh2MTc2Wk0xODAtMzkwaDg4LTg4Wm01MTIgMGg4OC04OFoiLz48L3N2Zz4=");
  background-size: 100% 100%;
  background-repeat: no-repeat;
}

.action-bar__tool-label {
  font-size: 20rpx;
  color: var(--color-text-secondary, #434654);
  line-height: 1;
}

.action-bar__actions {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $mb-spacing-md;
  min-width: 0;
}

.action-bar__button {
  min-width: 176rpx;
}

// ---- Bottom spacer ----
.bottom-spacer {
  height: calc(140rpx + env(safe-area-inset-bottom));
}
</style>
